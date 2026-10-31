<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic;

use RuntimeException;

final class HybridXrefPdf
{
    public const int XREF_STREAM_OFFSET = 445;

    private const int OBJECT_STREAM_NUMBER = 5;

    public static function create(string $directory): string
    {
        $content = 'BT /F1 12 Tf 20 100 Td (Hybrid xref fixture) Tj ET';
        $compressedObjects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Contents 4 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>',
        ];

        $objectStreamHeader = '';
        $objectStreamBody = '';
        foreach ($compressedObjects as $number => $object) {
            $objectStreamHeader .= $number.' '.strlen($objectStreamBody).' ';
            $objectStreamBody .= $object."\n";
        }
        $objectStream = $objectStreamHeader.$objectStreamBody;

        $document = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $offsets[4] = strlen($document);
        $document .= "4 0 obj\n<< /Length ".strlen($content)." >>\nstream\n{$content}\nendstream\nendobj\n";
        $offsets[self::OBJECT_STREAM_NUMBER] = strlen($document);
        $document .= self::OBJECT_STREAM_NUMBER." 0 obj\n<< /Type /ObjStm /N ".count($compressedObjects).' /First '.strlen($objectStreamHeader).' /Length '.strlen($objectStream)." >>\nstream\n{$objectStream}\nendstream\nendobj\n";
        $offsets[6] = strlen($document);
        if ($offsets[6] !== self::XREF_STREAM_OFFSET) {
            throw new RuntimeException("The cross-reference stream starts at {$offsets[6]} instead of ".self::XREF_STREAM_OFFSET);
        }

        $rows = "\x00".pack('n', 0)."\xFF";
        foreach (array_keys($compressedObjects) as $index => $number) {
            $rows .= "\x02".pack('n', self::OBJECT_STREAM_NUMBER).chr($index);
        }
        foreach ([4, self::OBJECT_STREAM_NUMBER, 6] as $number) {
            $rows .= "\x01".pack('n', $offsets[$number])."\x00";
        }
        $document .= "6 0 obj\n<< /Type /XRef /Size 7 /W [1 2 1] /Length ".strlen($rows)." >>\nstream\n{$rows}\nendstream\nendobj\n";

        $xrefOffset = strlen($document);
        $document .= "xref\n0 1\n0000000000 65535 f \n4 3\n";
        foreach ([4, self::OBJECT_STREAM_NUMBER, 6] as $number) {
            $document .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        $document .= 'trailer
<< /Size 7 /Root 1 0 R /XRefStm '.self::XREF_STREAM_OFFSET." >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        $path = $directory.'/hybrid-xref.pdf';
        file_put_contents($path, $document);

        return $path;
    }
}
