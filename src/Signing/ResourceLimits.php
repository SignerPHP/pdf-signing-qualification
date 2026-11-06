<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

final class ResourceLimits
{
    public function __construct(
        public readonly float $timeoutSeconds = 120.0,
        public readonly int $memoryBytes = 1024 * 1024 * 1024,
        public readonly int $fileSizeBytes = 256 * 1024 * 1024,
        public readonly int $storageBytes = 512 * 1024 * 1024,
        public readonly int $openFiles = 256,
    ) {}

    /**
     * @return array{timeout_seconds: float, address_space_bytes: int, file_size_bytes: int, storage_bytes: int, open_files: int}
     */
    public function record(): array
    {
        return [
            'timeout_seconds' => $this->timeoutSeconds,
            'address_space_bytes' => $this->memoryBytes,
            'file_size_bytes' => $this->fileSizeBytes,
            'storage_bytes' => $this->storageBytes,
            'open_files' => $this->openFiles,
        ];
    }

    /**
     * @return list<string>
     */
    public function prlimitArguments(): array
    {
        return ["--as={$this->memoryBytes}", "--fsize={$this->fileSizeBytes}", "--nofile={$this->openFiles}", '--core=0'];
    }
}
