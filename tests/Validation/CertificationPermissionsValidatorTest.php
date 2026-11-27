<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic\CertifiedPdf;
use SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic\SyntheticPdf;
use SignerPHP\PdfSigningQualification\Validation\BaselineValidator;
use SignerPHP\PdfSigningQualification\Validation\CertificationPermissionsValidator;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\ToolValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class CertificationPermissionsValidatorTest extends TestCase
{
    private const string ATTEMPT_ID = 'run-20261127T120000Z/basic-invisible-sha256/synthetic-signer/synthetic:certified.pdf';

    private static string $directory;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/certification-permissions-'.bin2hex(random_bytes(6));
        mkdir(self::$directory);
    }

    public static function tearDownAfterClass(): void
    {
        array_map(unlink(...), glob(self::$directory.'/*') ?: []);
        rmdir(self::$directory);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function documents(): iterable
    {
        $certifiedWithoutChanges = CertifiedPdf::certified(1);
        $certifiedForForms = CertifiedPdf::certified(2);
        $certifiedForAnnotations = CertifiedPdf::certified(3);
        $uncertified = CertifiedPdf::uncertified();

        yield 'approval signature under /P 1' => [$certifiedWithoutChanges, CertifiedPdf::appendSignature($certifiedWithoutChanges, ['4 0 R'], '5 0 R'), 'invalid', 'The certification of the input permits no change (/P 1), but the output adds the signature(s) Added'];
        yield 'approval signature under /P 2' => [$certifiedForForms, CertifiedPdf::appendSignature($certifiedForForms, ['4 0 R'], '5 0 R'), 'valid', 'The certification of the input permits signing (/P 2), and the output adds only the approval signature(s) Added'];
        yield 'approval signature under /P 3' => [$certifiedForAnnotations, CertifiedPdf::appendSignature($certifiedForAnnotations, ['4 0 R'], '5 0 R'), 'valid', 'The certification of the input permits signing (/P 3), and the output adds only the approval signature(s) Added'];
        yield 'certification signature under /P 2' => [$certifiedForForms, CertifiedPdf::appendSignature($certifiedForForms, ['4 0 R'], '7 0 R', 2), 'invalid', 'The input is already certified, but the output adds the certification signature(s) Added; the output no longer registers the certification of the input as /Perms /DocMDP'];
        yield 'certification signature that leaves /Perms to the input' => [$certifiedForForms, CertifiedPdf::appendSignature($certifiedForForms, ['4 0 R'], '5 0 R', 2), 'invalid', 'The input is already certified, but the output adds the certification signature(s) Added'];
        yield 'direct certification signature' => [$certifiedForForms, CertifiedPdf::appendSignature($certifiedForForms, ['4 0 R'], '5 0 R', 2, directSignature: true), 'invalid', 'The input is already certified, but the output adds the certification signature(s) Added'];
        yield 'document timestamp under /P 1' => [$certifiedWithoutChanges, CertifiedPdf::appendSignature($certifiedWithoutChanges, ['4 0 R'], '5 0 R', documentTimestamp: true), 'valid', 'The output adds no approval or certification signature to the certified input (/P 1), only the document timestamp(s) Added, which DocMDP restrictions ignore'];
        yield 'document timestamp under /P 2' => [$certifiedForForms, CertifiedPdf::appendSignature($certifiedForForms, ['4 0 R'], '5 0 R', documentTimestamp: true), 'valid', 'The output adds no approval or certification signature to the certified input (/P 2), only the document timestamp(s) Added, which DocMDP restrictions ignore'];
        yield 'no signature under /P 1' => [$certifiedWithoutChanges, SyntheticPdf::appendUpdate($certifiedWithoutChanges, [3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 200] /Annots [4 0 R] >>']), 'valid', 'The output adds no approval or certification signature to the certified input (/P 1)'];
        yield 'approval signature under /P 1 of an input qpdf had to recover' => [str_replace('/Size 6', '/Size 9', $certifiedWithoutChanges), CertifiedPdf::appendSignature($certifiedWithoutChanges, ['4 0 R'], '5 0 R'), 'inconclusive', 'The certification of the input permits no change (/P 1), but the output adds the signature(s) Added; qpdf had to recover the input, so it may not have listed them in the input'];
        yield 'certification of the input dropped from /Perms of an output qpdf had to recover' => [$certifiedForForms, str_replace('/Size 8', '/Size 9', CertifiedPdf::appendSignature($certifiedForForms, ['4 0 R'], null)), 'inconclusive', 'The output does not register the certification of the input as /Perms /DocMDP; qpdf had to recover the output, so it may not have listed it'];
        yield 'transform parameters that are not a dictionary' => [self::withTransformParameters($certifiedForForms, '7'), CertifiedPdf::appendSignature(self::withTransformParameters($certifiedForForms, '7'), ['4 0 R'], '5 0 R'), 'inconclusive', 'The DocMDP transform parameters of the input do not give a permission level of 1, 2 or 3: 7'];
        yield 'certification of the input dropped from /Perms' => [$certifiedForForms, CertifiedPdf::appendSignature($certifiedForForms, ['4 0 R'], null), 'invalid', 'The output no longer registers the certification of the input as /Perms /DocMDP'];
        yield 'uncertified input' => [$uncertified, CertifiedPdf::appendSignature($uncertified, [], null), 'not_applicable', 'The input has no certification signature in /Perms /DocMDP'];
        yield 'certification without a DocMDP transform' => [str_replace('/TransformMethod /DocMDP', '/TransformMethod /Ignored', $certifiedForForms), CertifiedPdf::appendSignature(str_replace('/TransformMethod /DocMDP', '/TransformMethod /Ignored', $certifiedForForms), ['4 0 R'], '5 0 R'), 'inconclusive', 'The certification signature of the input has no DocMDP transform in /Reference, so its permissions cannot be read'];
        yield 'unknown permission level' => [CertifiedPdf::certified(4), CertifiedPdf::appendSignature(CertifiedPdf::certified(4), ['4 0 R'], '5 0 R'), 'inconclusive', 'The DocMDP transform parameters of the input do not give a permission level of 1, 2 or 3: 4'];
        yield 'input qpdf had to recover' => [str_replace('/Size 6', '/Size 9', $certifiedForForms), CertifiedPdf::appendSignature($certifiedForForms, ['4 0 R'], '5 0 R'), 'inconclusive', 'qpdf had to recover the input, so it may not have listed every signature'];
        yield 'input qpdf cannot read' => ['not a PDF', $certifiedForForms, 'inconclusive', 'qpdf could not read the catalog of the input: qpdf exited with code 2'];
    }

    #[DataProvider('documents')]
    public function test_added_signatures_are_judged_against_the_certification_of_the_input(string $input, string $output, string $verdict, string $summary): void
    {
        $observation = self::observe($input, $output);

        self::assertSame($verdict, $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame($summary, $observation['evidence']['summary']);
        self::assertSame('certification_permissions', $observation['property']);
        (new SchemaValidator)->assertValid($observation, ToolValidator::OBSERVATION_SCHEMA, 'observation');
    }

    public function test_records_the_permission_level_and_the_added_signatures(): void
    {
        $input = CertifiedPdf::certified(2);

        $details = self::observe($input, CertifiedPdf::appendSignature($input, ['4 0 R'], '5 0 R'))['evidence']['details'];

        self::assertSame(['certification' => '5 0 R', 'permission' => 2, 'added_signatures' => [['object' => '7 0 R', 'field' => 'Added', 'kind' => 'approval']]], $details);
    }

    public function test_has_no_baseline_and_records_its_version_and_policy(): void
    {
        $validator = self::validator();
        $input = CertifiedPdf::certified(2);
        $observation = self::observe($input, CertifiedPdf::appendSignature($input, ['4 0 R'], '5 0 R'));

        self::assertNotInstanceOf(BaselineValidator::class, $validator);
        self::assertSame('qpdf-certification-permissions', $observation['analyzer']['id']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $observation['analyzer']['revision']['value']);
        self::assertSame('qpdf-certification-permissions', $observation['policy']['id']);
    }

    public function test_added_signature_whose_dictionary_cannot_be_read_is_inconclusive(): void
    {
        $input = CertifiedPdf::certified(2);
        $inputPath = SyntheticPdf::write(self::$directory, 'listed-input.pdf', $input);
        $outputPath = SyntheticPdf::write(self::$directory, 'listed-output.pdf', CertifiedPdf::appendSignature($input, ['4 0 R'], '5 0 R'));
        $runner = new AlteredListingRunner($outputPath, static function (CommandResult $result): CommandResult {
            $listing = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
            $listing['qpdf'][1]['trailer']['value']['/Signature'] = ['/ByteRange' => [0, 4, 5, 6], '/Contents' => 'b:01'];

            return new CommandResult($result->exitCode, json_encode($listing, JSON_THROW_ON_ERROR));
        });

        $observation = (new CertificationPermissionsValidator($runner, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-27T12:00:00Z')))->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($inputPath), $outputPath, $inputPath));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('The dictionary of the added signature(s) trailer cannot be read', $observation['evidence']['summary']);
    }

    private static function withTransformParameters(string $document, string $parameters): string
    {
        $original = '/TransformParams << /Type /TransformParams /P 2 /V /1.2 >>';

        return str_replace($original, str_pad("/TransformParams {$parameters}", strlen($original)), $document);
    }

    private static function observe(string $input, string $output): array
    {
        $name = bin2hex(random_bytes(6));
        $inputPath = SyntheticPdf::write(self::$directory, "{$name}-input.pdf", $input);
        $outputPath = SyntheticPdf::write(self::$directory, "{$name}-output.pdf", $output);

        return self::validator()->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($inputPath), $outputPath, $inputPath));
    }

    private static function validator(): CertificationPermissionsValidator
    {
        return new CertificationPermissionsValidator(new ProcessRunner, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-27T12:00:00Z'));
    }
}
