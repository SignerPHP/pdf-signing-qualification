<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class BaselineSubject
{
    /**
     * @param  array{sha256: string, byte_size: int}  $inputArtifact
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $sourceId,
        public readonly array $inputArtifact,
        public readonly string $inputPath,
    ) {}
}
