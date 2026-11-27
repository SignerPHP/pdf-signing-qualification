<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic;

use RuntimeException;

final class PadesCatalogEntriesPdf
{
    public const array ENTRIES = [
        'the catalog /DocMDP' => '/DocMDP 4 0 R',
        'the catalog /DocTimeStamp' => '/DocTimeStamp 4 0 R',
        '/Perms /DocTimeStamp' => '/Perms << /DocTimeStamp 4 0 R >>',
    ];

    public static function input(string $directory, string $name, string $catalogEntries): string
    {
        $objects = SyntheticPdf::onePage($catalogEntries) + [4 => '<< /Type /Example /Child 6 0 R >>', 6 => '<< /Origin (input) >>'];

        return SyntheticPdf::write($directory, "{$name}.pdf", SyntheticPdf::classic($objects));
    }

    public static function update(string $directory, string $name, string $inputPath, string $catalogEntries, ?int $changedObject = null): string
    {
        $input = file_get_contents($inputPath);
        if ($input === false) {
            throw new RuntimeException("Unable to read {$inputPath}");
        }
        $objects = [1 => SyntheticPdf::onePage($catalogEntries)[1]];
        $objects[5] = '<< /Origin (update) >>';
        if ($changedObject !== null) {
            $objects[$changedObject] = '<< /Type /Example /Origin (update) >>';
        }
        ksort($objects);

        return SyntheticPdf::write($directory, "{$name}.pdf", SyntheticPdf::appendUpdate($input, $objects));
    }
}
