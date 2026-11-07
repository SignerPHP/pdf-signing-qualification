<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic;

use RuntimeException;

final class SyntheticPdf
{
    /**
     * @return array<int, string>
     */
    public static function onePage(string $catalogEntries = ''): array
    {
        return [
            1 => "<< /Type /Catalog /Pages 2 0 R {$catalogEntries} >>",
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] >>',
        ];
    }

    /**
     * @param  array<int, string>  $objects
     */
    public static function classic(array $objects, string $version = '1.4', string $trailerEntries = ''): string
    {
        $document = "%PDF-{$version}\n%\xE2\xE3\xCF\xD3\n";

        return self::appendSection($document, $objects, $trailerEntries, null);
    }

    /**
     * @param  array<int, string>  $objects
     */
    public static function appendUpdate(string $document, array $objects): string
    {
        if (preg_match_all('/startxref\s+(\d+)/', $document, $matches) < 1) {
            throw new RuntimeException('The document has no startxref to chain the update to');
        }

        return self::appendSection($document, $objects, '', (int) end($matches[1]));
    }

    public static function write(string $directory, string $name, string $document): string
    {
        $path = "{$directory}/{$name}";
        if (file_put_contents($path, $document) !== strlen($document)) {
            throw new RuntimeException("Unable to write {$path}");
        }

        return $path;
    }

    /**
     * @param  array<int, string>  $objects
     */
    private static function appendSection(string $document, array $objects, string $trailerEntries, ?int $previousOffset): string
    {
        $offsets = [];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($document);
            $document .= "{$number} 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($document);
        $document .= "xref\n0 1\n0000000000 65535 f \n";
        foreach ($offsets as $number => $offset) {
            $document .= "{$number} 1\n".sprintf("%010d 00000 n \n", $offset);
        }
        $size = max(array_keys($objects)) + 1;
        if ($previousOffset !== null) {
            preg_match_all('#/Size (\d+)#', $document, $sizes);
            $size = max($size, ...array_map(intval(...), $sizes[1]));
        }
        $previous = $previousOffset === null ? '' : " /Prev {$previousOffset}";
        $document .= "trailer\n<< /Size {$size} /Root 1 0 R{$previous} {$trailerEntries} >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $document;
    }
}
