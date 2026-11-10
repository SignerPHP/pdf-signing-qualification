<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\Acquirer;
use SignerPHP\PdfSigningQualification\Acquisition\Input;
use SignerPHP\PdfSigningQualification\Acquisition\SourceConfiguration;
use SignerPHP\PdfSigningQualification\Run\Environment;
use SignerPHP\PdfSigningQualification\Run\ExpectedRejections;
use SignerPHP\PdfSigningQualification\Run\Pilot;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Signing\NetworkIsolation;
use SignerPHP\PdfSigningQualification\Signing\ResourceLimits;
use SignerPHP\PdfSigningQualification\Signing\TestCredential;
use SignerPHP\PdfSigningQualification\Tests\Acquisition\InMemoryFetcher;
use SignerPHP\PdfSigningQualification\Tests\Signing\CommandImplementation;
use SignerPHP\PdfSigningQualification\Tests\Validation\FakeCommandRunner;
use SignerPHP\PdfSigningQualification\Tests\Validation\SyntheticSignedPdf;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\QpdfCheckValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;

final class PilotTest extends TestCase
{
    private const string SCENARIO_DIRECTORY = __DIR__.'/../../config/scenarios';

    private string $directory;

    private FakeCommandRunner $validatorRunner;

    private TestCredential $credential;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/pilot-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        mkdir($this->directory.'/work');
        mkdir($this->directory.'/credential');
        $this->validatorRunner = new FakeCommandRunner(new CommandResult(0, "qpdf version 11.3.0\n"), new CommandResult(0, ''));
        $this->credential = TestCredential::generate($this->directory.'/credential');
    }

    protected function tearDown(): void
    {
        $this->credential->remove();
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    /**
     * @return array{list<Input>, list<array>}
     */
    private function acquiredInput(): array
    {
        $content = (string) file_get_contents(SyntheticSignedPdf::createUnsigned($this->directory));
        $license = ['expression' => 'MIT', 'evidence_url' => 'https://example.org/license'];
        $configurationPath = $this->directory.'/inputs.json';
        file_put_contents($configurationPath, json_encode([
            'schema' => SourceConfiguration::SCHEMA,
            'sources' => [['id' => 'local', 'url' => 'https://example.org/repository', 'revision' => ['kind' => 'commit', 'value' => str_repeat('a', 40)], 'file_url' => 'https://example.org/{revision}/{path}', 'license' => $license]],
            'inputs' => [['source' => 'local', 'path' => 'unsigned.pdf', 'sha256' => hash('sha256', $content), 'byte_size' => strlen($content), 'license' => $license]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $inputs = SourceConfiguration::fromFile($configurationPath);
        $fetcher = new InMemoryFetcher([$inputs[0]->fileUrl() => $content]);
        $acquirer = new Acquirer($fetcher, $this->directory.'/cache', static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-31T12:00:00Z'));

        return [$inputs, array_map($acquirer->acquire(...), $inputs)];
    }

    /**
     * @param  list<Input>  $inputs
     */
    private function pilot(array $inputs, NetworkIsolation $networkIsolation, ResourceLimits $limits = new ResourceLimits): Pilot
    {
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-31T12:00:00Z');
        $expectedRejectionsPath = $this->directory.'/expected-rejections.json';
        file_put_contents($expectedRejectionsPath, json_encode(['schema' => ExpectedRejections::SCHEMA, 'rejections' => []], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return new Pilot(
            new AttemptRunner($this->validatorRunner, $networkIsolation, $this->credential, $this->directory.'/work', $this->directory.'/outputs', $limits, $clock),
            new CommandImplementation(static fn (): array => ['false'], supported: false),
            new ValidationPanel([new QpdfCheckValidator($this->validatorRunner, $clock)]),
            ExpectedRejections::fromFile($expectedRejectionsPath, $inputs, self::SCENARIO_DIRECTORY),
            $this->directory.'/cache',
            $clock,
        );
    }

    private function runPilot(Pilot $pilot, array $inputs, array $acquisitions): array
    {
        $scenario = json_decode((string) file_get_contents(self::SCENARIO_DIRECTORY.'/basic-invisible-sha256.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($scenario);

        return $pilot->run('run-pilot', $scenario, $inputs, $acquisitions, Environment::describe(false, new ResourceLimits), ['sha256' => str_repeat('b', 64), 'byte_size' => 1]);
    }

    public function test_available_input_gets_a_baseline_even_when_the_scenario_is_unsupported(): void
    {
        [$inputs, $acquisitions] = $this->acquiredInput();

        $records = $this->runPilot($this->pilot($inputs, new NetworkIsolation), $inputs, $acquisitions);

        self::assertSame('unsupported', $records['attempts'][0]['capability']);
        self::assertSame(['local:unsigned.pdf'], array_column($records['baselines'], 'source_id'));
        self::assertSame(['valid'], array_column($records['baselines'], 'verdict'));
    }

    public function test_no_validator_judges_an_input_outside_the_isolation_even_when_the_scenario_is_unsupported(): void
    {
        [$inputs, $acquisitions] = $this->acquiredInput();
        $interfacesDirectory = $this->directory.'/interfaces';
        mkdir($interfacesDirectory);
        touch($interfacesDirectory.'/eth0');

        try {
            $this->runPilot($this->pilot($inputs, new NetworkIsolation($interfacesDirectory)), $inputs, $acquisitions);
            self::fail('The run started with a network interface present');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('eth0', $exception->getMessage());
        }

        self::assertNotContains(['qpdf', '--check'], array_map(static fn (array $command): array => array_slice($command, 0, 2), $this->validatorRunner->commands));
    }

    public function test_no_validator_judges_an_input_when_the_work_directory_is_not_bounded(): void
    {
        [$inputs, $acquisitions] = $this->acquiredInput();

        try {
            $this->runPilot($this->pilot($inputs, new NetworkIsolation, new ResourceLimits(storageBytes: 1)), $inputs, $acquisitions);
            self::fail('The run started on an unbounded filesystem');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('larger than the storage limit', $exception->getMessage());
        }

        self::assertNotContains(['qpdf', '--check'], array_map(static fn (array $command): array => array_slice($command, 0, 2), $this->validatorRunner->commands));
    }
}
