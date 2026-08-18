#!/bin/sh
# Regenerates the encrypted object-stream fixtures.
#
# A document that is both encrypted and packed into object streams is what a
# password-protected export from a word processor looks like, and it was refused
# outright until 2.0. Both halves already existed and only needed to meet: the
# container stream has to be decrypted with its own object number before it is
# unpacked (ISO 32000-1 §7.5.7 and §7.6.2).
#
# Committed with the fixtures so they can be rebuilt rather than trusted, and so
# the suite does not need qpdf to run the tests that use them.
#
#   docker compose -f .docker/compose.yaml run --rm php \
#       sh tests/Resources/make-encrypted-object-streams.sh
#
# The password is "secret" for both, matching the other encrypted fixtures.
set -eu

here="$(dirname "$0")"

# AES is the only cipher at 256 bits, so qpdf rejects --use-aes there rather
# than accepting it redundantly.
for bits in 128 256; do
    aes=""
    [ "$bits" = "128" ] && aes="--use-aes=y"

    # shellcheck disable=SC2086
    qpdf --object-streams=generate \
        --encrypt --user-password=secret --owner-password=secret --bits="$bits" $aes -- \
        "$here/test.pdf" "$here/encrypted-objstm-aes$bits.pdf"

    # Verified rather than trusted, and quietly: piping qpdf into head closes
    # its output and `set -e` then reads the SIGPIPE as a failure.
    qpdf --check --password=secret "$here/encrypted-objstm-aes$bits.pdf" >/dev/null
    echo "wrote encrypted-objstm-aes$bits.pdf"
done
