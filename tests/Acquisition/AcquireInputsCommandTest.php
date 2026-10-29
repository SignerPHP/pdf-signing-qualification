<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Acquisition;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Acquisition\Acquirer;
use SignerPHP\PdfSigningQualification\Acquisition\SourceConfiguration;

final class AcquireInputsCommandTest extends TestCase
{
    private const string CONTENT = "%PDF-1.7\nsynthetic command test content\n%%EOF\n";

    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir().'/acquire-inputs-test-'.bin2hex(random_bytes(8));
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->workDirectory));
    }

    private static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($address, strrpos($address, ':') + 1);
    }

    private static function inputDeclaration(string $path, string $content): array
    {
        return [
            'source' => 'local',
            'path' => $path,
            'sha256' => hash('sha256', $content),
            'byte_size' => strlen($content),
            'license' => ['expression' => 'NOASSERTION', 'evidence_url' => 'https://example.org/evidence'],
        ];
    }

    /**
     * @return array{int, string, string}
     */
    private function runCommand(array $configuration): array
    {
        $configurationPath = $this->workDirectory.'/inputs.json';
        file_put_contents($configurationPath, json_encode($configuration, JSON_UNESCAPED_SLASHES));

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/../../bin/acquire-inputs', $configurationPath, $this->workDirectory.'/cache'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errorOutput = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errorOutput];
    }

    public function test_aborted_acquisition_still_reports_the_records_collected_before_it(): void
    {
        $port = self::closedPort();
        $configuration = [
            'schema' => SourceConfiguration::SCHEMA,
            'sources' => [[
                'id' => 'local',
                'url' => "https://127.0.0.1:{$port}/repository",
                'revision' => ['kind' => 'commit', 'value' => str_repeat('a', 40)],
                'file_url' => "https://127.0.0.1:{$port}/{revision}/{path}",
                'license' => ['expression' => 'MIT', 'evidence_url' => 'https://example.org/license'],
            ]],
            'inputs' => [
                self::inputDeclaration('cached.pdf', self::CONTENT),
                self::inputDeclaration('unreachable.pdf', 'never served'),
            ],
        ];
        $cachedInput = SourceConfiguration::fromArray($configuration)[0];
        $acquirer = new Acquirer(
            new InMemoryFetcher([$cachedInput->fileUrl() => self::CONTENT]),
            $this->workDirectory.'/cache',
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-29T12:00:00Z'),
        );
        $acquirer->acquire($cachedInput);

        [$exitCode, $output, $errorOutput] = $this->runCommand($configuration);

        self::assertSame(2, $exitCode);
        $records = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($records);
        self::assertCount(1, $records);
        self::assertSame('local:cached.pdf', $records[0]['source_id']);
        self::assertStringContainsString('local:unreachable.pdf', $errorOutput);
        self::assertStringContainsString('No HTTP response', $errorOutput);
    }
}
