package io.github.signerphp.qualification.icp;

import com.fasterxml.jackson.core.json.JsonWriteFeature;
import com.fasterxml.jackson.databind.json.JsonMapper;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;
import org.apache.pdfbox.pdmodel.interactive.form.PDSignatureField;
import org.demoiselle.signer.policy.engine.asn1.etsi.SignaturePolicy;
import org.demoiselle.signer.policy.impl.cades.SignatureInformations;
import org.demoiselle.signer.policy.impl.pades.pkcs7.impl.PAdESChecker;

import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Properties;

public final class IcpValidator {

    public static final String REPORT_PREFIX = "qualification-report: ";

    private static final JsonMapper JSON = JsonMapper.builder().enable(JsonWriteFeature.ESCAPE_NON_ASCII).build();

    public static void main(String[] args) throws Exception {
        if (args.length == 1 && "--version".equals(args[0])) {
            System.out.println("demoiselle-signer " + version());
            return;
        }
        if (args.length != 1) {
            System.err.println("Usage: icp-validator <document.pdf>");
            System.exit(2);
        }

        byte[] pdf = Files.readAllBytes(Path.of(args[0]));
        List<Map<String, Object>> signatures = new ArrayList<>();
        try (PDDocument document = PDDocument.load(pdf)) {
            for (PDSignatureField field : document.getSignatureFields()) {
                PDSignature signature = field.getSignature();
                if (signature == null || "ETSI.RFC3161".equals(signature.getSubFilter())) {
                    continue;
                }
                signatures.add(check(field.getFullyQualifiedName(), signature, pdf));
            }
        }

        Map<String, Object> report = new LinkedHashMap<>();
        report.put("demoiselle_version", version());
        report.put("signatures", signatures);
        System.out.println(REPORT_PREFIX + JSON.writeValueAsString(report));
    }

    private static Map<String, Object> check(String field, PDSignature signature, byte[] pdf) {
        Map<String, Object> result = new LinkedHashMap<>();
        result.put("field", field);
        result.put("byte_range", signature.getByteRange());
        result.put("sub_filter", signature.getSubFilter());
        try {
            List<SignatureInformations> informations = new PAdESChecker()
                    .checkDetachedSignature(signature.getSignedContent(pdf), signature.getContents(pdf));
            List<Map<String, Object>> checks = new ArrayList<>();
            for (SignatureInformations information : informations) {
                Map<String, Object> check = new LinkedHashMap<>();
                check.put("invalid_signature", information.isInvalidSignature());
                check.put("policy", policy(information.getSignaturePolicy()));
                check.put("signer", information.getSignerBasicCertificate() == null ? null : information.getSignerBasicCertificate().getName());
                check.put("timestamp", information.getTimeStampSigner() != null);
                check.put("errors", information.getValidatorErrors() == null ? List.of() : List.copyOf(information.getValidatorErrors()));
                check.put("warnings", information.getValidatorWarnins() == null ? List.of() : List.copyOf(information.getValidatorWarnins()));
                checks.add(check);
            }
            result.put("checks", checks);
        } catch (Exception exception) {
            result.put("error", exception.getClass().getName() + ": " + exception.getMessage());
        }
        return result;
    }

    private static String policy(SignaturePolicy policy) {
        if (policy == null || policy.getSignPolicyInfo() == null || policy.getSignPolicyInfo().getSignPolicyIdentifier() == null) {
            return null;
        }
        return policy.getSignPolicyInfo().getSignPolicyIdentifier().getValue();
    }

    private static String version() throws Exception {
        Properties properties = new Properties();
        try (InputStream stream = IcpValidator.class.getResourceAsStream("/validator.properties")) {
            properties.load(stream);
        }
        return properties.getProperty("demoiselle.version");
    }
}
