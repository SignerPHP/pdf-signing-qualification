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
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
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

    private static function alteredValidator(string $validatorClass, string $documentPath, callable $alter): ToolValidator
    {
        return new $validatorClass(new AlteredListingRunner($documentPath, $alter(...)), static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-07T12:00:03Z'));
    }

    public function test_prior_signatures_of_an_input_qpdf_had_to_recover_are_not_all_known(): void
    {
        $validator = self::alteredValidator(PriorSignaturesValidator::class, self::$fixtures['signed'], static fn (CommandResult $result): CommandResult => new CommandResult(3, $result->output));

        $observation = $validator->observe(self::subject('signed', 'appended to signed'));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('The output keeps the 1 signature(s) qpdf listed in the input unchanged, but qpdf had to recover the input, so it may not have listed every signature', $observation['evidence']['summary']);
    }

    public function test_signature_lost_from_a_recovered_input_listing_is_not_judged_as_the_added_one(): void
    {
        $unsignedListing = AlteredListingRunner::listingOf(self::$fixtures['unsigned']);
        $validator = self::alteredValidator(SignatureCoverageValidator::class, self::$fixtures['signed'], static fn (CommandResult $result): CommandResult => new CommandResult(3, $unsignedListing));

        $observation = $validator->observe(self::subject('signed', 'appended to signed'));

        self::assertSame('inconclusive', $observation['verdict']);
        $uncovered = filesize(self::$fixtures['appended to signed']) - filesize(self::$fixtures['signed']);
        self::assertSame("Signature1: the byte range does not reach the end of the file, leaving {$uncovered} bytes uncovered, but qpdf had to recover the input, so this may be a signature of the input", $observation['evidence']['summary']);
        self::assertSame(['Signature1' => 'inconclusive', 'Signature2' => 'valid'], array_column($observation['evidence']['details']['signatures'], 'outcome', 'field'));
    }

    public function test_prior_signature_whose_range_extends_past_the_end_of_the_input_is_inconclusive(): void
    {
        $inputPath = SyntheticSignedPdf::rewriteFirstByteRange(self::$fixtures['signed'], self::$directory, 'range-past-end', static fn (int $firstLength, int $secondOffset, int $secondLength): string => sprintf('/ByteRange [0 %d %d %d]', $firstLength, $secondOffset, $secondLength + 1000));
        $outputPath = SyntheticSignedPdf::appendSignature($inputPath, self::$directory, 'appended-to-range-past-end');

        $observation = self::validator(PriorSignaturesValidator::class)->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($inputPath), $outputPath, $inputPath));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('Signature1: its byte range extends past the end of the input, so the input does not hold every byte it covers', $observation['evidence']['summary']);
    }

    public function test_prior_signature_whose_byte_range_cannot_be_read_is_inconclusive(): void
    {
        $inputPath = SyntheticSignedPdf::rewriteFirstByteRange(self::$fixtures['signed'], self::$directory, 'three-element-range', static fn (int $firstLength, int $secondOffset): string => sprintf('/ByteRange [0 %d %d]', $firstLength, $secondOffset));
        $outputPath = SyntheticSignedPdf::appendSignature($inputPath, self::$directory, 'appended-to-three-element-range');

        $observation = self::validator(PriorSignaturesValidator::class)->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($inputPath), $outputPath, $inputPath));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('Signature1: its /ByteRange or /Contents in the input cannot be read', $observation['evidence']['summary']);
    }

    public function test_prior_signature_missing_from_an_output_qpdf_had_to_recover_is_inconclusive(): void
    {
        $unsignedListing = AlteredListingRunner::listingOf(self::$fixtures['unsigned']);
        $validator = self::alteredValidator(PriorSignaturesValidator::class, self::$fixtures['appended to signed'], static fn (CommandResult $result): CommandResult => new CommandResult(3, $unsignedListing));

        $observation = $validator->observe(self::subject('signed', 'appended to signed'));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('Signature1: no dictionary with the same /ByteRange and /Contents was found in an output qpdf had to recover', $observation['evidence']['summary']);
    }

    public function test_added_signature_whose_contents_qpdf_does_not_give_is_inconclusive(): void
    {
        $withoutContents = static function (CommandResult $result): CommandResult {
            $listing = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
            $listing['qpdf'][1]['obj:8 0 R']['value']['/Contents'] = 'u:é';

            return new CommandResult($result->exitCode, json_encode($listing, JSON_THROW_ON_ERROR));
        };
        $validator = self::alteredValidator(SignatureCoverageValidator::class, self::$fixtures['appended to signed'], $withoutContents);

        $observation = $validator->observe(self::subject('signed', 'appended to signed'));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('Signature2: qpdf did not give the bytes of its /Contents, so the bytes the range leaves out cannot be explained', $observation['evidence']['summary']);
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
