<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use SignerPHP\PdfSigningQualification\Validation\CommandAccess;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;

final class RecordingCommandRunner implements CommandRunner
{
    /** @var list<array{list<string>, ?CommandAccess}> */
    public array $commands = [];

    public function run(array $command, float $timeoutSeconds, ?CommandAccess $access = null): CommandResult
    {
        $this->commands[] = [$command, $access];

        return (new ProcessRunner)->run($command, $timeoutSeconds, $access);
    }
}
