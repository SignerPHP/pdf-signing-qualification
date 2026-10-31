<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Regression;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Signing\NetworkIsolation;
use SignerPHP\PdfSigningQualification\Signing\PdfSignerImplementation;
use SignerPHP\PdfSigningQualification\Signing\ResourceLimits;
use SignerPHP\PdfSigningQualification\Signing\TestCredential;
use SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic\HybridXrefPdf;
use SignerPHP\PdfSigningQualification\Validation\CommandLineValidator;
use SignerPHP\PdfSigningQualification\Validation\OpenSslCmsValidator;
use SignerPHP\PdfSigningQualification\Validation\PdfsigValidator;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\PyHankoValidator;
use SignerPHP\PdfSigningQualification\Validation\QpdfCheckValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class HybridXrefRegressionTest extends TestCase
{
    private const string CASE_PATH = __DIR__.'/../../config/regressions/hybrid-xref.json';

    private const string SCENARIO_DIRECTORY = __DIR__.'/../../config/scenarios';

    private const string REGRESSION_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/regression.schema.json';

    private const string RUN_ID = 'run-20261031T120000Z';

    private const string PDF_SIGNER_COMMIT = 'f27e4064be107b8a662f370a9acdcecd786da9f7';

    private const array BASELINE_VERDICTS = [
        'pyhanko' => 'inconclusive',
        'pdfsig' => 'valid',
        'openssl-cms' => 'valid',
        'qpdf' => 'valid',
    ];

    private static string $directory;

    private static string $inputPath;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/regression-'.bin2hex(random_bytes(6));
        mkdir(self::$directory);
        self::$inputPath = HybridXrefPdf::create(self::$directory);
    }

    public static function tearDownAfterClass(): void
    {
        exec('rm -rf '.escapeshellarg(self::$directory));
    }

    private static function regressionCase(): array
    {
        $case = json_decode((string) file_get_contents(self::CASE_PATH), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($case);

        return $case;
    }

    private static function scenario(string $scenarioId): array
    {
        $scenario = json_decode((string) file_get_contents(self::SCENARIO_DIRECTORY."/{$scenarioId}.json"), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($scenario);

        return $scenario;
    }

    private static function acquisition(array $input): array
    {
        $license = ['expression' => 'MIT', 'evidence_url' => 'https://github.com/SignerPHP/pdf-signing-qualification/blob/main/LICENSE'];

        return [
            'schema' => SchemaValidator::SCHEMA_PREFIX.'v1/acquisition.schema.json',
            'source_id' => 'synthetic:hybrid-xref.pdf',
            'source' => [
                'id' => 'synthetic',
                'url' => 'https://github.com/SignerPHP/pdf-signing-qualification',
                'revision' => ['kind' => 'version', 'value' => '0.0.0'],
                'license' => $license,
            ],
            'path' => 'hybrid-xref.pdf',
            'file_url' => 'https://github.com/SignerPHP/pdf-signing-qualification/blob/main/tests/Fixtures/Synthetic/HybridXrefPdf.php',
            'status' => 'available',
            'retrieved_at' => '2026-10-31T12:00:00Z',
            'sha256' => $input['sha256'],
            'byte_size' => $input['byte_size'],
            'license' => $license,
        ];
    }

    private static function finalTrailerXrefStreamOffset(string $path): ?int
    {
        $result = (new ProcessRunner)->run(['qpdf', '--show-object=trailer', $path], 30.0);
        if ($result->exitCode !== 0) {
            throw new RuntimeException("qpdf could not read the trailer of {$path}: {$result->output}");
        }

        return preg_match('#/XRefStm (\d+)#', $result->output, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * @return list<int>
     */
    private static function classicTableObjectNumbers(string $path): array
    {
        $document = (string) file_get_contents($path);
        if (preg_match('/\nxref\n(.*?)trailer/s', $document, $section) !== 1) {
            throw new RuntimeException("No cross-reference table was found in {$path}");
        }
        preg_match_all('/^(\d+) (\d+)$/m', $section[1], $subsections, PREG_SET_ORDER);

        $objectNumbers = [];
        foreach ($subsections as [, $first, $count]) {
            array_push($objectNumbers, ...range((int) $first, (int) $first + (int) $count - 1));
        }

        return $objectNumbers;
    }

    public function test_case_references_its_originating_issue_and_an_existing_scenario(): void
    {
        $case = self::regressionCase();

        self::assertSame([], (new SchemaValidator)->errors($case, self::REGRESSION_SCHEMA));
        self::assertSame('https://github.com/SignerPHP/signer-php/issues/24', $case['origin']['issue_url']);
        self::assertSame([], (new SchemaValidator)->errors(self::scenario($case['scenario_id']), AttemptRunner::SCENARIO_SCHEMA));
    }

    public function test_generator_reproduces_the_exact_input_of_the_case(): void
    {
        $case = self::regressionCase();

        self::assertSame('synthetic', $case['input']['kind']);
        self::assertSame('tests/Fixtures/Synthetic/HybridXrefPdf.php', $case['input']['generator']);
        self::assertSame(['sha256' => $case['input']['sha256'], 'byte_size' => $case['input']['byte_size']], ['sha256' => hash_file('sha256', self::$inputPath), 'byte_size' => filesize(self::$inputPath)]);
    }

    public function test_input_is_a_readable_hybrid_reference_file_whose_page_tree_is_only_in_the_xref_stream(): void
    {
        $check = (new ProcessRunner)->run(['qpdf', '--check', self::$inputPath], 30.0);
        self::assertSame(0, $check->exitCode, $check->output);

        self::assertSame(HybridXrefPdf::XREF_STREAM_OFFSET, self::finalTrailerXrefStreamOffset(self::$inputPath));
        self::assertSame([0, 4, 5, 6], self::classicTableObjectNumbers(self::$inputPath));

        $xref = (new ProcessRunner)->run(['qpdf', '--show-xref', self::$inputPath], 30.0);
        foreach ([1, 2, 3] as $objectNumber) {
            self::assertStringContainsString("{$objectNumber}/0: compressed; stream = 5", $xref->output);
        }

        $pdfsig = (new ProcessRunner)->run(['pdfsig', self::$inputPath], 30.0);
        self::assertStringContainsString('does not contain any signatures', $pdfsig->output);
    }

    public function test_pdf_signer_keeps_the_previous_xref_stream_offset_and_each_validator_records_its_verdict(): void
    {
        $case = self::regressionCase();
        $workDirectory = '/sandbox/regression-'.bin2hex(random_bytes(4));
        mkdir($workDirectory);
        mkdir(self::$directory.'/credential');
        $credential = TestCredential::generate(self::$directory.'/credential');

        try {
            $runner = new AttemptRunner(new ProcessRunner, new NetworkIsolation, $credential, $workDirectory, self::$directory.'/outputs', new ResourceLimits, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-31T12:00:01Z'));
            $result = $runner->run(self::RUN_ID, self::scenario($case['scenario_id']), new PdfSignerImplementation, self::acquisition($case['input']), self::$inputPath);
        } finally {
            $credential->remove();
            exec('rm -rf '.escapeshellarg($workDirectory));
        }

        $record = $result->record;
        self::assertSame([], (new SchemaValidator)->errors($record, AttemptRunner::ATTEMPT_SCHEMA));
        self::assertSame(['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => self::PDF_SIGNER_COMMIT]], $record['implementation']);
        self::assertSame('succeeded', $record['execution']['outcome'], (string) ($record['execution']['error']['message'] ?? ''));
        self::assertNotNull($result->outputPath);

        $xrefStreamOffset = self::finalTrailerXrefStreamOffset($result->outputPath);
        self::assertSame(HybridXrefPdf::XREF_STREAM_OFFSET, $xrefStreamOffset, 'The signed revision no longer carries the /XRefStm of the original revision; update the baseline of this case');
        self::assertLessThan($case['input']['byte_size'], $xrefStreamOffset);

        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-31T12:00:02Z');
        $panel = new ValidationPanel([
            new PyHankoValidator(new ProcessRunner, $clock),
            new PdfsigValidator(new ProcessRunner, $clock),
            new OpenSslCmsValidator(new ProcessRunner, $clock),
            new QpdfCheckValidator(new ProcessRunner, $clock),
        ]);
        $observations = $panel->observe(new ValidationSubject($record['id'], ['sha256' => $record['input']['sha256'], 'byte_size' => $record['input']['byte_size']], $result->outputPath));

        $verdicts = [];
        $rawOutputs = [];
        foreach ($observations as $observation) {
            self::assertSame([], (new SchemaValidator)->errors($observation, CommandLineValidator::OBSERVATION_SCHEMA));
            self::assertSame($record['output'], $observation['output_artifact']);
            $verdicts[$observation['analyzer']['id']] = $observation['verdict'];
            $rawOutputs[$observation['analyzer']['id']] = $observation['evidence']['raw_output'];
        }
        self::assertSame(self::BASELINE_VERDICTS, $verdicts);
        self::assertStringContainsString('Settings do not permit validation of signatures in hybrid-reference files', $rawOutputs['pyhanko']);
    }
}
