<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic;

final class MinimalPdf
{
    public static function create(string $directory): string
    {
        return SyntheticPdf::write($directory, 'minimal.pdf', SyntheticPdf::classic(SyntheticPdf::onePage()));
    }
}
