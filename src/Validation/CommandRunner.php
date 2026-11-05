<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

interface CommandRunner
{
    /**
     * @param  list<string>  $command
     */
    public function run(array $command, float $timeoutSeconds): CommandResult;
}
