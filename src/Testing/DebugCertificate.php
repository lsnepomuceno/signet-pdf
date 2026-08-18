<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Testing;

use LSNepomuceno\Signet\Exceptions\CertificateOutputNotFoundException;
use LSNepomuceno\Signet\IcpBrasil\Enums\CertificateType;
use LSNepomuceno\Signet\IcpBrasil\Enums\OtherName;
use LSNepomuceno\Signet\Support\TemporaryFile;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

/**
 * Generates throwaway PKCS#12 bundles for tests.
 *
 * This lived on ManageCert in v1, which meant production code shipped a
 * certificate generator (§1.6). It is also fully native now: v1 shelled out to
 * `openssl req` and `openssl pkcs12 -export`, so running the test suite
 * required the binary on PATH.
 */
final class DebugCertificate
{
    public const string PASSWORD = "example's password with special chars: $ & * ? \" '";

    /**
     * @return array{0: string, 1: string} The PFX bytes and its password.
     *
     * @throws CertificateOutputNotFoundException
     */
    public static function make(int $daysValid = 600): array
    {
        [$key, $x509] = self::generate($daysValid);

        $pfx = '';

        if (! openssl_pkcs12_export($x509, $pfx, $key, self::PASSWORD)) {
            throw new CertificateOutputNotFoundException();
        }

        /** @var string $pfx */
        return [$pfx, self::PASSWORD];
    }

    /**
     * A bundle whose key is on an elliptic curve rather than RSA.
     *
     * Nothing in this package says it signs with RSA, and until this existed
     * nothing proved it signed with anything else: every fixture in the suite
     * was `OPENSSL_KEYTYPE_RSA`, so "does it sign with an ECDSA certificate"
     * could only be answered with "probably, nobody has looked". That is the
     * wrong shape of answer for a signing library in either direction.
     *
     * @param  string  $curve  An openssl curve name. `prime256v1` (P-256) and
     *                         `secp384r1` (P-384) are the ones the suite gates.
     * @return array{0: string, 1: string} The PFX bytes and its password.
     *
     * @throws CertificateOutputNotFoundException
     */
    public static function makeEc(string $curve = 'prime256v1', int $daysValid = 600): array
    {
        [$key, $x509] = self::generate($daysValid, $curve);

        $pfx = '';

        if (! openssl_pkcs12_export($x509, $pfx, $key, self::PASSWORD)) {
            throw new CertificateOutputNotFoundException();
        }

        /** @var string $pfx */
        return [$pfx, self::PASSWORD];
    }

    /**
     * The same certificate as PEM, with the key kept separate.
     *
     * $encryptKey mirrors what a real .pem carries: a passphrase-protected key
     * is the common case, an unencrypted one is legal and frequent. The two
     * behave differently under openssl_x509_check_private_key(), so both are
     * fixtures rather than one (docs/decisions/0007-pem-second-entry-one-pipeline.md).
     *
     * @param  ?string  $curve  An openssl curve name, or null for RSA.
     *                          `Certificates\PemCertificateReader` recognises an
     *                          `EC PRIVATE KEY` block, and this is what proves
     *                          it rather than the header being present in the
     *                          match list.
     * @return array{0: string, 1: string, 2: string} Certificate PEM, private key PEM, and the
     *                                                key's password, empty when it is unencrypted.
     */
    public static function makePem(bool $encryptKey = true, int $daysValid = 600, ?string $curve = null): array
    {
        [$key, $x509] = self::generate($daysValid, $curve);

        $certificate = '';

        if (! openssl_x509_export($x509, $certificate)) {
            throw new RuntimeException('Unable to export the test certificate: ' . openssl_error_string());
        }

        $privateKey = '';
        $password = $encryptKey ? self::PASSWORD : '';

        if (! openssl_pkey_export($key, $privateKey, $encryptKey ? $password : null)) {
            throw new RuntimeException('Unable to export the test private key: ' . openssl_error_string());
        }

        /** @var string $certificate */
        /** @var string $privateKey */
        return [$certificate, $privateKey, $password];
    }

    /**
     * A root authority and a signing certificate it issued.
     *
     * The plain make() certificate is self-signed and, like any certificate
     * openssl_csr_sign produces by default, carries basicConstraints CA:FALSE.
     * A strict verifier will not accept it as its own trust anchor, and it is
     * right not to: measured on 2026-08-10, openssl_x509_checkpurpose() refuses
     * it even with the certificate itself supplied as the root. Testing trust
     * against that shape would test the fixture rather than the chain, so this
     * builds the shape a real certificate has
     * (docs/decisions/0016-trust-is-the-applications-policy.md).
     *
     * @param  bool  $embedRoot  Whether the root travels inside the bundle.
     *          **False is the shape of a real ICP-Brasil e-CPF**: exported from
     *          a browser or a token it holds the leaf and nothing else, and the
     *          intermediates are published by the AC rather than handed over
     *          with the key. That is the fixture `PendingSignature::chain()`
     *          exists for.
     * @return array{0: string, 1: string, 2: string} The PFX bytes, its password,
     *                                                and the root authority in PEM.
     *
     * @throws CertificateOutputNotFoundException
     */
    public static function makeChain(int $daysValid = 600, bool $embedRoot = true): array
    {
        $rootKey = self::key();
        $rootCsr = self::request(['commonName' => 'Test Root Authority'], $rootKey);

        // v3_ca is what sets basicConstraints CA:TRUE, which is what makes a
        // certificate usable as an anchor at all.
        //
        // **The serials are distinct, and that is load-bearing.** They default
        // to 0, so the root and the certificate it issues came out carrying the
        // same issuer name and the same serial, and a CMS identifies its signer
        // by exactly that pair (RFC 5652 §5.3). pyHanko then resolved the
        // SignerInfo to the root, found the ESS signing-certificate-v2 attribute
        // describing the leaf, and refused the signature outright. No authority
        // issues two certificates that way, so the fixture was the defect.
        $root = openssl_csr_sign($rootCsr, null, $rootKey, $daysValid + 365, [
            'digest_alg' => 'sha256',
            'x509_extensions' => 'v3_ca',
        ], serial: 1);

        if ($root === false) {
            throw new RuntimeException('Unable to build the test root: ' . openssl_error_string());
        }

        $key = self::key();

        // **The leaf declares what it is for**, which a certificate signed with
        // no extensions section does not. pyHanko applies a key usage policy
        // when it validates a document signature and refuses to build a path to
        // a certificate that claims neither digitalSignature nor
        // nonRepudiation, so without this the chain could not be checked by the
        // one instrument that checks chains (RFC 5280 §4.2.1.3). The key
        // identifiers are here for the same reason: they are how a path is
        // built from the leaf to its issuer.
        $x509 = self::signWithExtensions(
            implode("\n", [
                '[req]',
                'distinguished_name = dn',
                '[dn]',
                '[leaf]',
                'basicConstraints = CA:FALSE',
                'keyUsage = critical, digitalSignature, nonRepudiation',
                'subjectKeyIdentifier = hash',
                'authorityKeyIdentifier = keyid,issuer',
                '',
            ]),
            'leaf',
            ['commonName' => 'Test Certificate', 'organizationalUnitName' => 'LucasNepomuceno'],
            $key,
            $daysValid,
            $root,
            $rootKey,
            2,
        );

        $pfx = '';

        // The root travels in the bundle, which is what lets the signature
        // carry its own chain and what a real PFX from an authority does.
        $options = $embedRoot ? ['extracerts' => [$root]] : [];

        if (! openssl_pkcs12_export($x509, $pfx, $key, self::PASSWORD, $options)) {
            throw new CertificateOutputNotFoundException();
        }

        $rootPem = '';

        if (! openssl_x509_export($root, $rootPem)) {
            throw new RuntimeException('Unable to export the test root: ' . openssl_error_string());
        }

        /** @var string $pfx */
        /** @var string $rootPem */
        return [$pfx, self::PASSWORD, $rootPem];
    }

    /**
     * A certificate whose key is too small to be worth anything.
     *
     * 1024-bit RSA was ordinary when many documents still in retention were
     * signed, and it is below every current recommendation
     * (`Support\CryptographicStrength`). A signature made with one **verifies**,
     * which is the whole point of reporting it as a finding rather than as a
     * verdict, and there is no other way to get a document into that state for
     * a test: the package will not produce a weak signature deliberately, so
     * the weakness has to come from the certificate.
     *
     * @return array{0: string, 1: string} The PFX bytes and its password.
     *
     * @throws CertificateOutputNotFoundException
     */
    public static function makeWithKeySize(int $bits, int $daysValid = 600): array
    {
        $key = self::key($bits);

        $csr = self::request(
            ['commonName' => 'Test Certificate', 'organizationalUnitName' => 'LucasNepomuceno'],
            $key,
        );

        $x509 = openssl_csr_sign($csr, null, $key, $daysValid, ['digest_alg' => 'sha256']);

        if ($x509 === false) {
            throw new RuntimeException('Unable to self-sign the test certificate: ' . openssl_error_string());
        }

        $pfx = '';

        if (! openssl_pkcs12_export($x509, $pfx, $key, self::PASSWORD)) {
            throw new CertificateOutputNotFoundException();
        }

        /** @var string $pfx */
        return [$pfx, self::PASSWORD];
    }

    /**
     * A certificate issued for something other than signing documents.
     *
     * The case worth catching is a TLS server certificate, which signs a PDF
     * perfectly well and was never meant to: nothing in the cryptography
     * objects, and the certificate's own `extendedKeyUsage` says so outright.
     *
     * @param  string  $purpose  As openssl's configuration names it:
     *                           `serverAuth`, `clientAuth`, `emailProtection`.
     * @return array{0: string, 1: string} The PFX bytes and its password.
     *
     * @throws CertificateOutputNotFoundException
     */
    public static function makeForPurpose(string $purpose = 'serverAuth', int $daysValid = 600): array
    {
        $key = self::key();

        $configuration = implode("\n", [
            '[req]',
            'distinguished_name = dn',
            '[dn]',
            '[purpose]',
            'basicConstraints = CA:FALSE',
            'keyUsage = critical, digitalSignature, keyEncipherment',
            "extendedKeyUsage = {$purpose}",
            '',
        ]);

        $x509 = self::signWithExtensions(
            $configuration,
            'purpose',
            ['commonName' => 'Test Certificate', 'organizationalUnitName' => 'LucasNepomuceno'],
            $key,
            $daysValid,
        );

        $pfx = '';

        if (! openssl_pkcs12_export($x509, $pfx, $key, self::PASSWORD)) {
            throw new CertificateOutputNotFoundException();
        }

        /** @var string $pfx */
        return [$pfx, self::PASSWORD];
    }

    /**
     * A certificate shaped like an ICP-Brasil one, for reading the fields back.
     *
     * **It is self-signed, and that is the point of saying so here.** It
     * carries the `otherName` fields the Receita Federal's layout fixes, so a
     * parser can be tested against something with the right shape, and it
     * chains to nothing: no trust store will accept it, and none should
     * (docs/decisions/0029-the-identity-a-brazilian-signer-is-known-by.md).
     *
     * @param  array<string, string>  $otherNames  OID to written value,
     *                                             replacing the defaults. Pass a
     *                                             malformed one to exercise a
     *                                             finding.
     * @return array{0: string, 1: string} The PFX bytes and its password.
     *
     * @throws CertificateOutputNotFoundException
     */
    public static function icpBrasil(
        CertificateType $type = CertificateType::Individual,
        array $otherNames = [],
        string $commonName = 'JOAO DA SILVA:11144477735',
        int $daysValid = 600,
    ): array {
        // An empty override leaves the field out entirely, which is how a test
        // expresses "this certificate does not carry it". An empty otherName is
        // a different thing, and not the one anybody wants to check.
        $fields = array_filter([...self::icpBrasilFields($type), ...$otherNames], static fn(string $value): bool => $value !== '');

        $key = self::key();

        $x509 = self::signWithExtensions(
            self::openSslConfiguration($fields),
            'icp',
            ['commonName' => $commonName, 'countryName' => 'BR'],
            $key,
            $daysValid,
        );

        $pfx = '';

        if (! openssl_pkcs12_export($x509, $pfx, $key, self::PASSWORD)) {
            throw new CertificateOutputNotFoundException();
        }

        /** @var string $pfx */
        return [$pfx, self::PASSWORD];
    }

    /**
     * A certificate that names where its revocation material lives.
     *
     * `Signer::collectValidationMaterial()` reads the endpoints out of the
     * certificate, so a certificate carrying none is never asked about, whatever
     * transport is bound. Without this, the code that gathers a Document
     * Security Store could only be exercised against a real authority
     * (docs/decisions/0022-the-archive-timestamp-is-a-chain.md).
     *
     * The URLs are unroutable on purpose. A substituted transport answers them,
     * and anything that reached the network would be a test lying about what it
     * checks.
     *
     * @return array{0: string, 1: string} The PFX bytes and its password.
     *
     * @throws CertificateOutputNotFoundException
     */
    public static function makeRevocable(
        string $crlUrl = 'http://crl.invalid/test.crl',
        string $ocspUrl = 'http://ocsp.invalid',
        int $daysValid = 600,
    ): array {
        $key = self::key();

        $configuration = implode("\n", [
            '[req]',
            'distinguished_name = dn',
            '[dn]',
            '[leaf]',
            'basicConstraints = CA:FALSE',
            'keyUsage = critical, digitalSignature, nonRepudiation, keyEncipherment',
            "crlDistributionPoints = URI:{$crlUrl}",
            "authorityInfoAccess = OCSP;URI:{$ocspUrl}",
            '',
        ]);

        $x509 = self::signWithExtensions(
            $configuration,
            'leaf',
            ['commonName' => 'Revocable Test Certificate'],
            $key,
            $daysValid,
        );

        $pfx = '';

        if (! openssl_pkcs12_export($x509, $pfx, $key, self::PASSWORD)) {
            throw new CertificateOutputNotFoundException();
        }

        /** @var string $pfx */
        return [$pfx, self::PASSWORD];
    }

    /**
     * Issues a self-signed certificate whose extensions come from a written
     * configuration, which is the only way openssl accepts an `otherName` or a
     * distribution point.
     *
     * @param  array<string, string>  $subject
     */
    private static function signWithExtensions(
        string $configuration,
        string $section,
        array $subject,
        OpenSSLAsymmetricKey $key,
        int $daysValid,
        ?OpenSSLCertificate $issuer = null,
        ?OpenSSLAsymmetricKey $issuerKey = null,
        int $serial = 0,
    ): OpenSSLCertificate {
        return TemporaryFile::with(
            sys_get_temp_dir(),
            '.cnf',
            $configuration,
            static function (TemporaryFile $file) use (
                $section,
                $subject,
                $key,
                $daysValid,
                $issuer,
                $issuerKey,
                $serial,
            ): OpenSSLCertificate {
                $options = ['digest_alg' => 'sha256', 'config' => $file->path, 'x509_extensions' => $section];

                // openssl_csr_new takes the key by reference, so it is handed a
                // copy: the analyser widens anything that function touches to
                // mixed, and the signing call below needs the type intact.
                $request = $key;
                $csr = openssl_csr_new($subject, $request, $options);

                if (! $csr instanceof OpenSSLCertificateSigningRequest) {
                    throw new RuntimeException('Unable to generate the test CSR: ' . openssl_error_string());
                }

                $signed = openssl_csr_sign($csr, $issuer, $issuerKey ?? $key, $daysValid, $options, $serial);

                if ($signed === false) {
                    throw new RuntimeException('Unable to sign the test certificate: ' . openssl_error_string());
                }

                return $signed;
            },
        );
    }

    /**
     * The fields each profile is required to carry, filled with values that
     * satisfy the check digits.
     *
     * @return array<string, string>
     */
    private static function icpBrasilFields(CertificateType $type): array
    {
        // 8 birth + 11 CPF + 11 NIS + 15 RG + 6 issuer, and "unavailable" is
        // written as zeros rather than left out.
        $holder = '11081985' . '11144477735' . '12345678901' . '000000012345678' . 'SSPSP';

        if ($type === CertificateType::LegalEntity) {
            return [
                OtherName::ResponsibleName->value => 'JOAO DA SILVA',
                OtherName::CompanyRegistry->value => '11222333000181',
                OtherName::ResponsibleData->value => $holder,
                OtherName::CompanySocialSecurity->value => '000000000000',
            ];
        }

        return [
            OtherName::HolderData->value => $holder,
            // 12 registration + 3 zone + 4 section + 22 municipality.
            OtherName::VoterRegistration->value => '465555610469' . '001' . '0477' . 'SAOPAULOSP',
            OtherName::HolderSocialSecurity->value => '000000000000',
        ];
    }

    /**
     * An openssl configuration carrying the fields as `otherName` entries.
     *
     * OCTET STRING because the specification says so, in as many words: "the
     * information in each OtherName field shall be stored as an ASN.1 OCTET
     * STRING character string". Real certificates also use UTF8String, and the
     * reader accepts both, so this generates the one the rule names.
     *
     * @param  array<string, string>  $fields
     */
    private static function openSslConfiguration(array $fields): string
    {
        $entries = [];
        $index = 0;

        foreach ($fields as $oid => $value) {
            $index++;
            $entries[] = "otherName.{$index} = {$oid};FORMAT:ASCII,OCTETSTRING:{$value}";
        }

        return implode("\n", [
            '[req]',
            'distinguished_name = dn',
            '[dn]',
            '[icp]',
            'subjectAltName = @alt',
            'basicConstraints = CA:FALSE',
            'keyUsage = critical, digitalSignature, nonRepudiation, keyEncipherment',
            '[alt]',
            ...$entries,
            'email = signer@example.test',
            '',
        ]);
    }

    /**
     * A fresh self-signed certificate and the key that signed it.
     *
     * @param  ?string  $curve  An elliptic curve by its openssl name, or null
     *                          for RSA. RSA is the default so that no existing
     *                          fixture changes shape.
     * @return array{0: OpenSSLAsymmetricKey, 1: OpenSSLCertificate}
     */
    private static function generate(int $daysValid, ?string $curve = null): array
    {
        $key = $curve === null ? self::key() : self::ellipticKey($curve);

        $csr = self::request(
            ['commonName' => 'Test Certificate', 'organizationalUnitName' => 'LucasNepomuceno'],
            $key,
        );

        $x509 = openssl_csr_sign($csr, null, $key, $daysValid, ['digest_alg' => 'sha256']);

        if ($x509 === false) {
            throw new RuntimeException('Unable to self-sign the test certificate: ' . openssl_error_string());
        }

        return [$key, $x509];
    }

    private static function key(int $bits = 2048): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Unable to generate a test key: ' . openssl_error_string());
        }

        return $key;
    }

    /**
     * A key on a named curve.
     *
     * `prime256v1` and `secp384r1` are the two worth having: P-256 is what a
     * European qualified certificate and a newer ICP-Brasil one are issued on,
     * and P-384 is the next step up, which is also the pair the digest matrix
     * is exercised over.
     */
    private static function ellipticKey(string $curve): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => $curve,
        ]);

        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException("Unable to generate a test key on {$curve}: " . openssl_error_string());
        }

        return $key;
    }

    /**
     * @param  array<string, string>  $subject
     */
    private static function request(array $subject, OpenSSLAsymmetricKey $key): OpenSSLCertificateSigningRequest
    {
        $csr = openssl_csr_new($subject, $key, ['digest_alg' => 'sha256']);

        if ($csr === false) {
            throw new RuntimeException('Unable to generate a test CSR: ' . openssl_error_string());
        }

        if ($csr === true) {
            throw new RuntimeException('openssl_csr_new returned no signing request');
        }

        return $csr;
    }
}
