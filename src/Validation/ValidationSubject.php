<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class ValidationSubject
{
    /**
     * @param  array{sha256: string, byte_size: int}|null  $inputArtifact
     */
    public function __construct(
        public readonly string $attemptId,
        public readonly ?array $inputArtifact,
        public readonly ?string $outputPath,
    ) {}
}
