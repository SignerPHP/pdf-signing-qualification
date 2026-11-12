<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic\SyntheticPdf;

final class SyntheticVisibleSignature
{
    public const array RECTANGLE = [36, 672, 276, 756];

    private const string FIRST_PAGE_CONTENT = '0.6 g 0 0 612 792 re f 0 g BT /F1 24 Tf 72 400 Td (Visible appearance fixture) Tj ET';

    private const string SECOND_PAGE_CONTENT = 'BT /F1 24 Tf 72 400 Td (Second page) Tj ET';

    private const string DRAWING_APPEARANCE = '0 g 0 0 240 84 re f';

    public static function input(string $directory, string $name = 'visible-input', string $firstPageEntries = '/MediaBox [0 0 612 792]'): string
    {
        return SyntheticPdf::write($directory, $name.'.pdf', SyntheticPdf::classic(self::inputObjects($firstPageEntries)));
    }

    /**
     * @param  array{page?: int, rectangle?: list<int|float>, flags?: int, appearance?: 'stream'|'missing'|'empty'|'states', annotated?: bool, changed_page?: ?int, first_page_entries?: string}  $options
     */
    public static function sign(string $inputPath, string $directory, string $name, array $options = []): string
    {
        $page = $options['page'] ?? 1;
        $rectangle = implode(' ', $options['rectangle'] ?? self::RECTANGLE);
        $flags = $options['flags'] ?? 132;
        $appearance = $options['appearance'] ?? 'stream';
        $annotated = $options['annotated'] ?? true;
        $changedPage = $options['changed_page'] ?? null;
        $pageObjects = self::inputObjects($options['first_page_entries'] ?? '/MediaBox [0 0 612 792]');

        $appearanceEntry = match ($appearance) {
            'stream' => '/AP << /N 22 0 R >>',
            'empty' => '/AP << /N 23 0 R >>',
            'states' => '/AP << /N << /On 22 0 R >> >>',
            'missing' => '',
        };
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [20 0 R] /SigFlags 3 >> >>',
            20 => "<< /Type /Annot /Subtype /Widget /FT /Sig /T (Signature1) /Rect [{$rectangle}] /F {$flags} /P ".($page === 1 ? '3' : '6')." 0 R /V 21 0 R {$appearanceEntry} >>",
            21 => '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached /ByteRange [0 10 20 30] /Contents <'.bin2hex($name).'> >>',
            22 => self::stream('/Type /XObject /Subtype /Form /BBox [0 0 240 84]', self::DRAWING_APPEARANCE),
            23 => self::stream('/Type /XObject /Subtype /Form /BBox [0 0 240 84]', ''),
        ];
        if ($annotated) {
            $pageNumber = $page === 1 ? 3 : 6;
            $objects[$pageNumber] = (string) preg_replace('/ >>\z/', ' /Annots [20 0 R] >>', $pageObjects[$pageNumber]);
        }
        if ($changedPage !== null) {
            $contentNumber = $changedPage === 1 ? 4 : 7;
            $objects[$contentNumber] = self::stream('', ($changedPage === 1 ? self::FIRST_PAGE_CONTENT : self::SECOND_PAGE_CONTENT).' 0 g 400 100 100 100 re f');
        }
        ksort($objects);

        $document = SyntheticPdf::appendUpdate((string) file_get_contents($inputPath), $objects);

        return SyntheticPdf::write($directory, $name.'.pdf', $document);
    }

    /**
     * @return array<int, string>
     */
    private static function inputObjects(string $firstPageEntries): array
    {
        return [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R 6 0 R] /Count 2 >>',
            3 => "<< /Type /Page /Parent 2 0 R {$firstPageEntries} /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
            4 => self::stream('', self::FIRST_PAGE_CONTENT),
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            6 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 7 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            7 => self::stream('', self::SECOND_PAGE_CONTENT),
        ];
    }

    private static function stream(string $entries, string $content): string
    {
        return '<< '.($entries === '' ? '' : $entries.' ').'/Length '.strlen($content)." >>\nstream\n{$content}\nendstream";
    }
}
