<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Acquisition;

final readonly class Input
{
    /**
     * @param  array{expression: string, evidence_url: string}  $license
     */
    public function __construct(
        public Source $source,
        public string $path,
        public string $sha256,
        public int $byteSize,
        public array $license,
    ) {}

    public function id(): string
    {
        return $this->source->id.':'.$this->path;
    }

    public function fileUrl(): string
    {
        $encodedPath = implode('/', array_map(rawurlencode(...), explode('/', $this->path)));

        return strtr($this->source->fileUrlTemplate, [
            '{revision}' => rawurlencode($this->source->revision['value']),
            '{path}' => $encodedPath,
        ]);
    }
}
