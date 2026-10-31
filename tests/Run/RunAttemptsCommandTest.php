<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Acquisition\Acquirer;
use SignerPHP\PdfSigningQualification\Acquisition\SourceConfiguration;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Tests\Acquisition\InMemoryFetcher;
use SignerPHP\PdfSigningQualification\Tests\Validation\SyntheticSignedPdf;
use SignerPHP\PdfSigningQualification\Validation\CommandLineValidator;

final class RunAttemptsCommandTest extends TestCase
{
    private const string RUN_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/run.schema.json';

    private const string PDF_SIGNER_COMMIT = 'f27e4064be107b8a662f370a9acdcecd786da9f7';

    private const string IMAGE_ID = 'sha256:cb78b6ec11b31a78d55de03bfb9a7710495733607537d9e079975701bef4e2da';

    private const array ANALYZER_IDS = ['pyhanko', 'pdfsig', 'openssl-cms', 'qpdf'];

    private string $directory;

    private string $configurationPath;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/run-attempts-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->configurationPath = $this->directory.'/inputs.json';
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    /**
     * @param  array<string, string>  $declaredContents
     * @param  array<string, string>  $servedContents
     * @return list<array>
     */
    private function acquire(array $declaredContents, array $servedContents): array
    {
        $configuration = [
            'schema' => SourceConfiguration::SCHEMA,
            'sources' => [[
                'id' => 'local',
                'url' => 'https://example.org/repository',
                'revision' => ['kind' => 'commit', 'value' => str_repeat('a', 40)],
                'file_url' => 'https://example.org/{revision}/{path}',
                'license' => ['expression' => 'MIT', 'evidence_url' => 'https://example.org/license'],
            ]],
            'inputs' => array_map(static fn (string $path, string $content): array => [
                'source' => 'local',
                'path' => $path,
                'sha256' => hash('sha256', $content),
                'byte_size' => strlen($content),
                'license' => ['expression' => 'MIT', 'evidence_url' => 'https://example.org/license'],
            ], array_keys($declaredContents), $declaredContents),
        ];
        file_put_contents($this->configurationPath, json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $inputs = SourceConfiguration::fromFile($this->configurationPath);
        $filesByUrl = [];
        foreach ($inputs as $input) {
            if (isset($servedContents[$input->path])) {
                $filesByUrl[$input->fileUrl()] = $servedContents[$input->path];
            }
        }
        $acquirer = new Acquirer(new InMemoryFetcher($filesByUrl), $this->directory.'/cache', static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-31T12:00:00Z'));

        return array_map($acquirer->acquire(...), $inputs);
    }

    /**
     * @param  list<array>  $acquisitions
     * @param  array<string, string>  $environment
     * @return array{int, string, string}
     */
    private function runCommand(string $runId, array $acquisitions, array $environment = []): array
    {
        $runDirectory = $this->directory.'/runs/'.$runId;
        mkdir($runDirectory, 0777, true);
        file_put_contents($runDirectory.'/acquisitions.json', json_encode($acquisitions, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/../../bin/run-attempts', $runDirectory, $this->configurationPath, $this->directory.'/cache'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: ['PATH' => (string) getenv('PATH')] + $environment,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errorOutput = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errorOutput];
    }

    private function readRecords(string $runId, string $name): array
    {
        $records = json_decode((string) file_get_contents("{$this->directory}/runs/{$runId}/{$name}"), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($records);

        return $records;
    }

    /**
     * @return array<string, string>
     */
    private function verdicts(string $runId): array
    {
        $attemptSources = array_column(array_map(static fn (array $attempt): array => ['id' => $attempt['id'], 'source_id' => $attempt['input']['source_id']], $this->readRecords($runId, 'attempts.json')), 'source_id', 'id');

        $verdicts = [];
        foreach ($this->readRecords($runId, 'observations.json') as $observation) {
            $verdicts[$attemptSources[$observation['attempt_id']].' '.$observation['analyzer']['id']] = $observation['verdict'];
        }

        return $verdicts;
    }

    public function test_every_declared_input_gets_schema_valid_records_and_a_second_run_gives_the_same_verdicts(): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $acquisitions = $this->acquire(
            ['unsigned.pdf' => $unsignedPdf, 'not-a-pdf.pdf' => "%PDF-1.7\nthis is not a PDF body\n", 'gone.pdf' => 'never served'],
            ['unsigned.pdf' => $unsignedPdf, 'not-a-pdf.pdf' => "%PDF-1.7\nthis is not a PDF body\n"],
        );

        [$exitCode, $output, $errorOutput] = $this->runCommand('run-20261031T120000Z', $acquisitions, ['QUALIFICATION_IMAGE_ID' => self::IMAGE_ID]);

        self::assertSame(0, $exitCode, $errorOutput);
        $validator = new SchemaValidator;

        $run = $this->readRecords('run-20261031T120000Z', 'run.json');
        self::assertSame([], $validator->errors($run, self::RUN_SCHEMA));
        self::assertSame('run-20261031T120000Z', $run['id']);
        self::assertSame('basic-invisible-sha256', $run['scenario_id']);
        self::assertSame(['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => self::PDF_SIGNER_COMMIT]], $run['implementation']);
        self::assertSame(self::ANALYZER_IDS, array_column($run['analyzers'], 'id'));
        self::assertSame(['status' => 'available', 'id' => self::IMAGE_ID], $run['environment']['container_image']);
        self::assertSame(PHP_VERSION, $run['environment']['php_version']);
        self::assertSame(['sha256' => hash_file('sha256', $this->configurationPath), 'byte_size' => filesize($this->configurationPath)], $run['source_configuration']);

        $attempts = $this->readRecords('run-20261031T120000Z', 'attempts.json');
        self::assertSame(['local:unsigned.pdf', 'local:not-a-pdf.pdf', 'local:gone.pdf'], array_column(array_column($attempts, 'input'), 'source_id'));
        foreach ($attempts as $attempt) {
            self::assertSame([], $validator->errors($attempt, AttemptRunner::ATTEMPT_SCHEMA));
            self::assertSame('run-20261031T120000Z', $attempt['run_id']);
        }
        self::assertSame(['succeeded', 'failed', 'not_run'], array_column(array_column($attempts, 'execution'), 'outcome'));
        self::assertFileExists("{$this->directory}/runs/run-20261031T120000Z/outputs/{$attempts[0]['output']['sha256']}.pdf");

        $observations = $this->readRecords('run-20261031T120000Z', 'observations.json');
        self::assertCount(3 * count(self::ANALYZER_IDS), $observations);
        foreach ($observations as $observation) {
            self::assertSame([], $validator->errors($observation, CommandLineValidator::OBSERVATION_SCHEMA));
        }
        $expectedVerdicts = [];
        foreach (['local:unsigned.pdf' => 'valid', 'local:not-a-pdf.pdf' => 'not_evaluated', 'local:gone.pdf' => 'not_evaluated'] as $sourceId => $verdict) {
            foreach (self::ANALYZER_IDS as $analyzerId) {
                $expectedVerdicts["{$sourceId} {$analyzerId}"] = $verdict;
            }
        }
        self::assertSame($expectedVerdicts, $this->verdicts('run-20261031T120000Z'));

        $summary = (string) file_get_contents("{$this->directory}/runs/run-20261031T120000Z/summary.md");
        self::assertSame($summary, $output);
        self::assertStringContainsString("| `failed` | 1 |\n", $summary);
        self::assertStringContainsString('| `local:not-a-pdf.pdf` | `supported` | `failed` | pdf-signer exited with code 1', $summary);

        [$secondExitCode, , $secondErrorOutput] = $this->runCommand('run-20261031T130000Z', $acquisitions);

        self::assertSame(0, $secondExitCode, $secondErrorOutput);
        self::assertSame($expectedVerdicts, $this->verdicts('run-20261031T130000Z'));
        self::assertSame('unavailable', $this->readRecords('run-20261031T130000Z', 'run.json')['environment']['container_image']['status']);
    }

    public function test_run_stops_before_signing_when_a_declared_input_has_no_acquisition_record(): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $acquisitions = $this->acquire(['unsigned.pdf' => $unsignedPdf, 'second.pdf' => $unsignedPdf."\n"], ['unsigned.pdf' => $unsignedPdf, 'second.pdf' => $unsignedPdf."\n"]);

        [$exitCode, , $errorOutput] = $this->runCommand('run-20261031T120000Z', [$acquisitions[0]]);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('local:second.pdf', $errorOutput);
        self::assertFileDoesNotExist("{$this->directory}/runs/run-20261031T120000Z/attempts.json");
    }
}
