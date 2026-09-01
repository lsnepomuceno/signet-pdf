import java.io.File;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

import eu.europa.esig.dss.diagnostic.DiagnosticData;
import eu.europa.esig.dss.diagnostic.SignatureWrapper;
import eu.europa.esig.dss.model.DSSDocument;
import eu.europa.esig.dss.model.FileDocument;
import eu.europa.esig.dss.spi.policy.SignaturePolicyProvider;
import eu.europa.esig.dss.spi.validation.CommonCertificateVerifier;
import eu.europa.esig.dss.validation.SignedDocumentValidator;
import eu.europa.esig.dss.validation.reports.Reports;

/**
 * Reports what EU DSS makes of a signature's policy declaration.
 *
 * A second implementation of the one property this suite could not check for
 * itself: whether the digest a signature declares is the digest the policy
 * document it names actually carries. Everything else here checks the package
 * against its own reading of the standard.
 *
 * Usage:
 *
 *   dss-policy-check document.pdf 2.16.76.1.7.1.11.1.3=/path/PA_PAdES_AD_RB_v1_3.der
 *
 * Each argument after the document maps a key to a policy document. The key is
 * an OID or a URI, and both maps are filled from it, because implementations
 * differ on which one they look the policy up by.
 *
 * The policy documents are supplied rather than fetched. Nothing here opens a
 * connection: the eighteen ICP-Brasil policies are committed under
 * tests/Resources/icp-brasil/policies/ and a run that reached the network for
 * one would be measuring the authority's uptime
 * (docs/decisions/0124-the-policy-digest-has-an-offline-witness.md).
 *
 * The output is one JSON object on standard output. The exit status is 0 when
 * the document was read, whatever the verdict, and 1 when it was not: a
 * verdict is an answer and a failure to reach one is not.
 */
public final class DssPolicyCheck {

    public static void main(String[] args) {
        if (args.length < 1) {
            System.err.println("usage: dss-policy-check <document> [<oid-or-uri>=<policy.der> ...]");
            System.exit(1);
        }

        try {
            System.out.println(report(args));
        } catch (Exception failure) {
            System.err.println(failure.getClass().getName() + ": " + failure.getMessage());
            System.exit(1);
        }
    }

    private static String report(String[] args) {
        SignedDocumentValidator validator =
            SignedDocumentValidator.fromDocument(new FileDocument(new File(args[0])));

        validator.setCertificateVerifier(new CommonCertificateVerifier());
        validator.setSignaturePolicyProvider(provider(args));

        Reports reports = validator.validateDocument();
        DiagnosticData diagnostic = reports.getDiagnosticData();

        List<String> signatures = new ArrayList<>();

        for (SignatureWrapper signature : diagnostic.getSignatures()) {
            signatures.add(describe(signature));
        }

        String first = reports.getSimpleReport().getFirstSignatureId();

        return "{\"signatures\":[" + String.join(",", signatures) + "],\"indication\":"
            + quote(first == null ? null : String.valueOf(reports.getSimpleReport().getIndication(first)))
            + "}";
    }

    /**
     * The policy documents, keyed by every key a signature might name them by.
     */
    private static SignaturePolicyProvider provider(String[] args) {
        Map<String, DSSDocument> policies = new HashMap<>();

        for (int i = 1; i < args.length; i++) {
            int separator = args[i].indexOf('=');

            if (separator < 1) {
                throw new IllegalArgumentException("expected <oid-or-uri>=<policy.der>, got: " + args[i]);
            }

            policies.put(
                args[i].substring(0, separator),
                new FileDocument(new File(args[i].substring(separator + 1)))
            );
        }

        SignaturePolicyProvider provider = new SignaturePolicyProvider();
        provider.setSignaturePoliciesById(policies);
        provider.setSignaturePoliciesByUrl(policies);

        return provider;
    }

    /**
     * One signature, as the fields the policy question turns on.
     *
     * `digestValid` is the answer: DSS recomputes the hash over the policy
     * document the signature names and compares it with what the attribute
     * declares. `identified` sits beside it because a false there makes the
     * verdict vacuous rather than negative, which is exactly how two other
     * verifiers reported a defective document as valid.
     */
    private static String describe(SignatureWrapper signature) {
        return "{\"format\":" + quote(String.valueOf(signature.getSignatureFormat()))
            + ",\"policyId\":" + quote(signature.getPolicyId())
            + ",\"identified\":" + signature.isPolicyIdentified()
            + ",\"asn1Processable\":" + signature.isPolicyAsn1Processable()
            + ",\"digestValid\":" + signature.isPolicyDigestValid()
            + ",\"algorithmsEqual\":" + signature.isPolicyDigestAlgorithmsEqual()
            + ",\"error\":" + quote(signature.getPolicyProcessingError())
            + "}";
    }

    private static String quote(String value) {
        if (value == null) {
            return "null";
        }

        StringBuilder quoted = new StringBuilder("\"");

        for (char character : value.toCharArray()) {
            switch (character) {
                case '"' -> quoted.append("\\\"");
                case '\\' -> quoted.append("\\\\");
                case '\n' -> quoted.append("\\n");
                case '\r' -> quoted.append("\\r");
                case '\t' -> quoted.append("\\t");
                default -> {
                    if (character < 0x20) {
                        quoted.append(String.format("\\u%04x", (int) character));
                    } else {
                        quoted.append(character);
                    }
                }
            }
        }

        return quoted.append('"').toString();
    }
}
