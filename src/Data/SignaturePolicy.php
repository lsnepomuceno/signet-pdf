<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Data;

/**
 * The signature policy a signer declared they were signing under.
 *
 * `signature-policy-identifier`, RFC 5126 §5.8.1. A Brazilian verifier looks
 * for this before calling a signature ICP-Brasil conformant, so a signature
 * carrying none is cryptographically fine and reported as conformant to
 * nothing by the tools an institution actually uses (issue #56).
 *
 * **This is what the document says, not a verdict.** Nothing here checks that
 * the policy was satisfied, or even that the OID names a policy that exists:
 * doing either means holding the policy artefacts, read from the authority
 * that published them, which is a separate piece of work. Reporting the
 * declaration is useful on its own, because today an application cannot see it
 * at all.
 */
final readonly class SignaturePolicy extends BaseData
{
    /**
     * @param  string  $oid  The policy identifier, in dotted form.
     * @param  string  $digestAlgorithm  What the policy document was hashed
     *          with, by name.
     * @param  string  $digest  The digest of the policy document, lowercase
     *          hex, as the signer computed it.
     * @param  string|null  $uri  Where the policy document can be fetched, from
     *          the `sp-uri` qualifier. Optional, and nothing here fetches it:
     *          the network stays behind the injected transport (invariant 9).
     */
    public function __construct(
        public string $oid,
        public string $digestAlgorithm,
        public string $digest,
        public ?string $uri = null,
    ) {}
}
