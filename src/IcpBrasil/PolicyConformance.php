<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\IcpBrasil;

use LSNepomuceno\Signet\Data\SignatureDetails;
use LSNepomuceno\Signet\Data\SignatureReport;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\IcpBrasil\Data\PolicyReport;
use LSNepomuceno\Signet\IcpBrasil\Enums\PolicyFinding;
use LSNepomuceno\Signet\IcpBrasil\Enums\SignaturePolicy;

/**
 * Reads what a signature declared it was made under, and says whether it kept
 * to it.
 *
 * `Validation\PdfSignatureValidator` reports the declaration and judges none of
 * it, deliberately: which policies exist is Brazilian, and the core knows
 * nothing regional
 * (docs/decisions/0104-the-regional-layer-is-its-own-namespace.md). This is the
 * half that knows, and like everything else in this layer it is **structural
 * and offline**: it compares the declaration against the policies that ship
 * with the package, identifier and window from ITI's published list and digest
 * from the policy document itself, and against what the signature carries.
 *
 * **`isValid()` consults none of it.** A signature that declares a policy it
 * does not satisfy is still cryptographically valid, and saying otherwise would
 * be this layer deciding what "valid" means for everybody
 * (docs/decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md).
 */
final readonly class PolicyConformance
{
    /**
     * What the signature declared, and what is wrong with it.
     *
     * The report conforms when the declaration is on the published list, was in
     * force when the document was signed, carries the digest **the policy
     * document** carries, and is matched by what the signature holds.
     *
     * The list records a different hash for the same policy, over the file
     * rather than over the policy's contents, and declaring that one is what
     * ITI's Verificador rejects
     * (docs/decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md).
     */
    public function check(SignatureReport $report, SignatureDetails $signature): PolicyReport
    {
        $declared = $signature->signaturePolicy;

        if ($declared === null) {
            return new PolicyReport(findings: [
                ['finding' => PolicyFinding::NoPolicyDeclared, 'detail' => null],
            ]);
        }

        $policy = SignaturePolicy::tryFrom($declared->oid);

        // Nothing else is knowable about a policy that is not on the list, so
        // the finding stands alone rather than beside guesses.
        if ($policy === null) {
            return new PolicyReport(findings: [
                ['finding' => PolicyFinding::UnknownPolicy, 'detail' => $declared->oid],
            ]);
        }

        $findings = [];

        // Case-insensitively, because the hex is written by whoever signed and
        // the comparison is of bytes rather than of spelling.
        if (strcasecmp($declared->digest, $policy->digest()) !== 0) {
            $findings[] = ['finding' => PolicyFinding::PolicyDigestDisagrees, 'detail' => $declared->digest];
        }

        // Against the signing time when the document states one. A signature
        // with none is not held to a window it cannot be placed in.
        $signedAt = $signature->signedAt;

        if ($signedAt !== null && ($signedAt < $policy->validFrom() || $signedAt > $policy->validUntil())) {
            $findings[] = [
                'finding' => PolicyFinding::PolicyNotInForce,
                'detail' => 'signed at ' . gmdate('Y-m-d', $signedAt),
            ];
        }

        if (! $this->satisfies($report, $signature, $policy->profile())) {
            $findings[] = [
                'finding' => PolicyFinding::SignatureBelowPolicy,
                'detail' => 'the policy is satisfied by ' . $policy->profile()->value,
            ];
        }

        return new PolicyReport($policy, $findings);
    }

    /**
     * Whether the signature carries what the policy's profile requires.
     *
     * Each level adds to the one below it, so the checks accumulate: a time
     * reference needs a signature timestamp, complete references need the
     * validation material as well, and the archival level needs a document
     * timestamp over the lot.
     */
    private function satisfies(
        SignatureReport $report,
        SignatureDetails $signature,
        SignatureProfile $required,
    ): bool {
        $timestamped = $signature->stampedAt !== null;
        $material = $report->hasLongTermMaterial();
        $archived = $report->timestamps() !== [];

        return match ($required) {
            SignatureProfile::PadesBB, SignatureProfile::Legacy => true,
            SignatureProfile::PadesBT => $timestamped,
            SignatureProfile::PadesBLT => $timestamped && $material,
            SignatureProfile::PadesBLTA => $timestamped && $material && $archived,
        };
    }
}
