<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic;

use SignerPHP\PdfSigningQualification\Tests\Validation\SyntheticSignedPdf;

final class SignatureFieldNamePdf
{
    public const string NON_ASCII_NAME = "\xEF\xBB\xBFAssinatura\xC3\xA7\xC3\xA3o";

    public const string UNDECODABLE_NAME = "Signature\x9F\xAD\x7F";

    public static function nonAsciiName(string $directory): string
    {
        return SyntheticSignedPdf::create($directory, 'non-ascii-field-name', fieldName: self::NON_ASCII_NAME);
    }

    public static function undecodableName(string $directory): string
    {
        return SyntheticSignedPdf::create($directory, 'undecodable-field-name', fieldName: self::UNDECODABLE_NAME);
    }
}
