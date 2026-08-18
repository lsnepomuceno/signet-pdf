<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Contracts;

/**
 * Decides whether a detached CMS matches the bytes it covers.
 *
 * **A seam, for the reason every other one here is.** The implementation that
 * ships by default runs `openssl` as a process, and that is a deliberate
 * choice: walking the CMS grammar by hand to check the signed attributes is
 * exactly the kind of code whose bugs produce a false "valid", and for a
 * security decision deferring to OpenSSL's own implementation is conservative
 * ([0001](../../docs/decisions/0001-openssl-native-with-cli-fallback.md)).
 *
 * The consequence is what this interface exists to remove. On a host where
 * `proc_open` is disabled, the package signs perfectly well, through
 * ext-openssl, and cannot validate at all. Reporting that honestly, which
 * `signet check` does, is not the same as not having it. So the choice is the
 * application's: `Validation\NativeSignatureVerifier` needs no process, and it
 * is selected rather than defaulted to
 * ([0114](../../docs/decisions/0114-verification-has-two-implementations.md)).
 *
 * Three questions rather than one, because a timestamp is not a detached
 * signature: its content is inside the token, and the answer worth having is
 * what the authority asserted rather than merely that it did.
 */
interface SignatureVerifier
{
    /**
     * Whether this CMS is a signature over exactly these bytes.
     *
     * **Not whether the signer is trusted**, which is the application's policy
     * and `Validation\TrustStore`'s question
     * ([0016](../../docs/decisions/0016-trust-is-the-applications-policy.md)).
     *
     * @throws \LSNepomuceno\Signet\Exceptions\ProcessUnavailableException When
     *          the environment cannot answer at all, which is a different thing
     *          from a signature that does not verify and must not be reported
     *          as one.
     * @throws \LSNepomuceno\Signet\Exceptions\MissingBinaryException
     */
    public function verify(string $cms, string $coveredBytes): bool;

    /**
     * Whether this RFC 3161 token really stamps these bytes.
     *
     * Two things have to hold, and checking only the first is worse than
     * checking neither, because a token lifted from another document passes it:
     * the token's own CMS verifies, and the TSTInfo it signs carries the digest
     * of the range the token covers.
     */
    public function verifyTimestamp(string $token, string $coveredBytes): bool;

    /**
     * The TSTInfo of a token that verifies and really stamps these bytes, as
     * DER, or null.
     *
     * Strictly more than `verifyTimestamp()` for the same work: `genTime` lives
     * in here, and it is the only time in a signed document attributable to
     * anyone other than the signer.
     */
    public function verifiedTimestampInfo(string $token, string $stampedBytes): ?string;
}
