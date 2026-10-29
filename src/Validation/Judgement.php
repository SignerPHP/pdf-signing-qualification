<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class Judgement
{
    public function __construct(
        public readonly string $verdict,
        public readonly string $summary,
        public readonly ?int $exitCode,
        public readonly string $rawOutput,
        public readonly array $details = [],
    ) {}
}
