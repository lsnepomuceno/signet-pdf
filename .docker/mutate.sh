#!/bin/sh
#
# Runs the mutation suite, and clears the debris it leaves behind.
#
# Usage:
#   .docker/mutate.sh                  every mutated namespace, floor 65
#   .docker/mutate.sh Certificates 64  one namespace, its own floor
#
# Mutation testing rewrites `src/` on purpose, and `Support\TempDirectory` builds
# the path a temporary file is written to by concatenation. A mutant that drops
# an operand returns a name with no directory in it, and the filesystem resolves
# a relative path against the working directory, which for this suite is the
# repository. `tests/Pest.php` routes every fixture through that one method, so
# a single mutant scatters throwaway PKCS#12 bundles, PEM private keys and
# signed PDFs across the root, and the code that would delete them no longer
# knows where they went. It had reached 1328 entries and 10 MB before anyone
# looked, invisible to `git status` because those extensions are all gitignored.
#
# `TempDirectory` now refuses a relative path outright, which kills most of
# those mutants at the source. It cannot kill all of them: a mutant that removes
# the guard itself puts the old behaviour back for the length of one run. So the
# sweep below is the backstop rather than the fix.
#
# **The run stays in the package root.** Working from a scratch directory was
# tried first and is the wrong answer: `pest-plugin-mutate` maps coverage by
# path, and from anywhere else it reports every mutation as uncovered. Measured
# on src/Support: 1947 uncovered, 0 tested, 0.00%, against a namespace that
# scores around 78 from the root. It fails loudly rather than silently, but a
# gate that measures nothing is worse than the debris it was meant to prevent.
#
# See docs/spec/quality-policy.md.

set -e

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

cd "$root"

# One namespace, or every mutated one. This default and the CI matrix in
# .github/workflows/mutation.yml have to agree, and the workflow calls this
# script so that there is one list rather than two that drift.
if [ -n "$1" ]; then
    paths="src/$1"
else
    paths="src/Certificates,src/IcpBrasil,src/Signing,src/Support,src/Validation"
fi

min=${2:-65}

# Deliberately narrow: a bare UUIDv7, optionally carrying one extension, and the
# twelve directories named after an extension that a truncated path produces.
# Anything git tracks is refused outright, so a mistake in the pattern cannot
# cost a file that belongs to the repository.
sweep() {
    for entry in "$root"/????????-????-????-????-???????????? \
                 "$root"/????????-????-????-????-????????????.* \
                 "$root"/.bin "$root"/.cnf "$root"/.crt "$root"/.der \
                 "$root"/.key "$root"/.p7s "$root"/.pem "$root"/.pfx \
                 "$root"/.tmp "$root"/.tsq "$root"/.tsr "$root"/.tst; do
        [ -e "$entry" ] || continue

        name=${entry##*/}

        if git -C "$root" ls-files --error-unmatch "$name" >/dev/null 2>&1; then
            continue
        fi

        rm -rf "$entry"
    done
}

# On EXIT rather than after the command: `set -e` leaves before any following
# line when the score is under the floor, which is exactly a run worth sweeping.
trap sweep EXIT INT TERM

vendor/bin/pest \
    --mutate \
    --path="$paths" \
    --exclude-group=network \
    --min="$min"
