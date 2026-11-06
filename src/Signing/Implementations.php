<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

use InvalidArgumentException;

final class Implementations
{
    public const string DEFAULT_ID = 'pdf-signer';

    public static function select(string $id): SigningImplementation
    {
        return match ($id) {
            'pdf-signer' => new PdfSignerImplementation,
            'jsignpdf' => new JSignPdfImplementation,
            default => throw new InvalidArgumentException("Unknown implementation {$id}; available: pdf-signer, jsignpdf"),
        };
    }
}
