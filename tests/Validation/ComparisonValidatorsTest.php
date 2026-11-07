<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Validation\BaselineSubject;
use SignerPHP\PdfSigningQualification\Validation\ComparisonValidator;
use SignerPHP\PdfSigningQualification\Validation\InputPreservationValidator;
use SignerPHP\PdfSigningQualification\Validation\PriorSignaturesValidator;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\QpdfCheckValidator;
use SignerPHP\PdfSigningQualification\Validation\SignatureCoverageValidator;
use SignerPHP\PdfSigningQualification\Validation\ToolValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class ComparisonValidatorsTest extends TestCase
{
    private const string ATTEMPT_ID = 'run-20261107T120000Z/synthetic/basic-invisible-sha256/synthetic-signer/1';

    private const array VALIDATORS = [InputPreservationValidator::class, PriorSignaturesValidator::class, SignatureCoverageValidator::class];

    private static string $directory;

    /** @var array<string, string> */
    private static array $fixtures;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/comparison-validators-'.bin2hex(random_bytes(6));
        mkdir(self::$directory);

        $signed = SyntheticSignedPdf::create(self::$directory);
        $unsigned = SyntheticSignedPdf::createUnsigned(self::$directory);
        $appendedToSigned = SyntheticSignedPdf::appendSignature($signed, self::$directory, 'appended-to-signed');
        $leadingBytes = self::$directory.'/leading-bytes.pdf';
        file_put_contents($leadingBytes, "leading junk\n\n".file_get_contents($unsigned));

        self::$fixtures = [
            'signed' => $signed,
            'unsigned' => $unsigned,
            'leading bytes' => $leadingBytes,
            'appended to signed' => $appendedToSigned,
            'appended to unsigned' => SyntheticSignedPdf::appendSignature($unsigned, self::$directory, 'appended-to-unsigned'),
            'appended to tampered' => SyntheticSignedPdf::appendSignature(SyntheticSignedPdf::tamperSignedRange($signed, self::$directory), self::$directory, 'appended-to-tampered'),
            'appended without the prior field' => SyntheticSignedPdf::appendSignature($signed, self::$directory, 'appended-without-prior-field', keepPriorFields: false),
            'appended with bytes after the range' => SyntheticSignedPdf::appendSignature($signed, self::$directory, 'appended-with-trailing-bytes', unsignedSuffix: "% unsigned\n"),
            'appended with a shifted gap' => SyntheticSignedPdf::shiftLastByteRangeGap($appendedToSigned, self::$directory),
        ];
    }

    public static function tearDownAfterClass(): void
    {
        exec('rm -rf '.escapeshellarg(self::$directory));
    }

    /**
     * @param  class-string<ToolValidator>  $validatorClass
     */
    private static function validator(string $validatorClass): ToolValidator
    {
        return new $validatorClass(new ProcessRunner, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-07T12:00:03Z'));
    }

    private static function subject(string $input, ?string $output): ValidationSubject
    {
        $inputPath = self::$fixtures[$input];

        return new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($inputPath), $output === null ? null : self::$fixtures[$output], $inputPath);
    }

    public static function expectedVerdicts(): iterable
    {
        $cases = [
            'intact prior signature' => ['signed', 'appended to signed', ['valid', 'valid', 'valid']],
            'unsigned input' => ['unsigned', 'appended to unsigned', ['valid', 'not_applicable', 'valid']],
            'prior signed bytes changed' => ['signed', 'appended to tampered', ['invalid', 'invalid', 'valid']],
            'prior signature removed from the fields' => ['signed', 'appended without the prior field', ['valid', 'invalid', 'valid']],
            'bytes appended after signing' => ['signed', 'appended with bytes after the range', ['valid', 'valid', 'invalid']],
            'range gap shifted after signing' => ['signed', 'appended with a shifted gap', ['valid', 'valid', 'invalid']],
            'bytes before the header dropped' => ['leading bytes', 'appended to unsigned', ['invalid', 'not_applicable', 'valid']],
            'no signature added' => ['signed', 'signed', ['valid', 'valid', 'invalid']],
        ];

        foreach ($cases as $name => [$input, $output, $verdicts]) {
            foreach (self::VALIDATORS as $index => $validatorClass) {
                yield "{$name}, ".self::validator($validatorClass)->analyzerId() => [$validatorClass, $input, $output, $verdicts[$index]];
            }
        }
    }

    /**
     * @param  class-string<ComparisonValidator>  $validatorClass
     */
    #[DataProvider('expectedVerdicts')]
    public function test_validator_compares_the_output_with_its_input(string $validatorClass, string $input, string $output, string $verdict): void
    {
        $validator = self::validator($validatorClass);

        $observation = $validator->observe(self::subject($input, $output));

        self::assertSame([], (new SchemaValidator)->errors($observation, ToolValidator::OBSERVATION_SCHEMA));
        self::assertSame($verdict, $observation['verdict'], $observation['evidence']['summary']."\n".$observation['evidence']['raw_output']);
        self::assertSame(self::ATTEMPT_ID.'/'.$validator->analyzerId(), $observation['id']);
        self::assertSame(Artifact::describe(self::$fixtures[$input]), $observation['input_artifact']);
        self::assertSame(Artifact::describe(self::$fixtures[$output]), $observation['output_artifact']);
    }

    public function test_coverage_identifies_the_signature_added_by_the_implementation(): void
    {
        $observation = self::validator(SignatureCoverageValidator::class)->observe(self::subject('signed', 'appended to signed'));

        $signatures = $observation['evidence']['details']['signatures'];
        self::assertCount(1, $signatures);
        self::assertSame(['object' => '8 0 R', 'field' => 'Signature2', 'outcome' => 'valid'], array_intersect_key($signatures[0], ['object' => true, 'field' => true, 'outcome' => true]));
        [, $firstLength, $secondOffset, $secondLength] = $signatures[0]['byte_range'];
        self::assertSame(filesize(self::$fixtures['appended to signed']), $secondOffset + $secondLength);
        self::assertSame('Signature2 covers every byte of the output except its /Contents', $observation['evidence']['summary']);
        self::assertGreaterThan($firstLength, $secondOffset);
    }

    public function test_field_name_that_is_not_text_is_kept_as_qpdf_gives_it(): void
    {
        $outputPath = SyntheticSignedPdf::appendSignature(self::$fixtures['signed'], self::$directory, 'binary-field-name', fieldName: '<F9934D8A>');
        $subject = new ValidationSubject(self::ATTEMPT_ID, Artifact::describe(self::$fixtures['signed']), $outputPath, self::$fixtures['signed']);

        $observation = self::validator(SignatureCoverageValidator::class)->observe($subject);

        self::assertSame([], (new SchemaValidator)->errors($observation, ToolValidator::OBSERVATION_SCHEMA));
        self::assertSame('b:f9934d8a covers every byte of the output except its /Contents', $observation['evidence']['summary']);
    }

    public static function coverageProblems(): iterable
    {
        yield 'bytes after the range' => ['appended with bytes after the range', 'Signature2: the byte range does not reach the end of the file, leaving 11 bytes uncovered'];
        yield 'shifted gap' => ['appended with a shifted gap', 'Signature2: the bytes the range leaves out are not the hexadecimal string of its /Contents'];
        yield 'no signature added' => ['signed', 'The output holds no signature that is not already in the input'];
    }

    #[DataProvider('coverageProblems')]
    public function test_coverage_names_the_bytes_it_cannot_explain(string $output, string $summary): void
    {
        $observation = self::validator(SignatureCoverageValidator::class)->observe(self::subject('signed', $output));

        self::assertSame($summary, $observation['evidence']['summary']);
    }

    public static function priorSignatureProblems(): iterable
    {
        yield 'signed bytes changed' => ['appended to tampered', 'Signature1: the bytes its range covers changed'];
        yield 'removed from the fields' => ['appended without the prior field', 'Signature1: it is no longer the value of the field Signature1'];
    }

    #[DataProvider('priorSignatureProblems')]
    public function test_prior_signatures_report_each_signature_of_the_input(string $output, string $summary): void
    {
        $observation = self::validator(PriorSignaturesValidator::class)->observe(self::subject('signed', $output));

        self::assertSame($summary, $observation['evidence']['summary']);
        self::assertSame([['object' => '6 0 R', 'field' => 'Signature1', 'outcome' => 'broken']], array_map(
            static fn (array $signature): array => array_intersect_key($signature, ['object' => true, 'field' => true, 'outcome' => true]),
            $observation['evidence']['details']['signatures'],
        ));
    }

    public function test_preservation_reports_where_the_output_departs_from_the_input(): void
    {
        $observation = self::validator(InputPreservationValidator::class)->observe(self::subject('leading bytes', 'appended to unsigned'));

        self::assertSame(1, $observation['evidence']['exit_code']);
        self::assertStringStartsWith('The output does not start with the bytes of the input: ', $observation['evidence']['summary']);
        self::assertStringContainsString('differ: char 1, line 1', $observation['evidence']['raw_output']);
        self::assertSame(['compared_bytes' => filesize(self::$fixtures['leading bytes'])], $observation['evidence']['details']);
    }

    /**
     * @param  class-string<ComparisonValidator>  $validatorClass
     */
    #[DataProvider('validators')]
    public function test_attempt_without_output_is_not_evaluated(string $validatorClass): void
    {
        $observation = self::validator($validatorClass)->observe(self::subject('signed', null));

        self::assertSame([], (new SchemaValidator)->errors($observation, ToolValidator::OBSERVATION_SCHEMA));
        self::assertSame('not_evaluated', $observation['verdict']);
    }

    /**
     * @param  class-string<ComparisonValidator>  $validatorClass
     */
    #[DataProvider('validators')]
    public function test_input_that_differs_from_its_record_is_refused(string $validatorClass): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match the input artifact');

        self::validator($validatorClass)->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe(self::$fixtures['signed']), self::$fixtures['appended to signed'], self::$fixtures['unsigned']));
    }

    public static function validators(): iterable
    {
        foreach (self::VALIDATORS as $validatorClass) {
            yield self::validator($validatorClass)->analyzerId() => [$validatorClass];
        }
    }

    public function test_panel_gives_a_baseline_only_for_analyzers_that_judge_one_document(): void
    {
        $panel = new ValidationPanel([self::validator(QpdfCheckValidator::class), ...array_map(self::validator(...), self::VALIDATORS)]);
        $inputPath = self::$fixtures['signed'];

        $baselines = $panel->baseline(new BaselineSubject('run-20261107T120000Z', 'synthetic:signed.pdf', Artifact::describe($inputPath), $inputPath));
        $observations = $panel->observe(self::subject('signed', 'appended to signed'));

        self::assertSame(['qpdf'], array_map(static fn (array $baseline): string => $baseline['analyzer']['id'], $baselines));
        self::assertSame(['qpdf', 'cmp', 'qpdf-prior-signatures', 'qpdf-byte-range'], array_map(static fn (array $observation): string => $observation['analyzer']['id'], $observations));
    }
}
