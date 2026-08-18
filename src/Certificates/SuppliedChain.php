<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Certificates;

use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\Signet\Support\Pem;
use LSNepomuceno\Signet\Support\Probe;
use LSNepomuceno\Signet\Validation\ChainBuilder;

/**
 * Certificates the bundle did not carry, folded into the one that signs.
 *
 * The certificates that reach the CMS are whatever the bundle held, and **an
 * ICP-Brasil e-CPF exported from a browser or a token holds only the leaf**:
 * the intermediates are published by the AC and are not in the file. Everything
 * downstream then inherits that gap. `pades-b-lt` and `pades-b-lta` build their
 * Document Security Store from what the signature carries, so the store is
 * incomplete and long-term validation is not long-term; revocation cannot be
 * checked for a certificate whose issuer is absent; and validation correctly
 * reports `ChainDoesNotReachRoot` for a signature that would be fine if the
 * intermediates were there.
 *
 * A caller signing from PEM could already concatenate the intermediates into
 * the file by hand and it happened to work, because `Support\Pem::certificates()`
 * reads all of them. That was an accident rather than an API, it was
 * undocumented, and there was no equivalent for PKCS#12 at all.
 *
 * **The result is ordered, not appended in arrival order.** The Document
 * Security Store's collector treats each certificate's neighbour as its issuer,
 * so a pile in the wrong order asks a responder about the wrong pair and embeds
 * the answer. `Validation\ChainBuilder` decides the order, from the signatures
 * rather than from what the caller happened to type first.
 */
final readonly class SuppliedChain
{
    public function __construct(private ChainBuilder $chains = new ChainBuilder()) {}

    /**
     * The certificate with the supplied chain folded in, or the same one back
     * when there is nothing to add.
     *
     * @param  list<string>  $supplied  PEM or DER bytes. One certificate per
     *                                  blob, or a concatenated bundle: what
     *                                  arrives is whatever an AC publishes, and
     *                                  it is both.
     *
     * @throws InvalidCertificateContentException When a blob holds no
     *          certificate, or holds one that is not part of this signer's
     *          chain.
     */
    public function into(Certificate $certificate, array $supplied): Certificate
    {
        if ($supplied === []) {
            return $certificate;
        }

        $existing = Pem::certificates($certificate->original);
        $extra = $this->unseen($existing, $this->read($supplied));

        if ($extra === []) {
            return $certificate;
        }

        $ordered = $this->chains->build([...$existing, ...$extra]);

        $this->guardUnrelated($extra, $ordered);

        return new Certificate(
            original: $this->rebuild($certificate->original, $ordered),
            openssl: $certificate->openssl,
            data: $certificate->data,
            password: $certificate->password,
        );
    }

    /**
     * Every certificate in every blob, as PEM.
     *
     * Gated on content rather than on an extension or a flag, the same way the
     * certificate readers decide between PEM and PKCS#12
     * (docs/decisions/0007-pem-second-entry-one-pipeline.md): a blob carrying
     * the armour is read as PEM, and anything else is offered to the parser as
     * DER.
     *
     * @param  list<string>  $supplied
     * @return list<string>
     *
     * @throws InvalidCertificateContentException
     */
    private function read(array $supplied): array
    {
        $certificates = [];

        foreach ($supplied as $blob) {
            $found = Pem::hasCertificate($blob) ? Pem::certificates($blob) : [Pem::fromDer($blob)];

            foreach ($found as $pem) {
                // Through Probe, because a blob that is not a certificate is
                // an answer here rather than a fault, and openssl warns on the
                // way to saying so (docs/spec/quality-policy.md).
                if (Probe::run(static fn(): mixed => openssl_x509_read($pem)) === false) {
                    throw new InvalidCertificateContentException(
                        'a certificate supplied for the chain could not be read as PEM or DER.',
                    );
                }

                $certificates[] = $pem;
            }
        }

        return $certificates;
    }

    /**
     * The supplied certificates the bundle did not already carry.
     *
     * By the digest of the DER rather than by the PEM text: the same
     * certificate armoured with different line endings is the same certificate,
     * and embedding it twice inflates the CMS and the store while saying
     * nothing.
     *
     * @param  list<string>  $existing
     * @param  list<string>  $supplied
     * @return list<string>
     */
    private function unseen(array $existing, array $supplied): array
    {
        $seen = [];

        foreach ($existing as $pem) {
            $seen[self::fingerprint($pem)] = true;
        }

        $unseen = [];

        foreach ($supplied as $pem) {
            $fingerprint = self::fingerprint($pem);

            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $unseen[] = $pem;
        }

        return $unseen;
    }

    /**
     * Refuses a certificate that is not part of this signer's chain.
     *
     * Raising beats embedding. A certificate nobody in the chain was issued by
     * inflates the CMS and every Document Security Store built from it, and it
     * says nothing about the signer, so the caller has almost certainly named
     * the wrong file: the AC of another certificate they also hold, or an
     * intermediate whose own issuer they forgot.
     *
     * @param  list<string>  $supplied
     * @param  list<string>  $ordered
     *
     * @throws InvalidCertificateContentException
     */
    private function guardUnrelated(array $supplied, array $ordered): void
    {
        $inChain = [];

        foreach ($ordered as $pem) {
            $inChain[self::fingerprint($pem)] = true;
        }

        foreach ($supplied as $pem) {
            if (! isset($inChain[self::fingerprint($pem)])) {
                throw new InvalidCertificateContentException(sprintf(
                    'the supplied certificate "%s" issued nothing in this signer\'s chain, so it would be embedded '
                        . 'without saying anything about the signer. Supply the intermediates between them, or leave it out.',
                    self::subject($pem),
                ));
            }
        }
    }

    /**
     * The bundle with its certificates replaced by the ordered chain.
     *
     * The private key and anything else the bundle carries survive byte for
     * byte: only the certificate blocks are rewritten, and they are rewritten
     * rather than appended to because their order is what the store's collector
     * reads as "issuer of the one before".
     *
     * @param  list<string>  $ordered
     */
    private function rebuild(string $original, array $ordered): string
    {
        $withoutCertificates = preg_replace(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----\s*/s',
            '',
            $original,
        );

        return rtrim($withoutCertificates ?? $original) . "\n" . implode("\n", $ordered) . "\n";
    }

    /**
     * A certificate's identity for comparison: the digest of its DER.
     */
    private static function fingerprint(string $pem): string
    {
        return hash('sha256', Pem::toDer($pem) ?? $pem);
    }

    /**
     * The subject common name, for a message that names the file the caller got
     * wrong rather than making them work it out.
     */
    private static function subject(string $pem): string
    {
        $parsed = openssl_x509_parse($pem, false);
        $subject = is_array($parsed) && is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $name = $subject['commonName'] ?? $subject['organizationName'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'unnamed certificate';
    }
}
