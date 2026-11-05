<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Acquisition;

use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\Fetcher;
use SignerPHP\PdfSigningQualification\Acquisition\UpstreamFileMissing;

final class InMemoryFetcher implements Fetcher
{
    /** @var list<string> */
    public array $requestedUrls = [];

    /**
     * @param  array<string, string>  $filesByUrl
     * @param  list<string>  $unreachableUrls
     */
    public function __construct(
        private readonly array $filesByUrl = [],
        private readonly array $unreachableUrls = [],
    ) {}

    public function fetch(string $url): string
    {
        $this->requestedUrls[] = $url;

        if (in_array($url, $this->unreachableUrls, true)) {
            throw new RuntimeException("Connection to {$url} failed");
        }

        return $this->filesByUrl[$url] ?? throw new UpstreamFileMissing("{$url} returned HTTP 404");
    }
}
