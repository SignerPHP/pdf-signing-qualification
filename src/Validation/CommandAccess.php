<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class CommandAccess
{
    /**
     * @param  list<string>  $readablePaths
     * @param  list<string>  $writablePaths
     */
    public function __construct(
        public readonly array $readablePaths = [],
        public readonly array $writablePaths = [],
    ) {}
}
