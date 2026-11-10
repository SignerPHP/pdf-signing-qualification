<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Performance;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Performance\Protocol;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class PlanPerformanceCommandTest extends TestCase
{
    private const string PROTOCOL = __DIR__.'/../../config/performance/protocol.json';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/plan-performance-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    /**
     * @return array{int, string, string}
     */
    private function planPerformance(string ...$arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/../../bin/plan-performance', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: ['PATH' => (string) getenv('PATH')],
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errorOutput = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errorOutput];
    }

    public function test_session_plan_is_written_with_the_protocol_and_printed_one_run_per_line(): void
    {
        [$exitCode, $output, $errorOutput] = $this->planPerformance(self::PROTOCOL, '1', $this->directory);

        self::assertSame(0, $exitCode, $errorOutput);
        self::assertSame(file_get_contents(self::PROTOCOL), file_get_contents($this->directory.'/protocol.json'));
        $study = json_decode((string) file_get_contents($this->directory.'/study.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], (new SchemaValidator)->errors($study, Protocol::STUDY_SCHEMA));
        self::assertSame(basename($this->directory), $study['id']);
        $lines = array_map(static fn (array $run): string => "{$run['label']} {$run['implementation_id']} {$run['role']}", $study['runs']);
        self::assertSame(implode("\n", $lines)."\n", $output);
        self::assertSame(Protocol::fromFile(self::PROTOCOL)->plan(1), $study['runs']);
    }

    public function test_session_already_planned_is_not_planned_again(): void
    {
        $this->planPerformance(self::PROTOCOL, '1', $this->directory);

        [$exitCode, , $errorOutput] = $this->planPerformance(self::PROTOCOL, '1', $this->directory);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('without a study.json or protocol.json', $errorOutput);
    }

    public function test_invalid_session_is_refused_without_writing_a_plan(): void
    {
        [$exitCode, , $errorOutput] = $this->planPerformance(self::PROTOCOL, 'first', $this->directory);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('The session must be calibration or a positive number', $errorOutput);
        self::assertFileDoesNotExist($this->directory.'/study.json');
    }
}
