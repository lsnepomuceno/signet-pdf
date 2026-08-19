#!/bin/sh
#
# Runs the mutation suite, and clears the debris it leaves behind.
#
# Usage:
#   .docker/mutate.sh                    every mutated namespace, floor 65
#   .docker/mutate.sh Certificates 64    one namespace, its own floor
#   .docker/mutate.sh Signing/Incremental 60          a directory inside one
#   .docker/mutate.sh Signing 60 Signing/Incremental  the rest of that namespace
#   .docker/mutate.sh Support/Files.php,Support/Probe.php 60   named files
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

# One target, or every mutated namespace. This default and the CI matrix in
# .github/workflows/mutation.yml have to agree, and the workflow calls this
# script so that there is one list rather than two that drift.
#
# A target is a namespace (`Signing`), a directory inside one
# (`Signing/Incremental`), or a comma-separated list of either plus individual
# files (`Support/Files.php,Support/Probe.php`). The last two shapes exist
# because two legs of the matrix stopped finishing: a job on a hosted runner is
# killed at six hours, which reports as cancelled rather than as a failure, so
# `src/Signing` and `src/Support` were gating nothing while looking like clean
# nights (#84). Splitting by mutated path is the sanctioned answer, and
# `src/Support` is flat, so files are the only division it has.
#
# **Every element is checked to exist.** `--path=src/Typo` is not an error to
# the plugin, it is a path holding nothing to mutate: the suite runs in full,
# `0 Mutations for 0 Files created` scrolls past, and the run reports a score of
# 0.00%. A single wrong letter in the CI matrix therefore spent three minutes
# measuring nothing and then filed a score regression against a namespace that
# does not exist.
resolve() {
    resolved=''
    remaining="$1,"

    # Split on commas without a subshell or an array, which `sh` has neither of.
    while [ -n "$remaining" ]; do
        target=${remaining%%,*}
        remaining=${remaining#*,}

        [ -n "$target" ] || continue

        if [ ! -e "$root/src/$target" ]; then
            echo "mutate.sh: no such target, src/$target does not exist" >&2
            exit 2
        fi

        resolved="${resolved:+$resolved,}src/$target"
    done

    if [ -z "$resolved" ]; then
        echo "mutate.sh: no target to mutate" >&2
        exit 2
    fi

    echo "$resolved"
}

ignore=''

if [ -n "$1" ]; then
    paths=$(resolve "$1")
else
    paths="src/Certificates,src/IcpBrasil,src/Signing,src/Support,src/Validation"
    ignore="src/Support/SrgbProfile.php"
fi

min=${2:-65}

# What to leave out of a directory another leg covers, so the two halves of a
# split namespace do not measure the same files twice. Checked like the paths
# above, because an ignore naming nothing silently doubles a leg's work.
#
# `Support\SrgbProfile` is left out of the default run for a different reason.
# It builds an ICC profile out of matrix arithmetic and a tone curve computed in
# a loop, so nearly every number in it is a mutant, and the tests that kill
# those mutants are the PDF/A ones, each of which runs veraPDF. A leg holding
# that file alone was cancelled at six hours by CI, and a run of it on a
# developer machine had not finished after thirty minutes. What the file
# produces is measured by veraPDF instead
# (docs/spec/quality-policy.md).
if [ -n "$3" ]; then
    ignore=$(resolve "$3")
fi

# Deliberately narrow: a bare UUIDv7, optionally carrying one extension, the
# twelve directories named after an extension that a truncated path produces,
# and the probes in tests/Support/TempDirectoryTest.php. That last one is not
# hypothetical: the mutant that removes the guard lets those tests create the
# very directory they exist to forbid, so the test names its path with a prefix
# this sweep knows.
#
# Anything git tracks is refused outright, so a mistake in the pattern cannot
# cost a file that belongs to the repository.
sweep() {
    for entry in "$root"/????????-????-????-????-???????????? \
                 "$root"/????????-????-????-????-????????????.* \
                 "$root"/signet-relative-probe* \
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

    rm -f "$log" "$status"
}

# Outside the repository on purpose: this script exists to keep working files
# out of it.
log=$(mktemp "${TMPDIR:-/tmp}/signet-mutation.XXXXXX")
status=$(mktemp "${TMPDIR:-/tmp}/signet-mutation-status.XXXXXX")

# On EXIT rather than after the command: `set -e` leaves before any following
# line when the score is under the floor, which is exactly a run worth sweeping.
trap sweep EXIT INT TERM

# Piped through tee so the run can be read as it happens and inspected once it
# ends. A pipeline reports the exit status of its last command, which would be
# tee's, so pest's is carried out of the group in a file rather than trusted
# from `$?`. The workflow's own `| tee mutation.log` still works: this writes to
# the copy it reads, and passes the same bytes through.
#
# Written as an `if` rather than as two statements: `set -e` reaches inside the
# group, and a failing pest would end it before the status was ever recorded.
# A condition is the one place the option does not apply.
{
    if vendor/bin/pest \
        --mutate \
        --path="$paths" \
        ${ignore:+--ignore="$ignore"} \
        --exclude-group=network \
        --min="$min"
    then
        echo 0 > "$status"
    else
        echo $? > "$status"
    fi
} | tee "$log"

failed=$(cat "$status" 2>/dev/null || true)

# An empty file means the run was killed before it could record anything, which
# is a failure and not a pass.
[ -n "$failed" ] || failed=1

if [ "$failed" != '0' ]; then
    exit "$failed"
fi

# A run that mutated nothing is not a run that measured anything, and pest
# answers it with a score of 0.00%: a pass under a floor of 0, and a score
# regression under any real one. Both are the wrong verdict, and it is the same
# failure the header rejects the scratch directory for, arriving through the
# arguments instead of through the location.
#
# The namespace check above catches the cause this has actually had. This
# catches the rest of them, whatever they turn out to be, since the question
# it asks is about the run rather than about its arguments.
if grep -q 'No mutations created' "$log"; then
    echo "mutate.sh: no mutation was created for $paths, so nothing was measured" >&2
    exit 3
fi
