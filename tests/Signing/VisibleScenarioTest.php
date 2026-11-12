<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Signing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\AttemptResult;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Signing\Implementations;
use SignerPHP\PdfSigningQualification\Signing\JSignPdfImplementation;
use SignerPHP\PdfSigningQualification\Signing\NetworkIsolation;
use SignerPHP\PdfSigningQualification\Signing\PdfSignerImplementation;
use SignerPHP\PdfSigningQualification\Signing\ResourceLimits;
use SignerPHP\PdfSigningQualification\Signing\Scenarios;
use SignerPHP\PdfSigningQualification\Signing\SigningImplementation;
use SignerPHP\PdfSigningQualification\Signing\SigningRequest;
use SignerPHP\PdfSigningQualification\Signing\TestCredential;
use SignerPHP\PdfSigningQualification\Tests\Validation\SyntheticVisibleSignature;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class VisibleScenarioTest extends TestCase
{
    private const string RUN_ID = 'run-20261112T120000Z';

    private const string SCENARIO_DIRECTORY = __DIR__.'/../../config/scenarios';

    private const string SANDBOX_DIRECTORY = '/sandbox';

    private static string $directory;

    private static TestCredential $credential;

    private static string $inputPath;

    private string $workDirectory;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/visible-scenario-'.bin2hex(random_bytes(6));
        mkdir(self::$directory);
        mkdir(self::$directory.'/credential');
        self::$credential = TestCredential::generate(self::$directory.'/credential');
        self::$inputPath = SyntheticVisibleSignature::input(self::$directory);
    }

    public static function tearDownAfterClass(): void
    {
        self::$credential->remove();
        exec('rm -rf '.escapeshellarg(self::$directory));
    }

    protected function setUp(): void
    {
        $this->workDirectory = self::SANDBOX_DIRECTORY.'/work-'.bin2hex(random_bytes(4));
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+rwx '.escapeshellarg($this->workDirectory).' 2>/dev/null; rm -rf '.escapeshellarg($this->workDirectory));
    }

    private static function scenario(): array
    {
        return Scenarios::load(self::SCENARIO_DIRECTORY, 'basic-visible-sha256');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function implementations(): iterable
    {
        yield 'pdf-signer' => ['pdf-signer'];
        yield 'jsignpdf' => ['jsignpdf'];
    }

    private function sign(SigningImplementation $implementation, array $scenario): AttemptResult
    {
        $runner = new AttemptRunner(new ProcessRunner, new NetworkIsolation, self::$credential, $this->workDirectory, self::$directory.'/outputs', new ResourceLimits, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-12T12:00:01Z'));

        return $runner->run(self::RUN_ID, $scenario, $implementation, self::availableAcquisition(self::$inputPath), self::$inputPath);
    }

    private static function availableAcquisition(string $path): array
    {
        return [
            'schema' => SchemaValidator::SCHEMA_PREFIX.'v1/acquisition.schema.json',
            'source_id' => 'synthetic:visible-input.pdf',
            'source' => [
                'id' => 'synthetic',
                'url' => 'https://github.com/SignerPHP/pdf-signing-qualification',
                'revision' => ['kind' => 'version', 'value' => '1.0.0'],
                'license' => ['expression' => 'MIT', 'evidence_url' => 'https://github.com/SignerPHP/pdf-signing-qualification/blob/main/LICENSE'],
            ],
            'path' => 'visible-input.pdf',
            'file_url' => 'https://github.com/SignerPHP/pdf-signing-qualification/visible-input.pdf',
            'status' => 'available',
            'retrieved_at' => '2026-11-12T12:00:00Z',
            'sha256' => (string) hash_file('sha256', $path),
            'byte_size' => (int) filesize($path),
            'license' => ['expression' => 'MIT', 'evidence_url' => 'https://github.com/SignerPHP/pdf-signing-qualification/blob/main/LICENSE'],
        ];
    }

    #[DataProvider('implementations')]
    public function test_visible_signature_is_judged_valid_by_every_analyzer_of_the_scenario(string $implementationId): void
    {
        $scenario = self::scenario();
        $implementation = Implementations::select($implementationId);
        self::assertTrue($implementation->supports($scenario['signing']));

        $result = $this->sign($implementation, $scenario);

        $record = $result->record;
        self::assertSame('succeeded', $record['execution']['outcome'], (string) ($record['execution']['error']['message'] ?? ''));
        self::assertSame(self::RUN_ID."/basic-visible-sha256/{$implementationId}/synthetic:visible-input.pdf", $record['id']);
        self::assertNotNull($result->outputPath);

        $panel = ValidationPanel::installed(static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-12T12:00:02Z'), $scenario);
        self::assertContains('pdftoppm-appearance', array_column($panel->analyzers(), 'id'));
        $observations = $panel->observe(new ValidationSubject($record['id'], Artifact::describe(self::$inputPath), $result->outputPath, self::$inputPath));
        $verdicts = array_column(array_map(static fn (array $observation): array => ['analyzer' => $observation['analyzer']['id'], 'verdict' => $observation['verdict'].': '.$observation['evidence']['summary']], $observations), 'verdict', 'analyzer');
        $expected = array_fill_keys(array_keys($verdicts), 'valid');
        $expected['qpdf-prior-signatures'] = 'not_applicable';
        self::assertSame($expected, array_map(static fn (string $verdict): string => strstr($verdict, ':', true) ?: $verdict, $verdicts), print_r($verdicts, true));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function unconfirmedPlacements(): iterable
    {
        foreach (['pdf-signer', 'jsignpdf'] as $implementationId) {
            yield "{$implementationId}, another page" => [$implementationId, 'page', 2];
            yield "{$implementationId}, another left edge" => [$implementationId, 'left', 72];
            yield "{$implementationId}, another top edge" => [$implementationId, 'top', 0];
            yield "{$implementationId}, another width" => [$implementationId, 'width', 200];
            yield "{$implementationId}, another height" => [$implementationId, 'height', 50];
        }
    }

    #[DataProvider('unconfirmedPlacements')]
    public function test_placement_not_confirmed_from_outputs_is_an_unsupported_attempt(string $implementationId, string $parameter, int $value): void
    {
        $scenario = self::scenario();
        $scenario['signing']['placement'][$parameter] = $value;

        $result = $this->sign(Implementations::select($implementationId), $scenario);

        self::assertSame('unsupported', $result->record['capability']);
        self::assertSame('not_run', $result->record['execution']['outcome']);
        self::assertNull($result->outputPath);
    }

    public function test_pdf_signer_keeps_its_default_appearance_only_under_the_visible_scenario(): void
    {
        $implementation = new PdfSignerImplementation;
        $invisible = new SigningRequest(self::$inputPath, self::$directory.'/output.pdf', self::$credential, Scenarios::load(self::SCENARIO_DIRECTORY, 'basic-invisible-sha256')['signing']);
        $visible = new SigningRequest(self::$inputPath, self::$directory.'/output.pdf', self::$credential, self::scenario()['signing']);

        self::assertContains('--no-default-appearance', $implementation->command($invisible));
        self::assertNotContains('--no-default-appearance', $implementation->command($visible));
        self::assertContains('--no-default-timestamp', $implementation->command($visible));
    }

    public function test_jsignpdf_places_the_field_from_the_top_of_the_page(): void
    {
        $attemptDirectory = $this->workDirectory.'/attempt';
        mkdir($attemptDirectory);
        $implementation = new JSignPdfImplementation(javaBinary: __DIR__.'/recording-java');
        $request = new SigningRequest(self::$inputPath, $attemptDirectory.'/output.pdf', self::$credential, self::scenario()['signing']);

        (new ProcessRunner)->run($implementation->command($request), 30.0);

        $arguments = (array) file($attemptDirectory.'/java-arguments', FILE_IGNORE_NEW_LINES);
        self::assertContains('-V', $arguments);
        $value = static fn (string $option): string => (string) $arguments[array_search($option, $arguments, true) + 1];
        self::assertSame(['1', '36', '-120', '276', '-36'], array_map($value, ['-pg', '-llx', '-lly', '-urx', '-ury']));
    }

    public function test_jsignpdf_signs_without_a_visible_field_under_the_invisible_scenario(): void
    {
        $attemptDirectory = $this->workDirectory.'/attempt';
        mkdir($attemptDirectory);
        $implementation = new JSignPdfImplementation(javaBinary: __DIR__.'/recording-java');
        $request = new SigningRequest(self::$inputPath, $attemptDirectory.'/output.pdf', self::$credential, Scenarios::load(self::SCENARIO_DIRECTORY, 'basic-invisible-sha256')['signing']);

        (new ProcessRunner)->run($implementation->command($request), 30.0);

        $arguments = (array) file($attemptDirectory.'/java-arguments', FILE_IGNORE_NEW_LINES);
        self::assertSame([], array_values(array_intersect(['-V', '-pg', '-llx', '-lly', '-urx', '-ury'], $arguments)));
    }

    public function test_invisible_scenario_has_no_visual_analyzer(): void
    {
        $panel = ValidationPanel::installed(static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-12T12:00:02Z'), Scenarios::load(self::SCENARIO_DIRECTORY, 'basic-invisible-sha256'));

        self::assertNotContains('pdftoppm-appearance', array_column($panel->analyzers(), 'id'));
        self::assertCount(7, $panel->analyzers());
    }
}
