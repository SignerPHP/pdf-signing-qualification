<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Performance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Performance\Protocol;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic\MinimalPdf;

final class ProtocolTest extends TestCase
{
    private const string VERSIONED_PROTOCOL = __DIR__.'/../../config/performance/protocol.json';

    public static function protocol(array $changes = []): Protocol
    {
        $document = json_decode((string) file_get_contents(self::VERSIONED_PROTOCOL), true, flags: JSON_THROW_ON_ERROR);

        return Protocol::fromJson(json_encode(array_replace($document, $changes), JSON_THROW_ON_ERROR));
    }

    public function test_versioned_protocol_is_valid_and_names_the_main_panel(): void
    {
        $protocol = Protocol::fromFile(self::VERSIONED_PROTOCOL);

        self::assertSame(['pdf-signer', 'jsignpdf'], $protocol->implementations());
        self::assertSame('config/main-panel/inputs.json', $protocol->document['panel']['inputs_configuration']);
        self::assertFileExists(__DIR__.'/../../'.$protocol->document['panel']['inputs_configuration']);
        self::assertFileExists(__DIR__.'/../../'.$protocol->document['panel']['expected_rejections']);
    }

    public function test_reference_generator_writes_the_declared_document(): void
    {
        $reference = Protocol::fromFile(self::VERSIONED_PROTOCOL)->document['reference'];
        $directory = sys_get_temp_dir().'/reference-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $path = MinimalPdf::create($directory);

            self::assertSame('tests/Fixtures/Synthetic/MinimalPdf.php', $reference['generator']);
            self::assertSame($reference['sha256'], hash_file('sha256', $path));
            self::assertSame($reference['byte_size'], filesize($path));
        } finally {
            exec('rm -rf '.escapeshellarg($directory));
        }
    }

    public function test_measured_session_runs_the_warm_up_blocks_before_the_measured_ones_with_each_implementation_once_per_block(): void
    {
        $plan = self::protocol(['warm_up_runs' => 1, 'measured_runs' => 3])->plan(1);

        self::assertCount(8, $plan);
        self::assertSame(['warm_up', 'warm_up', 'measured', 'measured', 'measured', 'measured', 'measured', 'measured'], array_column($plan, 'role'));
        foreach (array_chunk($plan, 2) as $block) {
            $implementations = array_column($block, 'implementation_id');
            sort($implementations);
            self::assertSame(['jsignpdf', 'pdf-signer'], $implementations);
        }
        foreach ($plan as $index => $run) {
            self::assertSame(sprintf('%02d-%s', $index + 1, $run['implementation_id']), $run['label']);
        }
    }

    public function test_order_is_reproduced_from_the_seed(): void
    {
        $protocol = self::protocol(['seed' => 7, 'warm_up_runs' => 0, 'measured_runs' => 4]);

        self::assertSame($protocol->plan(1), self::protocol(['seed' => 7, 'warm_up_runs' => 0, 'measured_runs' => 4])->plan(1));
        self::assertSame(
            ['01-pdf-signer', '02-jsignpdf', '03-jsignpdf', '04-pdf-signer', '05-pdf-signer', '06-jsignpdf', '07-pdf-signer', '08-jsignpdf'],
            array_column($protocol->plan(1), 'label'),
        );
    }

    public function test_each_session_has_its_own_seed(): void
    {
        $protocol = self::protocol(['seed' => 100]);

        self::assertSame(100, $protocol->seedOf(Protocol::CALIBRATION));
        self::assertSame(101, $protocol->seedOf(1));
        self::assertSame(102, $protocol->seedOf('2'));
    }

    public function test_calibration_session_has_only_calibration_runs(): void
    {
        $plan = self::protocol(['calibration_runs' => 3])->plan(Protocol::CALIBRATION);

        self::assertCount(6, $plan);
        self::assertSame(['calibration'], array_values(array_unique(array_column($plan, 'role'))));
    }

    public function test_study_record_follows_its_schema_and_names_the_protocol_it_follows(): void
    {
        $protocol = self::protocol();
        $study = $protocol->study('performance-1-20261109T120000Z', '1', '2026-11-09T12:00:00Z');

        self::assertSame([], (new SchemaValidator)->errors($study, Protocol::STUDY_SCHEMA));
        self::assertSame(1, $study['session']);
        self::assertSame(['sha256' => hash('sha256', $protocol->contents), 'byte_size' => strlen($protocol->contents)], $study['protocol']);
        self::assertSame($protocol->plan(1), $study['runs']);
        self::assertSame('calibration', $protocol->study('performance-calibration-20261109T120000Z', Protocol::CALIBRATION, '2026-11-09T12:00:00Z')['session']);
    }

    public static function invalidSessions(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => [-1];
        yield 'word' => ['first'];
        yield 'padded' => ['01'];
    }

    #[DataProvider('invalidSessions')]
    public function test_session_must_be_calibration_or_a_positive_number(string|int $session): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The session must be calibration or a positive number');

        self::protocol()->plan($session);
    }

    public function test_protocol_that_breaks_its_schema_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid performance protocol');

        self::protocol(['measured_runs' => 1]);
    }

    public function test_quantiles_out_of_order_are_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not in increasing order');

        self::protocol(['quantiles' => [0.5, 0.25]]);
    }
}
