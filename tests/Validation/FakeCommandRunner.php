<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;

final class FakeCommandRunner implements CommandRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function __construct(
        private readonly CommandResult $versionResult,
        private readonly CommandResult $validationResult,
    ) {}

    public function run(array $command, float $timeoutSeconds): CommandResult
    {
        $this->commands[] = $command;

        return in_array('--version', $command, true) || in_array('-v', $command, true) || $command === ['openssl', 'version']
            ? $this->versionResult
            : $this->validationResult;
    }
}
