<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Performance;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Performance\FixedCostReference;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Signing\NetworkIsolation;
use SignerPHP\PdfSigningQualification\Signing\ResourceLimits;
use SignerPHP\PdfSigningQualification\Signing\TestCredential;
use SignerPHP\PdfSigningQualification\Tests\Signing\CommandImplementation;
use SignerPHP\PdfSigningQualification\Tests\Validation\FakeCommandRunner;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\QpdfCheckValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;

final class FixedCostReferenceTest extends TestCase
{
    private const string SCENARIO_PATH = __DIR__.'/../../config/scenarios/basic-invisible-sha256.json';

    private const string REFERENCE_ID = 'run-20261109T120000Z-01-command-signer-reference';

    private string $directory;

    private string $workDirectory;

    private TestCredential $credential;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fixed-cost-reference-'.bin2hex(random_bytes(6));
        $this->workDirectory = '/sandbox/fixed-cost-reference-'.bin2hex(random_bytes(4));
        mkdir($this->directory.'/credential', 0777, true);
        mkdir($this->workDirectory);
        $this->credential = TestCredential::generate($this->directory.'/credential');
    }

    protected function tearDown(): void
    {
        $this->credential->remove();
        exec('rm -rf '.escapeshellarg($this->directory).' '.escapeshellarg($this->workDirectory));
    }

    /**
     * @param  list<string>  $command
     */
    private function measure(array $command, array $referenceChanges = []): array
    {
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-09T12:00:00Z');
        $reference = new FixedCostReference(
            new AttemptRunner(new ProcessRunner, new NetworkIsolation, $this->credential, $this->workDirectory, $this->directory.'/outputs', new ResourceLimits, $clock),
            new ValidationPanel([new QpdfCheckValidator(new FakeCommandRunner(new CommandResult(0, "qpdf version 11.3.0\n"), new CommandResult(0, '')), $clock)]),
            $this->directory.'/reference-input',
            $clock,
        );
        $scenario = json_decode((string) file_get_contents(self::SCENARIO_PATH), true, flags: JSON_THROW_ON_ERROR);

        /** @var array{generator: string, sha256: string, byte_size: int, repetitions: int} $declared */
        $declared = array_replace(ProtocolTest::protocol()->document['reference'], ['repetitions' => 3], $referenceChanges);

        return $reference->measure(self::REFERENCE_ID, $scenario, new CommandImplementation(static fn (): array => $command), $declared);
    }

    public function test_reference_document_is_signed_measured_and_validated_once_per_repetition(): void
    {
        $records = $this->measure(['cp', 'input.pdf', 'output.pdf']);

        self::assertSame([
            self::REFERENCE_ID.'-01/basic-invisible-sha256/command-signer/reference:minimal.pdf',
            self::REFERENCE_ID.'-02/basic-invisible-sha256/command-signer/reference:minimal.pdf',
            self::REFERENCE_ID.'-03/basic-invisible-sha256/command-signer/reference:minimal.pdf',
        ], array_column($records['attempts'], 'id'));
        self::assertSame(['succeeded', 'succeeded', 'succeeded'], array_column(array_column($records['attempts'], 'execution'), 'outcome'));
        self::assertSame(['status' => 'available', 'source_id' => 'reference:minimal.pdf', 'sha256' => '39d1a47d379f2b659aee63c13d4bcaac131dec23205e1ca713a6c0817fced4ba', 'byte_size' => 349], $records['attempts'][0]['input']);
        self::assertSame(array_column($records['attempts'], 'id'), array_column($records['measurements'], 'attempt_id'));
        self::assertSame('available', $records['measurements'][0]['metrics']['wall_time_seconds']['status']);
        self::assertSame(array_column($records['attempts'], 'id'), array_column($records['observations'], 'attempt_id'));
        foreach ($records['measurements'] as $measurement) {
            self::assertSame([], (new SchemaValidator)->errors($measurement, AttemptRunner::MEASUREMENT_SCHEMA));
        }
    }

    public function test_failed_reference_attempt_keeps_its_outcome_in_the_measurement(): void
    {
        $records = $this->measure(['false'], ['repetitions' => 1]);

        self::assertSame('failed', $records['attempts'][0]['execution']['outcome']);
        self::assertSame('failed', $records['measurements'][0]['execution_outcome']);
    }

    public function test_generator_that_does_not_write_the_declared_document_stops_the_reference(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not the reference document declared by the protocol');

        $this->measure(['cp', 'input.pdf', 'output.pdf'], ['sha256' => str_repeat('0', 64)]);
    }
}
