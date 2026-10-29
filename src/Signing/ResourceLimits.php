<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

final class ResourceLimits
{
    public function __construct(
        public readonly float $timeoutSeconds = 120.0,
        public readonly int $memoryBytes = 1024 * 1024 * 1024,
        public readonly int $fileSizeBytes = 256 * 1024 * 1024,
        public readonly int $openFiles = 256,
    ) {}

    /**
     * @return list<string>
     */
    public function prlimitArguments(): array
    {
        return ["--as={$this->memoryBytes}", "--fsize={$this->fileSizeBytes}", "--nofile={$this->openFiles}", '--core=0'];
    }
}
