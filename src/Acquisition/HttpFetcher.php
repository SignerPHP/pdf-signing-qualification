<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Acquisition;

use RuntimeException;

final class HttpFetcher implements Fetcher
{
    public function __construct(
        private readonly float $timeoutSeconds = 60.0,
    ) {}

    public function fetch(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 5,
                'timeout' => $this->timeoutSeconds,
                'user_agent' => 'signerphp-pdf-signing-qualification',
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        $statusCode = self::lastStatusCode(http_get_last_response_headers() ?? []);

        if ($statusCode === null) {
            throw new RuntimeException("No HTTP response from {$url}: ".(error_get_last()['message'] ?? 'unknown error'));
        }
        if ($statusCode === 404 || $statusCode === 410) {
            throw new UpstreamFileMissing("{$url} returned HTTP {$statusCode}");
        }
        if ($statusCode !== 200 || $content === false) {
            throw new RuntimeException("{$url} returned HTTP {$statusCode}");
        }

        return $content;
    }

    /**
     * @param  list<string>  $headers
     */
    private static function lastStatusCode(array $headers): ?int
    {
        $statusCode = null;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $statusCode = (int) $matches[1];
            }
        }

        return $statusCode;
    }
}
