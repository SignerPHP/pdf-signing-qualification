<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Run\RunRecords;

final class RunRecordsMeasurementsTest extends TestCase
{
    private const string INPUT = 'qpdf:qpdf/qtest/qpdf/good17.pdf';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/run-records-measurements-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    private static function runFixture(): RunFixture
    {
        return (new RunFixture('run-20261109T120000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid'])->failed('qpdf:qpdf/qtest/qpdf/bad1.pdf', 'timed_out', null);
    }

    public function test_measurements_are_loaded_with_the_run(): void
    {
        $records = RunRecords::fromDirectory(self::runFixture()->measured(self::INPUT, 0.4, 0.3, 0.05, 52_428_800)->unmeasured('qpdf:qpdf/qtest/qpdf/bad1.pdf', 'The measurement process wrote no measurement')->write($this->directory));

        self::assertNotNull($records->measurements);
        self::assertSame(['run-20261109T120000Z/basic-invisible-sha256/pdf-signer/'.self::INPUT, 'run-20261109T120000Z/basic-invisible-sha256/pdf-signer/qpdf:qpdf/qtest/qpdf/bad1.pdf'], array_column($records->measurements, 'attempt_id'));
        self::assertSame(['succeeded', 'timed_out'], array_column($records->measurements, 'execution_outcome'));
    }

    public function test_run_written_before_measurements_has_none(): void
    {
        self::assertNull(RunRecords::fromDirectory(self::runFixture()->write($this->directory))->measurements);
    }

    public function test_measurement_of_an_attempt_outside_the_run_is_refused(): void
    {
        self::runFixture()->measured(self::INPUT, 0.4, 0.3, 0.05, 52_428_800)->write($this->directory);
        $measurements = json_decode((string) file_get_contents($this->directory.'/measurements.json'), true, flags: JSON_THROW_ON_ERROR);
        $measurements[0]['attempt_id'] = 'run-20261109T120000Z/basic-invisible-sha256/pdf-signer/qpdf:other.pdf';
        file_put_contents($this->directory.'/measurements.json', json_encode($measurements, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is for an attempt that is not in the run, or is recorded more than once');

        RunRecords::fromDirectory($this->directory);
    }

    public function test_attempt_measured_twice_is_refused(): void
    {
        self::runFixture()->measured(self::INPUT, 0.4, 0.3, 0.05, 52_428_800)->measured(self::INPUT, 0.5, 0.3, 0.05, 52_428_800)->write($this->directory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('recorded more than once');

        RunRecords::fromDirectory($this->directory);
    }

    public function test_measurement_whose_outcome_differs_from_its_attempt_is_refused(): void
    {
        self::runFixture()->measured(self::INPUT, 0.4, 0.3, 0.05, 52_428_800)->write($this->directory);
        $measurements = json_decode((string) file_get_contents($this->directory.'/measurements.json'), true, flags: JSON_THROW_ON_ERROR);
        $measurements[0]['execution_outcome'] = 'failed';
        file_put_contents($this->directory.'/measurements.json', json_encode($measurements, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('says failed, but the attempt ended as succeeded');

        RunRecords::fromDirectory($this->directory);
    }
}
