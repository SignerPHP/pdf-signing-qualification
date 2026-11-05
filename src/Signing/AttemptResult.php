<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

final class AttemptResult
{
    public function __construct(
        public readonly array $record,
        public readonly ?string $outputPath,
    ) {}
}
