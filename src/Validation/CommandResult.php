<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class CommandResult
{
    public function __construct(
        public readonly ?int $exitCode,
        public readonly string $output,
        public readonly bool $timedOut = false,
        public readonly ?int $terminationSignal = null,
    ) {}

    public function describe(): string
    {
        return match (true) {
            $this->timedOut => 'did not finish in time',
            $this->terminationSignal !== null => "was terminated by signal {$this->terminationSignal}",
            $this->exitCode === null => 'ended without an exit code',
            default => "exited with code {$this->exitCode}",
        };
    }
}
