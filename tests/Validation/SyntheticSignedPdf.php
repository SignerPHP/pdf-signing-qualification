<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

final class SyntheticSignedPdf
{
    public const string SIGNED_TEXT = 'Synthetic signed fixture';

    private const int CONTENTS_HEX_LENGTH = 16_384;

    private const string BYTE_RANGE_PLACEHOLDER = '/ByteRange [0 ********** ********** **********]';

    public static function create(string $directory): string
    {
        [$certificate, $privateKey] = self::generateCredentials();

        $document = self::document(self::signedObjects());
        $contentsStart = (int) strpos($document, '/Contents <') + strlen('/Contents ');
        $contentsEnd = $contentsStart + self::CONTENTS_HEX_LENGTH + 2;
        $byteRange = sprintf('/ByteRange [0 %d %d %d]', $contentsStart, $contentsEnd, strlen($document) - $contentsEnd);
        $document = str_replace(self::BYTE_RANGE_PLACEHOLDER, str_pad($byteRange, strlen(self::BYTE_RANGE_PLACEHOLDER)), $document);

        $signedContent = substr($document, 0, $contentsStart).substr($document, $contentsEnd);
        $signature = self::detachedCms($directory, $signedContent, $certificate, $privateKey);
        $contents = '<'.str_pad(bin2hex($signature), self::CONTENTS_HEX_LENGTH, '0').'>';

        $path = $directory.'/signed.pdf';
        file_put_contents($path, substr_replace($document, $contents, $contentsStart, strlen($contents)));

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
    private static function signedObjects(): array
    {
        return [
            ...self::pageObjects(' /AcroForm << /Fields [5 0 R] /SigFlags 3 >>', ' /Annots [5 0 R]'),
            '<< /Type /Annot /Subtype /Widget /FT /Sig /T (Signature1) /Rect [0 0 0 0] /F 132 /P 3 0 R /V 6 0 R >>',
            '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached '.self::BYTE_RANGE_PLACEHOLDER.' /Contents <'.str_repeat('0', self::CONTENTS_HEX_LENGTH).'> /M (D:20261029120000Z) >>',
        ];
    }

    /**
     * @param  list<string>  $objects
     */
    private static function document(array $objects): string
    {
        $document = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($document);
            $document .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($document);
        $document .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $document .= sprintf("%010d 00000 n \n", $offset);
        }

        return $document.'trailer
<< /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
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
