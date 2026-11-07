<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Regression;

interface RegressionCondition
{
    /**
     * @return array{status: 'present'|'absent'|'undetermined', evidence: string}
     */
    public function evaluate(string $inputPath, string $outputPath): array;
}
