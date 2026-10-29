<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Validation\CommandLineValidator;
use SignerPHP\PdfSigningQualification\Validation\OpenSslCmsValidator;
use SignerPHP\PdfSigningQualification\Validation\PdfsigValidator;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\PyHankoValidator;
use SignerPHP\PdfSigningQualification\Validation\QpdfCheckValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class ValidatorsTest extends TestCase
{
    private const string ATTEMPT_ID = 'run-20261029T120000Z/synthetic/basic-invisible-sha256/synthetic-signer/1';

    private const array INPUT_ARTIFACT = ['sha256' => '3b4c1f0a9e6d2b7c8f5e4a3d2c1b0a9f8e7d6c5b4a39281706f5e4d3c2b1a0f9', 'byte_size' => 1_024];

    private static string $directory;

    /** @var array<string, string> */
    private static array $fixtures;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/validators-'.bin2hex(random_bytes(6));
        mkdir(self::$directory);

        $signed = SyntheticSignedPdf::create(self::$directory);
        self::$fixtures = [
            'signed' => $signed,
            'tampered' => SyntheticSignedPdf::tamperSignedRange($signed, self::$directory),
            'unsigned' => SyntheticSignedPdf::createUnsigned(self::$directory),
        ];
    }

    public static function tearDownAfterClass(): void
    {
        array_map(unlink(...), glob(self::$directory.'/*') ?: []);
        rmdir(self::$directory);
    }

    /**
     * @param  class-string<CommandLineValidator>  $validatorClass
     */
    private static function validator(string $validatorClass): CommandLineValidator
    {
        return new $validatorClass(new ProcessRunner, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-29T12:00:03Z'));
    }

    public static function expectedVerdicts(): iterable
    {
        $expectations = [
            PyHankoValidator::class => ['cms_signature', ['signed' => 'valid', 'tampered' => 'invalid', 'unsigned' => 'invalid']],
            PdfsigValidator::class => ['cms_signature', ['signed' => 'valid', 'tampered' => 'invalid', 'unsigned' => 'invalid']],
            OpenSslCmsValidator::class => ['cms_signature', ['signed' => 'valid', 'tampered' => 'invalid', 'unsigned' => 'invalid']],
            QpdfCheckValidator::class => ['structure', ['signed' => 'valid', 'tampered' => 'valid', 'unsigned' => 'valid']],
        ];

        foreach ($expectations as $validatorClass => [$property, $verdicts]) {
            foreach ($verdicts as $fixture => $verdict) {
                yield (new ReflectionClass($validatorClass))->getShortName().' on '.$fixture => [$validatorClass, $fixture, $property, $verdict];
            }
        }
    }

    /**
     * @param  class-string<CommandLineValidator>  $validatorClass
     */
    #[DataProvider('expectedVerdicts')]
    public function test_validator_judges_the_output_with_its_own_tool(string $validatorClass, string $fixture, string $property, string $verdict): void
    {
        $validator = self::validator($validatorClass);
        $outputPath = self::$fixtures[$fixture];

        $observation = $validator->observe(new ValidationSubject(self::ATTEMPT_ID, self::INPUT_ARTIFACT, $outputPath));

        self::assertSame([], (new SchemaValidator)->errors($observation, CommandLineValidator::OBSERVATION_SCHEMA));
        self::assertSame($verdict, $observation['verdict'], $observation['evidence']['summary']."\n".$observation['evidence']['raw_output']);
        self::assertSame($property, $observation['property']);
        self::assertSame(self::ATTEMPT_ID.'/'.$validator->analyzerId(), $observation['id']);
        self::assertSame(['kind' => 'version', 'value' => $validator->version()], $observation['analyzer']['revision']);
        self::assertSame(self::INPUT_ARTIFACT, $observation['input_artifact']);
        self::assertSame(['sha256' => hash_file('sha256', $outputPath), 'byte_size' => filesize($outputPath)], $observation['output_artifact']);
        self::assertSame('2026-10-29T12:00:03Z', $observation['observed_at']);
    }

    public static function validators(): iterable
    {
        foreach ([PyHankoValidator::class, PdfsigValidator::class, OpenSslCmsValidator::class, QpdfCheckValidator::class] as $validatorClass) {
            yield (new ReflectionClass($validatorClass))->getShortName() => [$validatorClass];
        }
    }

    /**
     * @param  class-string<CommandLineValidator>  $validatorClass
     */
    #[DataProvider('validators')]
    public function test_validator_records_the_raw_evidence_of_a_signed_output(string $validatorClass): void
    {
        $observation = self::validator($validatorClass)->observe(new ValidationSubject(self::ATTEMPT_ID, self::INPUT_ARTIFACT, self::$fixtures['signed']));

        self::assertIsInt($observation['evidence']['exit_code']);
        self::assertNotSame('', trim($observation['evidence']['raw_output']));
    }

    /**
     * @param  class-string<CommandLineValidator>  $validatorClass
     */
    #[DataProvider('validators')]
    public function test_attempt_without_output_is_not_evaluated(string $validatorClass): void
    {
        $observation = self::validator($validatorClass)->observe(new ValidationSubject(self::ATTEMPT_ID, self::INPUT_ARTIFACT, null));

        self::assertSame([], (new SchemaValidator)->errors($observation, CommandLineValidator::OBSERVATION_SCHEMA));
        self::assertSame('not_evaluated', $observation['verdict']);
        self::assertNull($observation['output_artifact']);
    }

    /**
     * @param  class-string<CommandLineValidator>  $validatorClass
     */
    #[DataProvider('validators')]
    public function test_attempt_on_an_unavailable_input_is_not_evaluated(string $validatorClass): void
    {
        $observation = self::validator($validatorClass)->observe(new ValidationSubject(self::ATTEMPT_ID, null, null));

        self::assertSame([], (new SchemaValidator)->errors($observation, CommandLineValidator::OBSERVATION_SCHEMA));
        self::assertSame('not_evaluated', $observation['verdict']);
        self::assertNull($observation['input_artifact']);
        self::assertNull($observation['output_artifact']);
    }

    public function test_openssl_rejects_a_byte_range_whose_gap_is_not_the_signature(): void
    {
        $document = (string) file_get_contents(self::$fixtures['signed']);
        $shiftedPath = self::$directory.'/shifted-byte-range.pdf';
        file_put_contents($shiftedPath, preg_replace_callback(
            '#/ByteRange \[0 (\d+) (\d+) (\d+)\]#',
            static fn (array $match): string => sprintf('/ByteRange [0 %d %d %d]', (int) $match[1] - 1, (int) $match[2] - 1, (int) $match[3] + 1),
            $document,
        ));

        $observation = self::validator(OpenSslCmsValidator::class)->observe(new ValidationSubject(self::ATTEMPT_ID, self::INPUT_ARTIFACT, $shiftedPath));

        self::assertSame('invalid', $observation['verdict']);
        self::assertSame('openssl rejected a signature: the byte range gap is not a hexadecimal string', $observation['evidence']['summary']);
    }

    public function test_panel_keeps_conflicting_verdicts_side_by_side(): void
    {
        $panel = new ValidationPanel(array_map(self::validator(...), [PyHankoValidator::class, PdfsigValidator::class, OpenSslCmsValidator::class, QpdfCheckValidator::class]));

        $observations = $panel->observe(new ValidationSubject(self::ATTEMPT_ID, self::INPUT_ARTIFACT, self::$fixtures['tampered']));

        self::assertSame(
            ['pyhanko' => 'invalid', 'pdfsig' => 'invalid', 'openssl-cms' => 'invalid', 'qpdf' => 'valid'],
            array_column(array_map(static fn (array $observation): array => ['analyzer' => $observation['analyzer']['id'], 'verdict' => $observation['verdict']], $observations), 'verdict', 'analyzer'),
        );
        self::assertCount(4, array_unique(array_column($observations, 'id')));
    }
}
