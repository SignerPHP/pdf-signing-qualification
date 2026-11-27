<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Acquisition;

final readonly class Origin
{
    public function __construct(
        public Source $source,
        public string $path,
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
