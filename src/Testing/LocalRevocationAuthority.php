<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Testing;

use LSNepomuceno\Signet\Contracts\ProcessRunner;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Support\TemporaryFile;
use SensitiveParameter;

/**
 * The local timestamp authority, with revocation material to hand out.
 *
 * `LocalTimestampAuthority` answers false for OCSP and CRL, which is honest for
 * a self-signed certificate: it has no responder and no distribution point, so
 * a document signed with one carries no revocation material and the live tests
 * produce exactly the same. That leaves the code that gathers a Document
 * Security Store unexercised offline.
 *
 * This serves whatever it was given, at whatever URL it is asked for. **The
 * material is not evaluated here**, and that separation is deliberate: whether
 * a response really covers a certificate is
 * `Validation\RevocationChecker`'s question, with its own tests and its own
 * fixtures ([0024](../../docs/decisions/0024-revocation-is-evaluated-not-counted.md)).
 * What this makes testable is that the store is written, refreshed and covered
 * by the timestamp that follows it.
 *
 * Test-only, and kept out of the production classes exactly as its parent is.
 *
 * See docs/decisions/0022-the-archive-timestamp-is-a-chain.md.
 */
final class LocalRevocationAuthority implements SignatureTransport
{
    private readonly LocalTimestampAuthority $timestamps;

    /**
     * @param  ?string  $crl  DER, or null to answer as though there were none.
     * @param  ?string  $ocsp  DER, or null.
     */
    public function __construct(
        ProcessRunner $processes,
        private readonly ?string $crl = null,
        private readonly ?string $ocsp = null,
    ) {
        $this->timestamps = new LocalTimestampAuthority($processes);
    }

    /**
     * @return callable(string): string
     */
    public function timestamp(string $url, ?string $username = null, ?string $password = null): callable
    {
        return $this->timestamps->timestamp($url, $username, $password);
    }

    /**
     * @return callable(string, string): (string|false)
     */
    public function ocsp(): callable
    {
        $response = $this->ocsp;

        return static fn(string $url, string $request): string|false => $response ?? false;
    }

    /**
     * @return callable(string): (string|false)
     */
    public function crl(): callable
    {
        $list = $this->crl;

        return static fn(string $url): string|false => $list ?? false;
    }

    /**
     * A CRL signed by the authority that issued the certificate under test.
     *
     * **A list has to be real to be embedded.** The material a Document
     * Security Store carries is verified against the issuer before it goes in,
     * so bytes that are merely well-formed are discarded and the store comes
     * out empty (docs/decisions/0119-revocation-material-is-verified-before-it-is-embedded.md).
     * `DebugCertificate::makeRevocable()` returns the authority for exactly
     * this call.
     *
     * The list is empty, which is the answer that says "not revoked". Pass a
     * serial to get the other one.
     *
     * This is the one thing here that needs the `openssl` binary: ext-openssl
     * has no CRL writer, so the list is generated through
     * `Contracts\ProcessRunner` like the timestamps beside it (invariant 8).
     *
     * @param  string  $issuerPem  The issuing certificate.
     * @param  string  $issuerKeyPem  Its private key, unencrypted.
     * @param  string|null  $revokedSerial  Serial to list as revoked, in
     *          decimal, or null for a list that revokes nothing.
     * @return string The CRL in DER, which is what a distribution point serves.
     *
     * @throws ProcessRunTimeException
     */
    public static function crlFor(
        ProcessRunner $processes,
        string $issuerPem,
        #[SensitiveParameter]
        string $issuerKeyPem,
        ?string $revokedSerial = null,
    ): string {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'signet-crl-' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR;

        if (! is_dir($directory) && ! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new ProcessRunTimeException("could not create {$directory}");
        }

        file_put_contents($directory . 'ca.pem', $issuerPem);
        file_put_contents($directory . 'ca.key', $issuerKeyPem);
        file_put_contents($directory . 'crlnumber', "1000\n");

        // The database is what `openssl ca` reads to decide what the list
        // holds: one tab-separated line per certificate, and R in the first
        // column is the only thing that puts one on it.
        file_put_contents(
            $directory . 'index.txt',
            $revokedSerial === null
                ? ''
                : sprintf(
                    "R\t%s\t%s\t%s\tunknown\t/CN=Revoked\n",
                    date('ymdHis\Z', time() + 86400),
                    date('ymdHis\Z'),
                    strtoupper(dechex((int) $revokedSerial)),
                ),
        );

        file_put_contents($directory . 'ca.cnf', <<<CONFIG
            [ca]
            default_ca = local

            [local]
            database = {$directory}index.txt
            crlnumber = {$directory}crlnumber
            default_md = sha256
            default_crl_days = 3650
            CONFIG);

        return TemporaryFile::with($directory, '.pem', '', static function (TemporaryFile $list) use (
            $processes,
            $directory,
        ): string {
            $processes->run(sprintf(
                'openssl ca -config %s -gencrl -keyfile %s -cert %s -out %s 2>&1',
                escapeshellarg($directory . 'ca.cnf'),
                escapeshellarg($directory . 'ca.key'),
                escapeshellarg($directory . 'ca.pem'),
                escapeshellarg($list->path),
            ));

            // `openssl ca` writes PEM and takes no output format, so the
            // conversion is a second call rather than a flag.
            return TemporaryFile::with($directory, '.der', '', static function (TemporaryFile $der) use (
                $processes,
                $list,
            ): string {
                $processes->run(sprintf(
                    'openssl crl -in %s -outform DER -out %s 2>&1',
                    escapeshellarg($list->path),
                    escapeshellarg($der->path),
                ));

                $bytes = (string) file_get_contents($der->path);

                if ($bytes === '') {
                    throw new ProcessRunTimeException('the local authority produced no revocation list');
                }

                return $bytes;
            });
        });
    }
}
