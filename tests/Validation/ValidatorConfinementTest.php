<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Pki\TestPki;
use SignerPHP\PdfSigningQualification\Regression\HybridXrefCondition;
use SignerPHP\PdfSigningQualification\Signing\Scenarios;
use SignerPHP\PdfSigningQualification\Validation\BaselineSubject;
use SignerPHP\PdfSigningQualification\Validation\CommandAccess;
use SignerPHP\PdfSigningQualification\Validation\CommandLineValidator;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;
use SignerPHP\PdfSigningQualification\Validation\Judgement;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\QpdfCheckValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class ValidatorConfinementTest extends TestCase
{
    private const string ATTEMPT_ID = 'run-20261126T120000Z/synthetic/basic-invisible-sha256/synthetic-signer/1';

    private const string SCENARIO_DIRECTORY = __DIR__.'/../../config/scenarios';

    private string $directory;

    private string $documentPath;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/validator-confinement-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->documentPath = SyntheticSignedPdf::create($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    public function test_validator_command_cannot_read_the_repository_or_write_next_to_its_document(): void
    {
        $validator = self::validator(new ProcessRunner, ['sh', '-c', 'cat /app/composer.json; echo planted > "$1.planted"; cat "$1" > /dev/null && echo judged', 'sh']);

        $observation = $validator->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($this->documentPath), $this->documentPath));

        self::assertSame('inconclusive', $observation['verdict'], $observation['evidence']['raw_output']);
        self::assertStringNotContainsString('signerphp/pdf-signing-qualification', $observation['evidence']['raw_output']);
        self::assertSame(2, substr_count($observation['evidence']['raw_output'], 'Permission denied'), $observation['evidence']['raw_output']);
        self::assertStringContainsString('judged', $observation['evidence']['raw_output']);
        self::assertFileDoesNotExist($this->documentPath.'.planted');
    }

    public function test_validator_judges_a_document_named_relative_to_the_current_directory(): void
    {
        $currentDirectory = (string) getcwd();
        chdir($this->directory);
        try {
            $result = (new ProcessRunner)->run(['cat', basename($this->documentPath)], 10.0, new CommandAccess([basename($this->documentPath)]));
        } finally {
            chdir($currentDirectory);
        }

        self::assertSame(0, $result->exitCode, $result->output);
        self::assertSame(file_get_contents($this->documentPath), $result->output);
    }

    public function test_validator_command_sees_an_empty_environment_and_writes_only_to_its_own_directory(): void
    {
        putenv('QUALIFICATION_SECRET=do-not-leak');
        try {
            $result = (new ProcessRunner)->run(['sh', '-c', 'env | sort; : > "$TMPDIR/scratch" && echo wrote-own; (: > /tmp/planted-by-validator) 2> /dev/null || echo refused-tmp'], 10.0, new CommandAccess);
        } finally {
            putenv('QUALIFICATION_SECRET');
        }

        self::assertSame(0, $result->exitCode, $result->output);
        self::assertStringNotContainsString('QUALIFICATION_SECRET', $result->output);
        self::assertMatchesRegularExpression('#^HOME=/tmp/validator-[0-9a-f]+$#m', $result->output);
        self::assertMatchesRegularExpression('#^TMPDIR=/tmp/validator-[0-9a-f]+$#m', $result->output);
        self::assertStringContainsString('wrote-own', $result->output);
        self::assertStringContainsString('refused-tmp', $result->output);
        self::assertFileDoesNotExist('/tmp/planted-by-validator');
        preg_match('#^HOME=(\S+)$#m', $result->output, $matches);
        self::assertDirectoryDoesNotExist($matches[1]);
        self::assertFileDoesNotExist($matches[1].'.measurement.json');
    }

    public function test_validator_command_writes_to_the_directory_its_adapter_declares(): void
    {
        $renderDirectory = $this->directory.'/render';
        mkdir($renderDirectory);

        $result = (new ProcessRunner)->run(['sh', '-c', 'echo page > "$1/page.png"', 'sh', $renderDirectory], 10.0, new CommandAccess(writablePaths: [$renderDirectory]));

        self::assertSame(0, $result->exitCode, $result->output);
        self::assertFileExists($renderDirectory.'/page.png');
    }

    public function test_validator_command_that_exceeds_its_time_is_timed_out(): void
    {
        $result = (new ProcessRunner)->run(['sh', '-c', 'echo started; exec sleep 30'], 0.5, new CommandAccess);

        self::assertTrue($result->timedOut);
        self::assertStringContainsString('started', $result->output);
    }

    public function test_measuring_process_that_ends_without_a_measurement_stops_the_run_instead_of_giving_a_verdict(): void
    {
        $measurementProcess = $this->directory.'/failing-measurement.py';
        file_put_contents($measurementProcess, "import sys\nsys.exit(2)\n");
        $runner = new class(new ProcessRunner($measurementProcess)) implements CommandRunner
        {
            public function __construct(private readonly ProcessRunner $failingRunner) {}

            public function run(array $command, float $timeoutSeconds, ?CommandAccess $access = null): CommandResult
            {
                return in_array('--version', $command, true) ? (new ProcessRunner)->run($command, $timeoutSeconds, $access) : $this->failingRunner->run($command, $timeoutSeconds, $access);
            }
        };
        $validator = new QpdfCheckValidator($runner, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-26T12:00:00Z'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('wrote no measurement');

        $validator->baseline(new BaselineSubject('run-20261126T120000Z', 'synthetic:signed.pdf', Artifact::describe($this->documentPath), $this->documentPath));
    }

    public function test_validator_command_that_cannot_be_confined_stops_the_run(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to confine sh');

        (new ProcessRunner)->run(['sh', '-c', 'echo judged'], 10.0, new CommandAccess([$this->directory.'/missing.pdf']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function scenariosWithEveryValidator(): array
    {
        return [
            'visible signature' => ['basic-visible-sha256'],
            'ICP-Brasil AD-RT signed with the test PKI' => ['icp-brasil-ad-rt'],
        ];
    }

    #[DataProvider('scenariosWithEveryValidator')]
    public function test_every_command_of_every_installed_validator_is_confined(string $scenarioId): void
    {
        $runner = new RecordingCommandRunner;
        $pki = TestPki::start(new ProcessRunner, $this->directory.'/pki');
        try {
            $panel = ValidationPanel::installed(static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-26T12:00:00Z'), Scenarios::load(self::SCENARIO_DIRECTORY, $scenarioId), $pki, $runner);

            $panel->analyzers();
            $panel->baseline(new BaselineSubject('run-20261126T120000Z', 'synthetic:signed.pdf', Artifact::describe($this->documentPath), $this->documentPath));
            $panel->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($this->documentPath), $this->documentPath, $this->documentPath));
        } finally {
            $pki->stop();
        }

        $expectedAnalyzers = $scenarioId === 'icp-brasil-ad-rt' ? ['eu-dss', 'qpdf-pades-structure', 'document-timestamp-imprint', 'demoiselle-signer'] : ['pdftoppm-appearance'];
        self::assertSame([], array_diff($expectedAnalyzers, array_column($panel->analyzers(), 'id')));
        $this->assertEveryCommandIsConfined($runner->commands, [$this->documentPath, $pki->rootCertificatePath()]);
    }

    public function test_regression_condition_check_is_confined(): void
    {
        $runner = new RecordingCommandRunner;

        (new HybridXrefCondition($runner))->evaluate($this->documentPath, $this->documentPath);

        self::assertCount(1, $runner->commands);
        $this->assertEveryCommandIsConfined($runner->commands, [$this->documentPath]);
    }

    /**
     * @param  list<array{list<string>, ?CommandAccess}>  $commands
     * @param  list<string>  $documentPaths
     */
    private function assertEveryCommandIsConfined(array $commands, array $documentPaths): void
    {
        $temporaryDirectory = preg_quote(sys_get_temp_dir(), '#');
        self::assertNotSame([], $commands);
        foreach ($commands as [$command, $access]) {
            $description = implode(' ', $command);
            self::assertNotNull($access, "{$description} ran without confinement");
            foreach ($access->readablePaths as $path) {
                self::assertTrue(in_array($path, $documentPaths, true) || preg_match("#^{$temporaryDirectory}/openssl-cms-[^/]+/[^/]+$#", $path) === 1, "{$description} reads {$path}, beyond the documents it judges");
            }
            foreach ($access->writablePaths as $path) {
                self::assertMatchesRegularExpression("#^{$temporaryDirectory}/(openssl-cms-|visible-appearance-)[^/]+$#", $path, "{$description} writes to {$path}, beyond a directory of its own");
            }
        }
    }

    /**
     * @param  list<string>  $command
     */
    private static function validator(CommandRunner $runner, array $command): CommandLineValidator
    {
        return new class($runner, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-26T12:00:00Z'), $command) extends CommandLineValidator
        {
            /**
             * @param  list<string>  $command
             */
            public function __construct(CommandRunner $runner, Closure $clock, private readonly array $command)
            {
                parent::__construct($runner, $clock, 10.0);
            }

            public function analyzerId(): string
            {
                return 'confinement-probe';
            }

            protected function property(): string
            {
                return 'structure';
            }

            protected function policy(): array
            {
                return ['id' => 'confinement-probe', 'description' => 'Valid only when the probe reaches what it tries to reach', 'parameters' => []];
            }

            protected function versionCommand(): array
            {
                return ['echo', 'confinement-probe 1'];
            }

            protected function versionPattern(): string
            {
                return '/^confinement-probe (\S+)$/m';
            }

            protected function judge(string $documentPath): Judgement
            {
                $result = $this->runner->run([...$this->command, $documentPath], $this->timeoutSeconds, new CommandAccess([$documentPath]));
                $reachedEverything = $result->exitCode === 0 && ! str_contains($result->output, 'Permission denied');

                return new Judgement($reachedEverything ? 'valid' : 'inconclusive', 'The probe '.$result->describe(), $result->exitCode, $result->output);
            }
        };
    }
}
