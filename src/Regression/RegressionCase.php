<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Regression;

final readonly class RegressionCase
{
    /**
     * @param  array{kind: 'synthetic', generator: string, sha256: string, byte_size: int}|array{kind: 'upstream', source_id: string}  $input
     * @param  array{sha256: string, byte_size: int}  $definition
     */
    public function __construct(
        public string $id,
        public string $scenarioId,
        public array $input,
        public array $definition,
    ) {}
}
