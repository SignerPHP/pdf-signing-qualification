package io.github.signerphp.qualification.dss;

import com.fasterxml.jackson.core.json.JsonWriteFeature;
import com.fasterxml.jackson.databind.json.JsonMapper;
import eu.europa.esig.dss.diagnostic.SignatureWrapper;
import eu.europa.esig.dss.enumerations.Indication;
import eu.europa.esig.dss.enumerations.SignatureLevel;
import eu.europa.esig.dss.enumerations.SubIndication;
import eu.europa.esig.dss.jaxb.object.Message;
import eu.europa.esig.dss.model.FileDocument;
import eu.europa.esig.dss.service.crl.OnlineCRLSource;
import eu.europa.esig.dss.service.http.commons.CommonsDataLoader;
import eu.europa.esig.dss.service.http.commons.OCSPDataLoader;
import eu.europa.esig.dss.service.ocsp.OnlineOCSPSource;
import eu.europa.esig.dss.simplereport.SimpleReport;
import eu.europa.esig.dss.spi.DSSUtils;
import eu.europa.esig.dss.spi.validation.CommonCertificateVerifier;
import eu.europa.esig.dss.spi.x509.CommonTrustedCertificateSource;
import eu.europa.esig.dss.spi.x509.aia.DefaultAIASource;
import eu.europa.esig.dss.validation.SignedDocumentValidator;
import eu.europa.esig.dss.validation.reports.Reports;

import java.io.File;
import java.io.InputStream;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Properties;

public final class DssValidator {

    public static final String REPORT_PREFIX = "qualification-report: ";

    private static final JsonMapper JSON = JsonMapper.builder().enable(JsonWriteFeature.ESCAPE_NON_ASCII).build();

    public static void main(String[] args) throws Exception {
        if (args.length == 1 && "--version".equals(args[0])) {
            System.out.println("dss-validator " + version());
            return;
        }
        if (args.length != 2) {
            System.err.println("Usage: dss-validator <trusted-root-certificate> <document.pdf>");
            System.exit(2);
        }

        SignedDocumentValidator validator = SignedDocumentValidator.fromDocument(new FileDocument(new File(args[1])));
        validator.setCertificateVerifier(certificateVerifier(new File(args[0])));
        Reports reports = validator.validateDocument();
        SimpleReport simple = reports.getSimpleReport();

        List<Map<String, Object>> signatures = new ArrayList<>();
        for (String id : simple.getSignatureIdList()) {
            Map<String, Object> signature = token(simple, id);
            SignatureLevel format = simple.getSignatureFormat(id);
            signature.put("format", format == null ? null : format.toString());
            signature.put("signed_by", simple.getSignedBy(id));
            SignatureWrapper wrapper = reports.getDiagnosticData().getSignatureById(id);
            signature.put("fields", wrapper == null || wrapper.getSignatureFieldNames() == null ? List.of() : wrapper.getSignatureFieldNames());
            signature.put("byte_range", wrapper == null || wrapper.getPDFRevision() == null ? null : wrapper.getPDFRevision().getSignatureByteRange());
            signatures.add(signature);
        }
        List<Map<String, Object>> timestamps = new ArrayList<>();
        for (String id : simple.getTimestampIdList()) {
            timestamps.add(token(simple, id));
        }

        Map<String, Object> report = new LinkedHashMap<>();
        report.put("dss_version", version());
        report.put("signatures", signatures);
        report.put("timestamps", timestamps);
        System.out.println(REPORT_PREFIX + JSON.writeValueAsString(report));
    }

    private static CommonCertificateVerifier certificateVerifier(File trustedRoot) {
        CommonTrustedCertificateSource trusted = new CommonTrustedCertificateSource();
        trusted.addCertificate(DSSUtils.loadCertificate(trustedRoot));

        CommonCertificateVerifier verifier = new CommonCertificateVerifier();
        verifier.setTrustedCertSources(trusted);
        verifier.setAIASource(new DefaultAIASource(new CommonsDataLoader()));
        verifier.setCrlSource(new OnlineCRLSource(new CommonsDataLoader()));
        OnlineOCSPSource ocsp = new OnlineOCSPSource();
        ocsp.setDataLoader(new OCSPDataLoader());
        verifier.setOcspSource(ocsp);
        return verifier;
    }

    private static Map<String, Object> token(SimpleReport simple, String id) {
        Indication indication = simple.getIndication(id);
        SubIndication subIndication = simple.getSubIndication(id);

        Map<String, Object> token = new LinkedHashMap<>();
        token.put("id", id);
        token.put("indication", indication == null ? null : indication.name());
        token.put("sub_indication", subIndication == null ? null : subIndication.name());
        token.put("errors", messages(simple.getAdESValidationErrors(id)));
        token.put("warnings", messages(simple.getAdESValidationWarnings(id)));
        return token;
    }

    private static List<String> messages(List<Message> messages) {
        return messages == null ? List.of() : messages.stream().map(Message::getValue).toList();
    }

    private static String version() throws Exception {
        Properties properties = new Properties();
        try (InputStream stream = DssValidator.class.getResourceAsStream("/validator.properties")) {
            properties.load(stream);
        }
        return properties.getProperty("dss.version");
    }
}
