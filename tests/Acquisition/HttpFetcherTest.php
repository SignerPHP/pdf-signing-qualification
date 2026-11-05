<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Acquisition;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\HttpFetcher;
use SignerPHP\PdfSigningQualification\Acquisition\UpstreamFileMissing;

final class HttpFetcherTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$baseUrl = 'http://'.$address;

        $server = proc_open([PHP_BINARY, '-S', $address, __DIR__.'/HttpFetcherServer.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($server);
        self::$server = $server;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', (int) parse_url(self::$baseUrl, PHP_URL_PORT));
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }

        self::fail('The local HTTP server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    public function test_returns_the_body_of_a_successful_response(): void
    {
        self::assertSame("%PDF-1.7\nserved\n%%EOF\n", (new HttpFetcher)->fetch(self::$baseUrl.'/file.pdf'));
    }

    public function test_follows_redirects(): void
    {
        self::assertSame("%PDF-1.7\nserved\n%%EOF\n", (new HttpFetcher)->fetch(self::$baseUrl.'/redirect.pdf'));
    }

    public function test_not_found_is_a_missing_upstream_file(): void
    {
        $this->expectException(UpstreamFileMissing::class);
        $this->expectExceptionMessage('HTTP 404');

        (new HttpFetcher)->fetch(self::$baseUrl.'/absent.pdf');
    }

    public function test_gone_is_a_missing_upstream_file(): void
    {
        $this->expectException(UpstreamFileMissing::class);
        $this->expectExceptionMessage('HTTP 410');

        (new HttpFetcher)->fetch(self::$baseUrl.'/gone.pdf');
    }

    public function test_server_error_is_not_a_missing_upstream_file(): void
    {
        try {
            (new HttpFetcher)->fetch(self::$baseUrl.'/error.pdf');
            self::fail('A server error was accepted');
        } catch (RuntimeException $exception) {
            self::assertNotInstanceOf(UpstreamFileMissing::class, $exception);
            self::assertStringContainsString('HTTP 500', $exception->getMessage());
        }
    }

    public function test_body_shorter_than_its_content_length_is_rejected(): void
    {
        try {
            (new HttpFetcher)->fetch(self::$baseUrl.'/truncated.pdf');
            self::fail('A truncated body was accepted');
        } catch (RuntimeException $exception) {
            self::assertNotInstanceOf(UpstreamFileMissing::class, $exception);
            self::assertStringContainsString('incomplete', $exception->getMessage());
        }
    }

    public function test_body_interrupted_by_the_timeout_is_rejected(): void
    {
        try {
            (new HttpFetcher(timeoutSeconds: 0.2))->fetch(self::$baseUrl.'/stalled.pdf');
            self::fail('A body interrupted by the timeout was accepted');
        } catch (RuntimeException $exception) {
            self::assertNotInstanceOf(UpstreamFileMissing::class, $exception);
            self::assertStringContainsString('timed out', $exception->getMessage());
        }
    }

    public function test_unreachable_host_is_not_a_missing_upstream_file(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $closedAddress = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        try {
            (new HttpFetcher(timeoutSeconds: 2.0))->fetch('http://'.$closedAddress.'/file.pdf');
            self::fail('An unreachable host was accepted');
        } catch (RuntimeException $exception) {
            self::assertNotInstanceOf(UpstreamFileMissing::class, $exception);
            self::assertStringContainsString('No HTTP response', $exception->getMessage());
        }
    }
}
