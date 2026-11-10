<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Acquisition;

final readonly class Source
{
    /**
     * @param  array{kind: string, value: string}  $revision
     * @param  array{expression: string, evidence_url: string}  $license
     */
    public function __construct(
        public string $id,
        public string $url,
        public array $revision,
        public string $fileUrlTemplate,
        public array $license,
    ) {}

    public function fileUrl(string $path): string
    {
        $encodedPath = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        return strtr($this->fileUrlTemplate, [
            '{revision}' => rawurlencode($this->revision['value']),
            '{path}' => $encodedPath,
        ]);
    }
}
