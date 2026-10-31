<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;

final class SyntheticSignedPdf
{
    public const string SIGNED_TEXT = 'Synthetic signed fixture';

    private const int CONTENTS_HEX_LENGTH = 16_384;

    private const string BYTE_RANGE_PLACEHOLDER = '/ByteRange [0 ********** ********** **********]';

    public static function create(string $directory, string $name = 'signed', bool $indefiniteLength = false, bool $wrappedContents = false, string $unsignedGapSuffix = '', bool $hybridReference = false): string
    {
        [$certificate, $privateKey] = self::generateCredentials();

        $document = self::document(self::signedObjects($unsignedGapSuffix), $hybridReference);
        $contentsStart = (int) strpos($document, '/Contents <') + strlen('/Contents ');
        $contentsEnd = $contentsStart + self::CONTENTS_HEX_LENGTH + 2 + strlen($unsignedGapSuffix);
        $byteRange = sprintf('/ByteRange [0 %d %d %d]', $contentsStart, $contentsEnd, strlen($document) - $contentsEnd);
        $document = str_replace(self::BYTE_RANGE_PLACEHOLDER, str_pad($byteRange, strlen(self::BYTE_RANGE_PLACEHOLDER)), $document);

        $signedContent = substr($document, 0, $contentsStart).substr($document, $contentsEnd);
        $signature = $indefiniteLength
            ? self::streamedCms($directory, $signedContent, $certificate, $privateKey)
            : self::detachedCms($directory, $signedContent, $certificate, $privateKey);
        $hex = $wrappedContents ? chunk_split(bin2hex($signature), 64, "\n") : bin2hex($signature);
        $contents = '<'.str_pad($hex, self::CONTENTS_HEX_LENGTH, '0').'>';

        $path = "{$directory}/{$name}.pdf";
        file_put_contents($path, substr_replace($document, $contents, $contentsStart, strlen($contents)));

        return $path;
    }

    public static function appendSignatureWithIndirectByteRange(string $signedPath, string $directory): string
    {
        $document = (string) file_get_contents($signedPath);
        if (preg_match('/startxref\n(\d+)\n%%EOF\n\z/', $document, $matches) !== 1) {
            throw new RuntimeException('The cross-reference offset was not found in '.$signedPath);
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [5 0 R 9 0 R] /SigFlags 3 >> >>',
            7 => '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached /ByteRange 8 0 R /Contents <'.str_repeat('0', 64).'> >>',
            8 => '[0 10 20 30]',
            9 => '<< /Type /Annot /Subtype /Widget /FT /Sig /T (Signature2) /Rect [0 0 0 0] /F 132 /P 3 0 R /V 7 0 R >>',
        ];

        $update = '';
        $offsets = [];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($document) + strlen($update);
            $update .= "{$number} 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($document) + strlen($update);
        $update .= "xref\n0 1\n0000000000 65535 f \n1 1\n".sprintf("%010d 00000 n \n", $offsets[1])."7 3\n";
        foreach ([7, 8, 9] as $number) {
            $update .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        $update .= "trailer\n<< /Size 10 /Root 1 0 R /Prev {$matches[1]} >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        $path = $directory.'/indirect-byte-range.pdf';
        file_put_contents($path, $document.$update);

        return $path;
    }

    public static function replaceContents(string $signedPath, string $directory, string $contents): string
    {
        $document = (string) file_get_contents($signedPath);
        if (preg_match('#/ByteRange \[0 (\d+) (\d+) \d+\]#', $document, $matches) !== 1) {
            throw new RuntimeException('The byte range was not found in '.$signedPath);
        }

        $hexLength = (int) $matches[2] - (int) $matches[1] - 2;
        $path = $directory.'/replaced-contents.pdf';
        file_put_contents($path, substr_replace($document, str_pad(bin2hex($contents), $hexLength, '0'), (int) $matches[1] + 1, $hexLength));

        return $path;
    }

    public static function createUnsigned(string $directory): string
    {
        $path = $directory.'/unsigned.pdf';
        file_put_contents($path, self::document(self::pageObjects('', '')));

        return $path;
    }

    public static function tamperSignedRange(string $signedPath, string $directory): string
    {
        $document = (string) file_get_contents($signedPath);
        $position = strpos($document, self::SIGNED_TEXT);
        if ($position === false) {
            throw new RuntimeException('The signed text was not found in '.$signedPath);
        }

        $lastLetter = $position + strlen(self::SIGNED_TEXT) - 1;
        $document[$lastLetter] = $document[$lastLetter] === 'e' ? 'f' : 'e';

        $path = $directory.'/tampered.pdf';
        file_put_contents($path, $document);

        return $path;
    }

    /**
     * @return list<string>
     */
    private static function pageObjects(string $catalogEntries, string $pageEntries): array
    {
        $content = 'BT /F1 12 Tf 20 100 Td ('.self::SIGNED_TEXT.') Tj ET';

        return [
            "<< /Type /Catalog /Pages 2 0 R{$catalogEntries} >>",
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Contents 4 0 R{$pageEntries} /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>",
            '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
        ];
    }

    /**
     * @return list<string>
     */
    private static function signedObjects(string $unsignedGapSuffix): array
    {
        return [
            ...self::pageObjects(' /AcroForm << /Fields [5 0 R] /SigFlags 3 >>', ' /Annots [5 0 R]'),
            '<< /Type /Annot /Subtype /Widget /FT /Sig /T (Signature1) /Rect [0 0 0 0] /F 132 /P 3 0 R /V 6 0 R >>',
            '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached '.self::BYTE_RANGE_PLACEHOLDER.' /Contents <'.str_repeat('0', self::CONTENTS_HEX_LENGTH).'>'.$unsignedGapSuffix.' /M (D:20261029120000Z) >>',
        ];
    }

    /**
     * @param  list<string>  $objects
     */
    private static function document(array $objects, bool $hybridReference = false): string
    {
        $document = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($document);
            $document .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $trailerEntries = '';
        if ($hybridReference) {
            $xrefStreamNumber = count($objects) + 1;
            $offsets[] = strlen($document);
            $rows = "\x00".pack('N', 0).pack('n', 65535);
            foreach ($offsets as $offset) {
                $rows .= "\x01".pack('N', $offset).pack('n', 0);
            }
            $document .= "{$xrefStreamNumber} 0 obj\n<< /Type /XRef /Size ".($xrefStreamNumber + 1).' /W [1 4 2] /Length '.strlen($rows)." >>\nstream\n{$rows}\nendstream\nendobj\n";
            $trailerEntries = ' /XRefStm '.end($offsets);
        }

        $xrefOffset = strlen($document);
        $document .= 'xref
0 '.(count($offsets) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $document .= sprintf("%010d 00000 n \n", $offset);
        }

        return $document.'trailer
<< /Size '.(count($offsets) + 1)." /Root 1 0 R{$trailerEntries} >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }

    /**
     * @return array{OpenSSLCertificate, OpenSSLAsymmetricKey}
     */
    private static function generateCredentials(): array
    {
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($privateKey === false) {
            throw new RuntimeException('Unable to generate the synthetic signing key');
        }

        $request = openssl_csr_new(['commonName' => 'Synthetic test signer'], $privateKey, ['digest_alg' => 'sha256']);
        $certificate = $request === false || $request === true ? false : openssl_csr_sign($request, null, $privateKey, 1, ['digest_alg' => 'sha256']);
        if ($certificate === false) {
            throw new RuntimeException('Unable to generate the synthetic signing certificate');
        }

        return [$certificate, $privateKey];
    }

    private static function streamedCms(string $directory, string $signedContent, OpenSSLCertificate $certificate, OpenSSLAsymmetricKey $privateKey): string
    {
        $contentPath = $directory.'/signed-content.bin';
        $certificatePath = $directory.'/certificate.pem';
        $privateKeyPath = $directory.'/private-key.pem';
        $signaturePath = $directory.'/signature.ber';
        file_put_contents($contentPath, $signedContent);

        try {
            if (! openssl_x509_export_to_file($certificate, $certificatePath) || ! openssl_pkey_export_to_file($privateKey, $privateKeyPath)) {
                throw new RuntimeException('Unable to export the synthetic signing credentials: '.openssl_error_string());
            }

            $result = (new ProcessRunner)->run(['openssl', 'cms', '-sign', '-binary', '-stream', '-outform', 'DER', '-md', 'sha256', '-in', $contentPath, '-signer', $certificatePath, '-inkey', $privateKeyPath, '-out', $signaturePath], 30.0);
            if ($result->exitCode !== 0) {
                throw new RuntimeException('Unable to sign the synthetic document with indefinite lengths: '.$result->output);
            }

            return (string) file_get_contents($signaturePath);
        } finally {
            @unlink($contentPath);
            @unlink($certificatePath);
            @unlink($privateKeyPath);
            @unlink($signaturePath);
        }
    }

    private static function detachedCms(string $directory, string $signedContent, OpenSSLCertificate $certificate, OpenSSLAsymmetricKey $privateKey): string
    {
        $contentPath = $directory.'/signed-content.bin';
        $signaturePath = $directory.'/signature.der';
        file_put_contents($contentPath, $signedContent);

        try {
            if (! openssl_cms_sign($contentPath, $signaturePath, $certificate, $privateKey, null, OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER)) {
                throw new RuntimeException('Unable to sign the synthetic document: '.openssl_error_string());
            }

            return (string) file_get_contents($signaturePath);
        } finally {
            @unlink($contentPath);
            @unlink($signaturePath);
        }
    }
}
