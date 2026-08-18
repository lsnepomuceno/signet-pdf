<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\IcpBrasil;

/**
 * The check digits a CPF and a CNPJ carry.
 *
 * No dependency this package already has offers a rule for either, so it is
 * written here rather than pulled in
 * (docs/spec/conventions.md). It is one algorithm, modulus eleven over
 * positional weights, applied twice with different weights.
 *
 * **The CNPJ is alphanumeric.** Instrução Normativa RFB nº 2.229/2024 and Nota
 * Técnica COCAD/SUARA/RFB nº 49/2024 keep the fourteen positions and open the
 * first twelve to A to Z as well as 0 to 9; the two check digits stay numeric.
 * The arithmetic does not change, only what a character contributes to it: its
 * ASCII value minus 48, so `0` to `9` keep their value and `A` to `Z` count 17
 * to 42. A numeric CNPJ is that same rule over a narrower alphabet, and comes
 * out identical.
 *
 * **This says a number is well formed, never that it exists.** A CPF that
 * passes has the internal consistency the digits promise; whether the Receita
 * Federal ever issued it is a question only the Receita Federal answers.
 *
 * @internal
 */
final readonly class NationalRegistry
{
    /**
     * Whether eleven digits satisfy a CPF's two check digits.
     */
    public function isCpf(string $digits): bool
    {
        if (preg_match('/^\d{11}$/', $digits) !== 1) {
            return false;
        }

        // 00000000000, 11111111111 and the rest satisfy the arithmetic and are
        // rejected everywhere in Brazil, so the arithmetic is not the whole rule.
        if (preg_match('/^(\d)\1{10}$/', $digits) === 1) {
            return false;
        }

        // Weights count down from the length of the part being checked plus one.
        return $this->digit($digits, range(10, 2)) === (int) $digits[9]
            && $this->digit($digits, range(11, 2)) === (int) $digits[10];
    }

    /**
     * Whether fourteen characters satisfy a CNPJ's two check digits.
     *
     * Twelve alphanumeric positions for the root and the order, then two
     * numeric check digits. Letters are uppercase: the specification defines
     * the value of `A` and says nothing about `a`, and lowercasing a document
     * number silently is how a validator invents one the Receita Federal did
     * not issue. A caller with mixed case uppercases it first.
     */
    public function isCnpj(string $registry): bool
    {
        if (preg_match('/^[0-9A-Z]{12}\d{2}$/', $registry) !== 1) {
            return false;
        }

        // Only an all-numeric registry can match this, which is the point: the
        // repeated-digit numbers are arithmetically consistent and rejected
        // everywhere in Brazil.
        if (preg_match('/^(\d)\1{13}$/', $registry) === 1) {
            return false;
        }

        // Unlike the CPF's, these weights cycle from 2 to 9 rather than counting
        // down, which is the only difference between the two.
        $first = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        return $this->digit($registry, $first) === (int) $registry[12]
            && $this->digit($registry, [6, ...$first]) === (int) $registry[13];
    }

    /**
     * One check digit: the weighted sum, modulus eleven, with the two remainders
     * below two both meaning zero.
     *
     * **A character contributes its ASCII value minus 48**, which is the whole
     * of the alphanumeric rule: `'7'` is 55 - 48 = 7, so a numeric registry is
     * unaffected, and `'A'` is 65 - 48 = 17. Written as arithmetic on the byte
     * rather than as a lookup table, because that is what the Receita Federal
     * specifies and a table is a second place for it to be wrong.
     *
     * @param  list<int>  $weights  One per character read, from the left.
     */
    private function digit(string $value, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $position => $weight) {
            $sum += (ord($value[$position]) - 48) * $weight;
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }
}
