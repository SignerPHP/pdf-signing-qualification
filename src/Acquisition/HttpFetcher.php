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

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException("No HTTP response from {$url}: ".(error_get_last()['message'] ?? 'unknown error'));
        }

        try {
            $content = stream_get_contents($stream);
            $metadata = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }

        /** @var list<string> $headers */
        $headers = $metadata['wrapper_data'] ?? [];
        [$statusCode, $contentLength] = self::lastResponse($headers);

        if ($statusCode === null) {
            throw new RuntimeException("No HTTP response from {$url}");
        }
        if ($statusCode === 404 || $statusCode === 410) {
            throw new UpstreamFileMissing("{$url} returned HTTP {$statusCode}");
        }
        if ($statusCode !== 200 || $content === false) {
            throw new RuntimeException("{$url} returned HTTP {$statusCode}");
        }
        if ($metadata['timed_out']) {
            throw new RuntimeException("Reading {$url} timed out after ".strlen($content).' bytes');
        }
        if ($contentLength !== null && strlen($content) !== $contentLength) {
            throw new RuntimeException("Response from {$url} is incomplete: received ".strlen($content)." of {$contentLength} bytes");
        }

        return $content;
    }

    /**
     * @param  list<string>  $headers
     * @return array{?int, ?int}
     */
    private static function lastResponse(array $headers): array
    {
        $statusCode = null;
        $contentLength = null;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $statusCode = (int) $matches[1];
                $contentLength = null;
            } elseif (preg_match('#^Content-Length:\s*(\d+)\s*$#i', $header, $matches) === 1) {
                $contentLength = (int) $matches[1];
            }
        }

        return [$statusCode, $contentLength];
    }
}
