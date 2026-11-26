<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Signing\Scenarios;
use SignerPHP\PdfSigningQualification\Validation\BaselineSubject;
use SignerPHP\PdfSigningQualification\Validation\CommandAccess;
use SignerPHP\PdfSigningQualification\Validation\CommandLineValidator;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;
use SignerPHP\PdfSigningQualification\Validation\Judgement;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
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

    public function test_validator_command_that_cannot_be_confined_stops_the_run(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to confine sh');

        (new ProcessRunner)->run(['sh', '-c', 'echo judged'], 10.0, new CommandAccess([$this->directory.'/missing.pdf']));
    }

    public function test_every_command_of_every_installed_validator_is_confined(): void
    {
        $runner = new class implements CommandRunner
        {
            /** @var list<array{list<string>, ?CommandAccess}> */
            public array $commands = [];

            public function run(array $command, float $timeoutSeconds, ?CommandAccess $access = null): CommandResult
            {
                $this->commands[] = [$command, $access];

                return (new ProcessRunner)->run($command, $timeoutSeconds, $access);
            }
        };
        $panel = ValidationPanel::installed(static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-26T12:00:00Z'), Scenarios::load(self::SCENARIO_DIRECTORY, 'basic-visible-sha256'), runner: $runner);

        $panel->analyzers();
        $panel->baseline(new BaselineSubject('run-20261126T120000Z', 'synthetic:signed.pdf', Artifact::describe($this->documentPath), $this->documentPath));
        $panel->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($this->documentPath), $this->documentPath, $this->documentPath));

        self::assertNotSame([], $runner->commands);
        foreach ($runner->commands as [$command, $access]) {
            self::assertNotNull($access, implode(' ', $command).' ran without confinement');
            foreach ($access->readablePaths as $path) {
                self::assertTrue($path === $this->documentPath || preg_match('#^'.preg_quote(sys_get_temp_dir(), '#').'/openssl-cms-[^/]+/[^/]+$#', $path) === 1, implode(' ', $command)." reads {$path}, beyond the documents it judges");
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
