#!/usr/bin/env sh
#
# Produces tests/Resources/policy-signed.pdf: a document carrying the CAdES
# `signature-policy-identifier` signed attribute of RFC 5126 §5.8.1.
#
# **Written by pyHanko rather than by this package**, and that is the point.
# Nothing here can produce the attribute yet: it is signed, so it has to go into
# the signed attributes before they are signed, and the CMS library underneath
# exposes no way to contribute one (issue #56). A fixture this package wrote
# would also only prove that its reader agrees with its writer.
#
# The policy OID is 2.999.1.1, under the arc ITU-T Rec. X.667 sets aside for
# examples. A real ICP-Brasil OID is deliberately not used: those, their URIs
# and their hashes have to be read from ITI's published artefacts when the
# writing half is built, and a fixture carrying one transcribed from memory
# would be wrong in a way nothing here could catch.
#
# Run from the package root, in the development image:
#
#   docker compose -f .docker/compose.yaml run --rm php tests/Resources/make-policy-signature.sh
set -eu

python3 - <<'PY'
from asn1crypto import algos, core

from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.sign import signers
from pyhanko.sign.ades.api import CAdESSignedAttrSpec
from pyhanko.sign.ades import cades_asn1
from pyhanko.sign.fields import SigSeedSubFilter

PASSWORD = b'example\'s password with special chars: $ & * ? " \''

policy = cades_asn1.SignaturePolicyIdentifier(
    name='signature_policy_id',
    value=cades_asn1.SignaturePolicyId({
        'sig_policy_id': '2.999.1.1',
        'sig_policy_hash': algos.DigestInfo({
            'digest_algorithm': algos.DigestAlgorithm({'algorithm': 'sha256'}),
            # The digest of the policy document. Any value is structurally
            # valid; what a verifier does with it is the writing half's problem.
            'digest': bytes(range(32)),
        }),
        'sig_policy_qualifiers': cades_asn1.SigPolicyQualifierInfos([
            cades_asn1.SigPolicyQualifierInfo({
                'sig_policy_qualifier_id': 'sp_uri',
                'sig_qualifier': core.IA5String('https://policy.invalid/example.der'),
            }),
        ]),
    }),
)

signer = signers.SimpleSigner.load_pkcs12(
    pfx_file='samples/certificate.pfx',
    passphrase=PASSWORD,
)

with open('tests/Resources/test.pdf', 'rb') as source:
    writer = IncrementalPdfFileWriter(source)

    with open('tests/Resources/policy-signed.pdf', 'wb') as target:
        signers.sign_pdf(
            writer,
            signers.PdfSignatureMetadata(
                field_name='PolicySignature',
                subfilter=SigSeedSubFilter.PADES,
                cades_signed_attr_spec=CAdESSignedAttrSpec(
                    signature_policy_identifier=policy,
                ),
            ),
            signer=signer,
            output=target,
        )

print('tests/Resources/policy-signed.pdf written')
PY
