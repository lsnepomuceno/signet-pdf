<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\IcpBrasil\Data;

use LSNepomuceno\Signet\Data\BaseData;
use LSNepomuceno\Signet\IcpBrasil\Enums\PolicyFinding;
use LSNepomuceno\Signet\IcpBrasil\Enums\SignaturePolicy;

/**
 * What a check of the policy a signature declares found.
 *
 * The shape `Data\Report` has, for the same reason: a caller that already reads
 * one regional report should not have to learn a second way of reading the
 * next. Both carry what was examined, a list of findings, a question with a
 * yes-or-no answer, and lines fit to show somebody
 * (docs/decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md).
 *
 * **`conforms()` is not `isValid()`.** A signature that declares a policy it
 * does not satisfy still verifies against the bytes it covers, and a regional
 * layer deciding what "valid" means for everybody is what
 * [0104](../../../docs/decisions/0104-the-regional-layer-is-its-own-namespace.md)
 * exists to prevent.
 */
final readonly class PolicyReport extends BaseData
{
    /**
     * @param  SignaturePolicy|null  $policy  The policy the signature declared,
     *          when it declared one this package knows. Null covers both a
     *          signature that declared none and one that named an identifier
     *          that is not on ITI's published list, which the findings tell
     *          apart.
     * @param  list<array{finding: PolicyFinding, detail: ?string}>  $findings
     *          What is wrong, and with what, in the order it was checked.
     */
    public function __construct(
        public ?SignaturePolicy $policy = null,
        public array $findings = [],
    ) {}

    /**
     * Whether the signature kept to the policy it declared.
     *
     * **A signature declaring no policy does not conform**, since there was
     * nothing to conform to, which is the same answer `Data\Report` gives a
     * certificate that is not ICP-Brasil at all.
     */
    public function conforms(): bool
    {
        return $this->policy !== null && $this->findings === [];
    }

    /**
     * Whether anything was found of this kind.
     */
    public function has(PolicyFinding $finding): bool
    {
        foreach ($this->findings as $entry) {
            if ($entry['finding'] === $finding) {
                return true;
            }
        }

        return false;
    }

    /**
     * One line per finding, for a log or a message to whoever sent the file.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(
            static fn(array $entry): string => $entry['detail'] === null
                ? $entry['finding']->description()
                : "{$entry['finding']->description()} ({$entry['detail']})",
            $this->findings,
        );
    }
}
