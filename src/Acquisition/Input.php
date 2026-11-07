<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Acquisition;

final readonly class Input
{
    /**
     * @param  array{expression: string, evidence_url: string}  $license
     * @param  list<string>  $strata
     */
    public function __construct(
        public Source $source,
        public string $path,
        public string $sha256,
        public int $byteSize,
        public array $license,
        public array $strata,
    ) {}

    public function id(): string
    {
        return $this->source->id.':'.$this->path;
    }

    public function fileUrl(): string
    {
        return $this->source->fileUrl($this->path);
    }
}
