<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Validation\AddedSignatures;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;
use SignerPHP\PdfSigningQualification\Validation\DemoiselleValidator;
use SignerPHP\PdfSigningQualification\Validation\EuDssValidator;
use SignerPHP\PdfSigningQualification\Validation\ToolValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class PadesProfileValidatorsTest extends TestCase
{
    private const string ATTEMPT_ID = 'run-20261123T120000Z/pades-baseline-lta/pdf-signer/synthetic:input.pdf';

    private const array ARTIFACT = ['sha256' => '3b4c1f0a9e6d2b7c8f5e4a3d2c1b0a9f8e7d6c5b4a39281706f5e4d3c2b1a0f9', 'byte_size' => 1_024];

    private const array LTA = [
        'digest_algorithm' => 'sha256',
        'signature_algorithm' => 'rsa-pkcs1-v1_5',
        'sub_filter' => 'ETSI.CAdES.detached',
        'appearance' => 'invisible',
        'timestamp' => true,
        'revocation_info' => true,
        'document_timestamp' => true,
        'signers' => ['valid'],
    ];

    private static string $output;

    public static function setUpBeforeClass(): void
    {
        self::$output = (string) tempnam(sys_get_temp_dir(), 'output');
        file_put_contents(self::$output, '%PDF-1.7 synthetic output');
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$output);
    }

    public static function expectedFormats(): iterable
    {
        yield 'B' => [['timestamp' => false, 'revocation_info' => false, 'document_timestamp' => false] + self::LTA, 'PAdES-BASELINE-B'];
        yield 'T' => [['revocation_info' => false, 'document_timestamp' => false] + self::LTA, 'PAdES-BASELINE-T'];
        yield 'LT' => [['document_timestamp' => false] + self::LTA, 'PAdES-BASELINE-LT'];
        yield 'LTA' => [self::LTA, 'PAdES-BASELINE-LTA'];
        yield 'PKCS#7' => [['sub_filter' => 'adbe.pkcs7.detached', 'timestamp' => false, 'revocation_info' => false, 'document_timestamp' => false] + self::LTA, 'PKCS7-B'];
    }

    #[DataProvider('expectedFormats')]
    public function test_expected_format_follows_the_signing_of_the_scenario(array $signing, string $format): void
    {
        self::assertSame($format, EuDssValidator::expectedFormat($signing));
    }

    public static function dssReports(): iterable
    {
        $passed = ['id' => 'S-1', 'indication' => 'TOTAL_PASSED', 'sub_indication' => null, 'format' => 'PAdES-BASELINE-LTA', 'signed_by' => 'Signer A'];

        yield 'passed at the expected level' => [[$passed], 'valid'];
        yield 'failed' => [[['indication' => 'TOTAL_FAILED', 'sub_indication' => 'REVOKED'] + $passed], 'invalid'];
        yield 'another level' => [[['format' => 'PAdES-BASELINE-LT'] + $passed], 'invalid'];
        yield 'no signature' => [[], 'invalid'];
        yield 'one signature too many' => [[$passed, ['id' => 'S-2'] + $passed], 'invalid'];
        yield 'indeterminate' => [[['indication' => 'INDETERMINATE', 'sub_indication' => 'NO_POE'] + $passed], 'inconclusive'];
        yield 'indeterminate at another level' => [[['indication' => 'INDETERMINATE', 'format' => 'PAdES-BASELINE-B'] + $passed], 'invalid'];
    }

    #[DataProvider('dssReports')]
    public function test_dss_report_gives_the_verdict(array $signatures, string $verdict): void
    {
        $runner = new FakeCommandRunner(new CommandResult(0, "dss-validator 6.2\n"), new CommandResult(0, "a log line {with braces}\n".AddedSignatures::REPORT_PREFIX.json_encode(['dss_version' => '6.2', 'signatures' => $signatures, 'timestamps' => []], JSON_THROW_ON_ERROR)."\n"));
        $observation = self::observe(new EuDssValidator($runner, self::clock(), self::LTA, '/pki/root.der', self::ARTIFACT));

        self::assertSame($verdict, $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame(['id' => 'eu-dss', 'revision' => ['kind' => 'version', 'value' => '6.2']], $observation['analyzer']);
        self::assertSame('profile', $observation['property']);
        self::assertSame('PAdES-BASELINE-LTA', $observation['policy']['parameters']['expected_format']);
        self::assertSame(['/opt/java/bin/java', ...EuDssValidator::JAVA_OPTIONS, '-jar', EuDssValidator::JAR, '/pki/root.der', self::$output], $runner->commands[0]);
    }

    public function test_signature_of_a_field_signed_in_the_input_is_prior_and_not_judged(): void
    {
        $prior = ['id' => 'S-0', 'fields' => ['Signature1'], 'indication' => 'INDETERMINATE', 'sub_indication' => 'NO_CERTIFICATE_CHAIN_FOUND', 'format' => 'PKCS7-B'];
        $added = ['id' => 'S-1', 'fields' => ['Signature2'], 'indication' => 'TOTAL_PASSED', 'sub_indication' => null, 'format' => 'PAdES-BASELINE-LTA'];
        $listing = json_encode(['qpdf' => [['jsonversion' => 2], [
            'trailer' => ['value' => ['/Root' => '1 0 R']],
            'obj:1 0 R' => ['value' => ['/AcroForm' => ['/Fields' => ['2 0 R']]]],
            'obj:2 0 R' => ['value' => ['/FT' => '/Sig', '/T' => 'u:Signature1', '/V' => '3 0 R']],
            'obj:3 0 R' => ['value' => ['/Type' => '/Sig', '/ByteRange' => [0, 1, 2, 3], '/Contents' => 'b:00']],
        ]]], JSON_THROW_ON_ERROR);
        $runner = new class(AddedSignatures::REPORT_PREFIX.json_encode(['signatures' => [$prior, $added], 'timestamps' => []], JSON_THROW_ON_ERROR), $listing) implements CommandRunner
        {
            public function __construct(private readonly string $report, private readonly string $listing) {}

            public function run(array $command, float $timeoutSeconds): CommandResult
            {
                return match (true) {
                    in_array('--version', $command, true) => new CommandResult(0, "dss-validator 6.2\n"),
                    $command[0] === 'qpdf' => new CommandResult(0, $this->listing),
                    default => new CommandResult(0, $this->report),
                };
            }
        };

        $observation = (new EuDssValidator($runner, self::clock(), self::LTA, '/pki/root.der', self::ARTIFACT))->observe(new ValidationSubject(self::ATTEMPT_ID, self::ARTIFACT, self::$output, '/input.pdf'));

        self::assertSame('valid', $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame(['S-0'], array_column($observation['evidence']['details']['prior_signatures'], 'id'));
    }

    public function test_policy_does_not_change_with_the_root_of_the_run(): void
    {
        $runner = new FakeCommandRunner(new CommandResult(0, "dss-validator 6.2\n"), new CommandResult(0, ''));
        $first = self::observe(new EuDssValidator($runner, self::clock(), self::LTA, '/pki/root.der', self::ARTIFACT));
        $second = self::observe(new EuDssValidator($runner, self::clock(), self::LTA, '/other/root.der', ['sha256' => str_repeat('a', 64), 'byte_size' => 900]));

        self::assertSame($first['policy'], $second['policy']);
    }

    public function test_trusted_root_is_recorded_in_the_details(): void
    {
        $runner = new FakeCommandRunner(new CommandResult(0, "dss-validator 6.2\n"), new CommandResult(0, AddedSignatures::REPORT_PREFIX.json_encode(['signatures' => [], 'timestamps' => []], JSON_THROW_ON_ERROR)));

        self::assertSame(self::ARTIFACT, self::observe(new EuDssValidator($runner, self::clock(), self::LTA, '/pki/root.der', self::ARTIFACT))['evidence']['details']['trusted_root']);
    }

    public function test_dss_without_a_readable_report_is_inconclusive(): void
    {
        $runner = new FakeCommandRunner(new CommandResult(0, "dss-validator 6.2\n"), new CommandResult(1, 'Exception in thread "main"'));

        self::assertSame('inconclusive', self::observe(new EuDssValidator($runner, self::clock(), self::LTA, '/pki/root.der', self::ARTIFACT))['verdict']);
    }

    public static function demoiselleReports(): iterable
    {
        $untrusted = 'O certificado CN=Signer A, O=SignerPHP Qualification Test PKI, C=BR não pertence a nenhuma cadeia confiável ou não há acesso internet para busca!';
        $check = ['invalid_signature' => false, 'policy' => '2.16.76.1.7.1.12.1.3', 'signer' => 'Signer A', 'timestamp' => true, 'errors' => [$untrusted], 'warnings' => ['a warning']];
        $signature = ['field' => 'Signature1', 'sub_filter' => 'ETSI.CAdES.detached', 'checks' => [$check]];

        yield 'AD-RT with only the untrusted chain' => [[$signature], 'valid'];
        yield 'no policy identifier' => [[['checks' => [['policy' => null] + $check]] + $signature], 'invalid'];
        yield 'AD-RB policy' => [[['checks' => [['policy' => '2.16.76.1.7.1.11.1.3'] + $check]] + $signature], 'invalid'];
        yield 'another error' => [[['checks' => [['errors' => [$untrusted, 'Atributo obrigatório ausente']] + $check]] + $signature], 'invalid'];
        yield 'invalid signature' => [[['checks' => [['invalid_signature' => true] + $check]] + $signature], 'invalid'];
        yield 'checker exception' => [[['field' => 'Signature1', 'error' => 'java.lang.RuntimeException: boom']], 'inconclusive'];
        yield 'no signature' => [[], 'invalid'];
    }

    #[DataProvider('demoiselleReports')]
    public function test_demoiselle_report_gives_the_verdict(array $signatures, string $verdict): void
    {
        $runner = new FakeCommandRunner(new CommandResult(0, "demoiselle-signer 4.4.0\n"), new CommandResult(0, AddedSignatures::REPORT_PREFIX.json_encode(['demoiselle_version' => '4.4.0', 'signatures' => $signatures], JSON_THROW_ON_ERROR)."\n"));
        $observation = self::observe(new DemoiselleValidator($runner, self::clock(), 1));

        self::assertSame($verdict, $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame(['id' => 'demoiselle-signer', 'revision' => ['kind' => 'version', 'value' => '4.4.0']], $observation['analyzer']);
    }

    public function test_demoiselle_without_a_readable_report_is_inconclusive(): void
    {
        $runner = new FakeCommandRunner(new CommandResult(0, "demoiselle-signer 4.4.0\n"), new CommandResult(null, '', timedOut: true));

        self::assertSame('inconclusive', self::observe(new DemoiselleValidator($runner, self::clock(), 1))['verdict']);
    }

    public function test_panel_refuses_a_test_pki_scenario_without_the_test_pki(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test PKI');

        ValidationPanel::installed(self::clock(), ['id' => 'pades-baseline-lta', 'signing' => self::LTA]);
    }

    private static function observe(ToolValidator $validator): array
    {
        $observation = $validator->observe(new ValidationSubject(self::ATTEMPT_ID, self::ARTIFACT, self::$output));
        (new SchemaValidator)->assertValid($observation, ToolValidator::OBSERVATION_SCHEMA, 'observation');

        return $observation;
    }

    private static function clock(): \Closure
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-23T12:00:00Z');
    }
}
