<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Acquisition\Acquirer;
use SignerPHP\PdfSigningQualification\Acquisition\SourceConfiguration;
use SignerPHP\PdfSigningQualification\Regression\RegressionReplay;
use SignerPHP\PdfSigningQualification\Run\ExpectedRejections;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Tests\Acquisition\InMemoryFetcher;
use SignerPHP\PdfSigningQualification\Tests\Validation\SyntheticSignedPdf;
use SignerPHP\PdfSigningQualification\Tests\Validation\SyntheticVisibleSignature;
use SignerPHP\PdfSigningQualification\Validation\CommandLineValidator;

final class RunAttemptsCommandTest extends TestCase
{
    private const string RUN_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/run.schema.json';

    private const string PDF_SIGNER_COMMIT = 'f27e4064be107b8a662f370a9acdcecd786da9f7';

    private const string IMAGE_ID = 'sha256:cb78b6ec11b31a78d55de03bfb9a7710495733607537d9e079975701bef4e2da';

    private const string REGRESSION_CASE_PATH = __DIR__.'/../../config/regressions/hybrid-xref.json';

    private const array ANALYZER_IDS = ['pyhanko', 'pdfsig', 'openssl-cms', 'qpdf', 'cmp', 'qpdf-prior-signatures', 'qpdf-byte-range'];

    private string $directory;

    private string $configurationPath;

    private string $expectedRejectionsPath;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/run-attempts-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->configurationPath = $this->directory.'/inputs.json';
        $this->expectedRejectionsPath = $this->directory.'/expected-rejections.json';
        $this->declareExpectedRejections([]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    /**
     * @param  list<string>  $sourceIds
     */
    private function declareExpectedRejections(array $sourceIds, string $scenarioId = 'basic-invisible-sha256'): void
    {
        file_put_contents($this->expectedRejectionsPath, json_encode([
            'schema' => ExpectedRejections::SCHEMA,
            'rejections' => array_map(static fn (string $sourceId): array => ['source_id' => $sourceId, 'scenario_id' => $scenarioId, 'reason' => 'Not a PDF'], $sourceIds),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
            'selection' => 'failure_enriched',
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
                'strata' => ['classic'],
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
     * @param  list<string>  $options
     * @return array{int, string, string}
     */
    private function runCommand(string $runId, array $acquisitions, array $environment = [], array $options = [], bool $passExpectedRejections = true): array
    {
        $runDirectory = $this->directory.'/runs/'.$runId;
        if (! is_dir($runDirectory)) {
            mkdir($runDirectory, 0777, true);
        }
        file_put_contents($runDirectory.'/acquisitions.json', json_encode($acquisitions, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/../../bin/run-attempts', ...$options, $runDirectory, $this->configurationPath, $this->directory.'/cache', ...($passExpectedRejections ? [$this->expectedRejectionsPath] : [])],
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
        self::assertFileEquals($this->configurationPath, "{$this->directory}/runs/run-20261031T120000Z/inputs.json");
        self::assertSame(['sha256' => hash_file('sha256', $this->expectedRejectionsPath), 'byte_size' => filesize($this->expectedRejectionsPath)], $run['expected_rejections']);
        self::assertSame(['status' => 'compatible'], $run['implementation_compatibility']);

        $regressions = $this->readRecords('run-20261031T120000Z', 'regressions.json');
        self::assertSame([], $validator->errors($regressions, RegressionReplay::SCHEMA));
        self::assertSame('run-20261031T120000Z', $regressions['run_id']);
        self::assertSame(['hybrid-xref'], array_column($regressions['cases'], 'case_id'));
        self::assertSame('present', $regressions['cases'][0]['condition']['status'], $regressions['cases'][0]['condition']['evidence']);
        self::assertSame('The final trailer of the output has /XRefStm 445, inside the 721 bytes of the input', $regressions['cases'][0]['condition']['evidence']);
        self::assertSame(['sha256' => hash_file('sha256', self::REGRESSION_CASE_PATH), 'byte_size' => filesize(self::REGRESSION_CASE_PATH)], $regressions['cases'][0]['definition']);
        self::assertSame('run-20261031T120000Z/basic-invisible-sha256/pdf-signer/regression:hybrid-xref.pdf', $regressions['cases'][0]['attempt_id']);
        self::assertSame(['succeeded'], array_column(array_column($regressions['attempts'], 'execution'), 'outcome'));
        self::assertSame(self::ANALYZER_IDS, array_map(static fn (array $observation): string => $observation['analyzer']['id'], $regressions['observations']));

        $attempts = $this->readRecords('run-20261031T120000Z', 'attempts.json');
        self::assertSame(['local:unsigned.pdf', 'local:not-a-pdf.pdf', 'local:gone.pdf'], array_column(array_column($attempts, 'input'), 'source_id'));
        foreach ($attempts as $attempt) {
            self::assertSame([], $validator->errors($attempt, AttemptRunner::ATTEMPT_SCHEMA));
            self::assertSame('run-20261031T120000Z', $attempt['run_id']);
        }
        self::assertSame(['succeeded', 'failed', 'not_run'], array_column(array_column($attempts, 'execution'), 'outcome'));
        self::assertFileExists("{$this->directory}/runs/run-20261031T120000Z/outputs/{$attempts[0]['output']['sha256']}.pdf");
        self::assertSame(['status' => 'available'], array_intersect_key($run['environment']['cpu_model'], ['status' => true]));

        $measurements = $this->readRecords('run-20261031T120000Z', 'measurements.json');
        self::assertSame([$attempts[0]['id'], $attempts[1]['id']], array_column($measurements, 'attempt_id'));
        self::assertSame(['succeeded', 'failed'], array_column($measurements, 'execution_outcome'));
        foreach ($measurements as $measurement) {
            self::assertSame([], $validator->errors($measurement, AttemptRunner::MEASUREMENT_SCHEMA));
            self::assertSame(['status' => 'available'], array_intersect_key($measurement['metrics']['peak_memory_bytes'], ['status' => true]));
        }

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
        $expectedVerdicts['local:unsigned.pdf qpdf-prior-signatures'] = 'not_applicable';
        self::assertSame($expectedVerdicts, $this->verdicts('run-20261031T120000Z'));

        $summary = (string) file_get_contents("{$this->directory}/runs/run-20261031T120000Z/summary.md");
        self::assertSame($summary, $output);
        self::assertStringContainsString("| `failed` | 1 |\n", $summary);
        self::assertStringContainsString('| `local:not-a-pdf.pdf` | `supported` | `failed` | pdf-signer exited with code 1', $summary);
        self::assertStringContainsString('| `hybrid-xref` | `regression:hybrid-xref.pdf` | `succeeded` | `present` | The final trailer of the output has /XRefStm 445, inside the 721 bytes of the input | `pyhanko` `inconclusive`, `pdfsig` `valid`', $summary);
        self::assertStringNotContainsString('regression:hybrid-xref.pdf` | `pyhanko`', $summary);

        [$secondExitCode, , $secondErrorOutput] = $this->runCommand('run-20261031T130000Z', $acquisitions);

        self::assertSame(0, $secondExitCode, $secondErrorOutput);
        self::assertSame($expectedVerdicts, $this->verdicts('run-20261031T130000Z'));
        self::assertSame('unavailable', $this->readRecords('run-20261031T130000Z', 'run.json')['environment']['container_image']['status']);
    }

    public function test_every_available_input_gets_one_baseline_per_analyzer_before_signing(): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $signedPath = SyntheticSignedPdf::create($this->directory);
        $signedPdf = (string) file_get_contents($signedPath);
        $tamperedPdf = (string) file_get_contents(SyntheticSignedPdf::tamperSignedRange($signedPath, $this->directory));
        $acquisitions = $this->acquire(
            ['unsigned.pdf' => $unsignedPdf, 'signed.pdf' => $signedPdf, 'tampered.pdf' => $tamperedPdf, 'gone.pdf' => 'never served'],
            ['unsigned.pdf' => $unsignedPdf, 'signed.pdf' => $signedPdf, 'tampered.pdf' => $tamperedPdf],
        );

        [$exitCode, $output, $errorOutput] = $this->runCommand('run-baseline', $acquisitions);

        self::assertSame(0, $exitCode, $errorOutput);
        $baselines = $this->readRecords('run-baseline', 'baselines.json');
        foreach ($baselines as $baseline) {
            self::assertSame([], (new SchemaValidator)->errors($baseline, CommandLineValidator::BASELINE_SCHEMA));
        }
        $verdicts = [];
        foreach ($baselines as $baseline) {
            $verdicts[$baseline['source_id']][$baseline['analyzer']['id']] = $baseline['verdict'];
        }
        self::assertSame([
            'local:unsigned.pdf' => ['pyhanko' => 'not_applicable', 'pdfsig' => 'not_applicable', 'openssl-cms' => 'not_applicable', 'qpdf' => 'valid'],
            'local:signed.pdf' => ['pyhanko' => 'valid', 'pdfsig' => 'valid', 'openssl-cms' => 'valid', 'qpdf' => 'valid'],
            'local:tampered.pdf' => ['pyhanko' => 'invalid', 'pdfsig' => 'invalid', 'openssl-cms' => 'invalid', 'qpdf' => 'valid'],
        ], $verdicts);

        $outputVerdicts = $this->verdicts('run-baseline');
        self::assertSame(['valid', 'valid', 'valid'], [$outputVerdicts['local:signed.pdf cmp'], $outputVerdicts['local:signed.pdf qpdf-prior-signatures'], $outputVerdicts['local:signed.pdf qpdf-byte-range']]);
        self::assertSame('invalid', $outputVerdicts['local:tampered.pdf pdfsig']);
        self::assertStringContainsString('`cmp`, `qpdf-prior-signatures` and `qpdf-byte-range` compare the output with its input, so they have no baseline.', $output);
        self::assertSame('valid', $outputVerdicts['local:unsigned.pdf pdfsig']);
        self::assertSame('invalid', $outputVerdicts['local:tampered.pdf pdfsig']);
        self::assertStringContainsString("| `local:unsigned.pdf` | `not_applicable` → `valid` | `not_applicable` → `valid` | `not_applicable` → `valid` | `valid` → `valid` |\n", $output);
        self::assertStringContainsString("| `local:tampered.pdf` | `invalid` → `invalid` | `invalid` → `invalid` | `invalid` → `invalid` | `valid` → `valid` |\n", $output);
        self::assertStringContainsString("| `local:gone.pdf` | `missing_upstream` |\n", $output);
    }

    public function test_input_changed_in_the_cache_stops_the_run_before_any_baseline(): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $acquisitions = $this->acquire(['unsigned.pdf' => $unsignedPdf], ['unsigned.pdf' => $unsignedPdf]);
        file_put_contents($this->directory.'/cache/files/local/unsigned.pdf', $unsignedPdf."\n%changed\n");

        [$exitCode, , $errorOutput] = $this->runCommand('run-changed-input', $acquisitions);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('does not match its acquisition record for local:unsigned.pdf', $errorOutput);
        self::assertFileDoesNotExist("{$this->directory}/runs/run-changed-input/baselines.json");
        self::assertFileDoesNotExist("{$this->directory}/runs/run-changed-input/inputs.json");
    }

    public function test_copy_of_the_configuration_in_the_run_directory_can_be_the_configuration_itself(): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $acquisitions = $this->acquire(['unsigned.pdf' => $unsignedPdf], ['unsigned.pdf' => $unsignedPdf]);
        $configuration = (string) file_get_contents($this->configurationPath);
        mkdir($this->directory.'/runs/run-own-copy', 0777, true);
        $this->configurationPath = $this->directory.'/runs/run-own-copy/inputs.json';
        file_put_contents($this->configurationPath, $configuration);

        [$exitCode, , $errorOutput] = $this->runCommand('run-own-copy', $acquisitions);

        self::assertSame(0, $exitCode, $errorOutput);
        self::assertStringEqualsFile($this->configurationPath, $configuration);
        self::assertSame(['sha256' => hash('sha256', $configuration), 'byte_size' => strlen($configuration)], $this->readRecords('run-own-copy', 'run.json')['source_configuration']);
    }

    public function test_declared_input_is_an_expected_rejection_and_the_others_stay_supported(): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $acquisitions = $this->acquire(
            ['unsigned.pdf' => $unsignedPdf, 'not-a-pdf.pdf' => "%PDF-1.7\nthis is not a PDF body\n", 'gone.pdf' => 'never served'],
            ['unsigned.pdf' => $unsignedPdf, 'not-a-pdf.pdf' => "%PDF-1.7\nthis is not a PDF body\n"],
        );
        $this->declareExpectedRejections(['local:not-a-pdf.pdf', 'local:gone.pdf']);

        [$exitCode, $output, $errorOutput] = $this->runCommand('run-expected-rejection', $acquisitions);

        self::assertSame(0, $exitCode, $errorOutput);
        $attempts = $this->readRecords('run-expected-rejection', 'attempts.json');
        self::assertSame(['supported', 'expected_rejection', 'not_evaluated'], array_column($attempts, 'capability'));
        self::assertSame(['succeeded', 'failed', 'not_run'], array_column(array_column($attempts, 'execution'), 'outcome'));
        self::assertStringContainsString("| `failed` | 0 |\n", $output);
        self::assertStringContainsString("| Rejected as expected | 1 |\n", $output);
        self::assertStringContainsString('| `local:not-a-pdf.pdf` | pdf-signer exited with code 1', $output);
    }

    public function test_declaration_for_an_undeclared_input_stops_the_run_before_signing(): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $acquisitions = $this->acquire(['unsigned.pdf' => $unsignedPdf], ['unsigned.pdf' => $unsignedPdf]);
        $this->declareExpectedRejections(['local:gone.pdf']);

        [$exitCode, , $errorOutput] = $this->runCommand('run-undeclared-input', $acquisitions);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('undeclared input local:gone.pdf', $errorOutput);
        self::assertFileDoesNotExist($this->directory.'/runs/run-undeclared-input/attempts.json');
    }

    public function test_declaration_for_an_undeclared_scenario_stops_the_run_before_signing(): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $acquisitions = $this->acquire(['unsigned.pdf' => $unsignedPdf], ['unsigned.pdf' => $unsignedPdf]);
        $this->declareExpectedRejections(['local:unsigned.pdf'], 'unknown-scenario');

        [$exitCode, , $errorOutput] = $this->runCommand('run-undeclared-scenario', $acquisitions);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('undeclared scenario unknown-scenario', $errorOutput);
        self::assertFileDoesNotExist($this->directory.'/runs/run-undeclared-scenario/attempts.json');
    }

    public function test_another_input_configuration_needs_its_own_declarations(): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $acquisitions = $this->acquire(['unsigned.pdf' => $unsignedPdf], ['unsigned.pdf' => $unsignedPdf]);

        [$exitCode, , $errorOutput] = $this->runCommand('run-versioned-declarations', $acquisitions, passExpectedRejections: false);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('config/expected-rejections.json refers to the undeclared input qpdf:qpdf/qtest/qpdf/bad1.pdf', $errorOutput);
        self::assertFileDoesNotExist($this->directory.'/runs/run-versioned-declarations/attempts.json');
    }

    public function test_run_selects_its_implementation_and_records_java_and_the_memory_bound(): void
    {
        $unsignedPdf = (string) preg_replace('/\A%PDF-\d\.\d/', '%PDF-1.7', (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory)));
        $acquisitions = $this->acquire(['unsigned.pdf' => $unsignedPdf], ['unsigned.pdf' => $unsignedPdf]);

        [$exitCode, , $errorOutput] = $this->runCommand('run-jsignpdf', $acquisitions, options: ['--implementation=jsignpdf']);

        self::assertSame(0, $exitCode, $errorOutput);
        $run = $this->readRecords('run-jsignpdf', 'run.json');
        self::assertSame([], (new SchemaValidator)->errors($run, self::RUN_SCHEMA));
        self::assertSame(['id' => 'jsignpdf', 'revision' => ['kind' => 'version', 'value' => '3.2.1']], $run['implementation']);
        self::assertSame('21.0.13+9-LTS', $run['implementation_runtime']['java_version']);
        self::assertSame(1024 * 1024 * 1024, $run['environment']['resource_limits']['address_space_bytes']);
        self::assertSame('succeeded', $this->readRecords('run-jsignpdf', 'attempts.json')[0]['execution']['outcome']);
        self::assertSame(['status' => 'not_checked'], $run['implementation_compatibility']);
        self::assertSame(['hybrid-xref'], array_column($this->readRecords('run-jsignpdf', 'regressions.json')['cases'], 'case_id'));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'misspelled option' => [['--implementaton=jsignpdf']];
        yield 'repeated option' => [['--implementation=jsignpdf', '--implementation=pdf-signer']];
        yield 'option without a value' => [['--implementation']];
        yield 'repeated scenario' => [['--scenario=basic-visible-sha256', '--scenario=basic-invisible-sha256']];
        yield 'scenario without a value' => [['--scenario']];
        yield 'option after the run directory' => [['--scenario=basic-visible-sha256', '--implementation=jsignpdf', '--']];
    }

    /**
     * @param  list<string>  $options
     */
    #[DataProvider('invalidOptions')]
    public function test_invalid_options_stop_the_run_before_signing(array $options): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $acquisitions = $this->acquire(['unsigned.pdf' => $unsignedPdf], ['unsigned.pdf' => $unsignedPdf]);

        [$exitCode, , $errorOutput] = $this->runCommand('run-invalid-options', $acquisitions, options: $options);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('Usage: run-attempts', $errorOutput);
        self::assertFileDoesNotExist($this->directory.'/runs/run-invalid-options/attempts.json');
    }

    public function test_run_selects_its_scenario_and_adds_the_visual_analyzer_for_a_visible_appearance(): void
    {
        $inputPdf = (string) file_get_contents(SyntheticVisibleSignature::input($this->directory));
        $acquisitions = $this->acquire(['visible-input.pdf' => $inputPdf], ['visible-input.pdf' => $inputPdf]);
        $this->declareExpectedRejections([], 'basic-visible-sha256');

        [$exitCode, , $errorOutput] = $this->runCommand('run-visible', $acquisitions, options: ['--implementation=jsignpdf', '--scenario=basic-visible-sha256']);

        self::assertSame(0, $exitCode, $errorOutput);
        $run = $this->readRecords('run-visible', 'run.json');
        self::assertSame([], (new SchemaValidator)->errors($run, self::RUN_SCHEMA));
        self::assertSame('basic-visible-sha256', $run['scenario_id']);
        self::assertSame([...self::ANALYZER_IDS, 'pdftoppm-appearance'], array_column($run['analyzers'], 'id'));
        self::assertSame('jsignpdf', $run['implementation']['id']);
        $attempts = $this->readRecords('run-visible', 'attempts.json');
        self::assertSame('basic-visible-sha256', $attempts[0]['scenario_id']);
        self::assertSame('valid', $this->verdicts('run-visible')['local:visible-input.pdf pdftoppm-appearance']);
        self::assertSame('not_evaluated', $this->readRecords('run-visible', 'regressions.json')['cases'][0]['condition']['status']);
    }

    public function test_run_with_an_undeclared_scenario_stops_before_signing(): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $acquisitions = $this->acquire(['unsigned.pdf' => $unsignedPdf], ['unsigned.pdf' => $unsignedPdf]);

        [$exitCode, , $errorOutput] = $this->runCommand('run-undeclared-scenario', $acquisitions, options: ['--scenario=basic-visible-sha512']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('The scenario basic-visible-sha512 is not declared in', $errorOutput);
        self::assertFileDoesNotExist($this->directory.'/runs/run-undeclared-scenario/attempts.json');
    }

    public function test_run_with_an_unknown_implementation_stops_before_signing(): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $acquisitions = $this->acquire(['unsigned.pdf' => $unsignedPdf], ['unsigned.pdf' => $unsignedPdf]);

        [$exitCode, , $errorOutput] = $this->runCommand('run-unknown', $acquisitions, options: ['--implementation=unknown-signer']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Unknown implementation unknown-signer', $errorOutput);
        self::assertFileDoesNotExist($this->directory.'/runs/run-unknown/run.json');
        self::assertFileDoesNotExist($this->directory.'/runs/run-unknown/attempts.json');
    }

    public function test_run_stops_before_signing_when_a_declared_input_has_no_acquisition_record(): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $acquisitions = $this->acquire(['unsigned.pdf' => $unsignedPdf, 'second.pdf' => $unsignedPdf."\n"], ['unsigned.pdf' => $unsignedPdf, 'second.pdf' => $unsignedPdf."\n"]);

        $scratchDirectoriesBefore = glob('/sandbox/run-*') ?: [];

        [$exitCode, , $errorOutput] = $this->runCommand('run-20261031T120000Z', [$acquisitions[0]]);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('local:second.pdf', $errorOutput);
        self::assertFileDoesNotExist("{$this->directory}/runs/run-20261031T120000Z/attempts.json");
        self::assertFileDoesNotExist("{$this->directory}/runs/run-20261031T120000Z/run.json");
        self::assertSame($scratchDirectoriesBefore, glob('/sandbox/run-*') ?: []);
    }

    public function test_run_rejects_an_acquisition_record_that_does_not_follow_its_schema(): void
    {
        $unsignedPdf = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $acquisitions = $this->acquire(['unsigned.pdf' => $unsignedPdf], ['unsigned.pdf' => $unsignedPdf]);
        unset($acquisitions[0]['status']);

        [$exitCode, , $errorOutput] = $this->runCommand('run-20261031T120000Z', $acquisitions);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('Invalid acquisition record 1', $errorOutput);
        self::assertStringNotContainsString('Warning', $errorOutput);
        self::assertFileDoesNotExist("{$this->directory}/runs/run-20261031T120000Z/run.json");
    }
}
