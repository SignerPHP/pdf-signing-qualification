<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Regression;

use InvalidArgumentException;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;

final class RegressionConditions
{
    public function __construct(
        private readonly CommandRunner $runner,
    ) {}

    public function for(string $caseId): RegressionCondition
    {
        return match ($caseId) {
            'hybrid-xref' => new HybridXrefCondition($this->runner),
            default => throw new InvalidArgumentException("The regression case {$caseId} has no condition check; add one to ".self::class),
        };
    }
}
