<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Pki\TestPki;
use SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic\MinimalPdf;
use SignerPHP\PdfSigningQualification\Validation\DocumentTimestampImprintValidator;
use SignerPHP\PdfSigningQualification\Validation\EuDssValidator;
use SignerPHP\PdfSigningQualification\Validation\OpenSslCmsValidator;
use SignerPHP\PdfSigningQualification\Validation\PadesStructureValidator;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\ToolValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class PadesPositiveControlTest extends TestCase
{
    private const string ATTEMPT_ID = 'run-20261125T120000Z/positive-control/pyhanko/synthetic:minimal.pdf';

    private const array BASELINE_B = [
        'digest_algorithm' => 'sha256',
        'signature_algorithm' => 'rsa-pkcs1-v1_5',
        'sub_filter' => 'ETSI.CAdES.detached',
        'appearance' => 'invisible',
        'timestamp' => false,
        'revocation_info' => false,
        'document_timestamp' => false,
        'signers' => ['valid'],
    ];

    private const array BASELINE_LTA = ['timestamp' => true, 'revocation_info' => true, 'document_timestamp' => true] + self::BASELINE_B;

    private static string $directory;

    private static TestPki $pki;

    private static string $input;

    /** @var array<string, string> */
    private static array $outputs = [];

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/positive-control-'.bin2hex(random_bytes(6));
        mkdir(self::$directory);
        self::$pki = TestPki::start(new ProcessRunner, self::$directory.'/pki');
        self::$input = MinimalPdf::create(self::$directory);
        file_put_contents(self::$directory.'/password', self::$pki->password());
        $root = (new ProcessRunner)->run(['openssl', 'x509', '-inform', 'DER', '-in', self::$pki->rootCertificatePath(), '-out', self::$directory.'/root.pem'], 30.0);
        self::assertSame(0, $root->exitCode, $root->output);
    }

    public static function tearDownAfterClass(): void
    {
        self::$pki->stop();
        exec('rm -rf '.escapeshellarg(self::$directory));
    }

    public function test_baseline_b_signature_is_valid_for_every_pades_analyzer(): void
    {
        $output = self::signed('baseline-b', ['--use-pades']);

        self::assertVerdict('valid', self::dss(self::BASELINE_B), $output);
        self::assertVerdict('valid', new PadesStructureValidator(new ProcessRunner, self::clock(), null, false), $output);
        self::assertVerdict('valid', new OpenSslCmsValidator(new ProcessRunner, self::clock()), $output);
    }

    public function test_baseline_lta_signature_and_its_document_timestamp_are_valid(): void
    {
        $output = self::baselineLta();

        self::assertVerdict('valid', self::dss(self::BASELINE_LTA), $output);
        self::assertVerdict('valid', new PadesStructureValidator(new ProcessRunner, self::clock(), null, true), $output);
        self::assertVerdict('valid', new DocumentTimestampImprintValidator(new ProcessRunner, self::clock()), $output);
        self::assertVerdict('valid', new OpenSslCmsValidator(new ProcessRunner, self::clock()), $output);
    }

    public function test_document_timestamp_whose_range_was_changed_is_invalid(): void
    {
        $bytes = (string) file_get_contents(self::baselineLta());
        preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $bytes, $ranges, PREG_SET_ORDER);
        usort($ranges, static fn (array $first, array $second): int => (int) $first[3] <=> (int) $second[3]);
        $approvalEnd = (int) $ranges[0][3] + (int) $ranges[0][4];
        $timestampGapStart = (int) end($ranges)[2];
        self::assertSame(1, preg_match('/stream\r?\n/', $bytes, $stream, PREG_OFFSET_CAPTURE, $approvalEnd));
        $changedOffset = $stream[0][1] + strlen($stream[0][0]);
        self::assertLessThan($timestampGapStart, $changedOffset);
        $bytes[$changedOffset] = chr(ord($bytes[$changedOffset]) ^ 1);
        $tampered = self::$directory.'/baseline-lta-changed.pdf';
        file_put_contents($tampered, $bytes);

        $observation = (new OpenSslCmsValidator(new ProcessRunner, self::clock()))->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe(self::$input), $tampered, self::$input));

        self::assertSame('invalid', $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame(['valid', 'invalid'], array_column($observation['evidence']['details']['signatures'], 'outcome'));
        self::assertStringContainsString('message imprint', $observation['evidence']['summary']);
        self::assertVerdict('invalid', new DocumentTimestampImprintValidator(new ProcessRunner, self::clock()), $tampered);
    }

    public function test_certification_signature_is_registered_in_perms(): void
    {
        $output = self::signed('certification', ['--use-pades', '--certify']);

        self::assertVerdict('valid', new PadesStructureValidator(new ProcessRunner, self::clock(), 2, false), $output);
        self::assertVerdict('invalid', new PadesStructureValidator(new ProcessRunner, self::clock(), null, false), $output);
    }

    public function test_signature_at_another_level_than_the_scenario_is_invalid(): void
    {
        $output = self::signed('baseline-b', ['--use-pades']);

        self::assertVerdict('invalid', self::dss(self::BASELINE_LTA), $output);
        self::assertVerdict('invalid', new PadesStructureValidator(new ProcessRunner, self::clock(), null, true), $output);
    }

    private static function baselineLta(): string
    {
        return self::signed('baseline-lta', ['--use-pades', '--timestamp-url', self::$pki->timestampUrl(), '--with-validation-info', '--use-pades-lta', '--trust', self::$directory.'/root.pem']);
    }

    /**
     * @param  list<string>  $options
     */
    private static function signed(string $name, array $options): string
    {
        if (isset(self::$outputs[$name])) {
            return self::$outputs[$name];
        }
        $output = self::$directory."/{$name}.pdf";
        $result = (new ProcessRunner)->run([
            'pyhanko', 'sign', 'addsig', '--field', 'Signature1', ...$options,
            'pkcs12', '--passfile', self::$directory.'/password', self::$input, $output, self::$directory.'/pki/signer-a.pfx',
        ], 300.0);
        self::assertSame(0, $result->exitCode, $result->output);

        return self::$outputs[$name] = $output;
    }

    private static function dss(array $signing): EuDssValidator
    {
        return new EuDssValidator(new ProcessRunner, self::clock(), $signing, self::$pki->rootCertificatePath(), self::$pki->rootCertificate());
    }

    private static function assertVerdict(string $verdict, ToolValidator $validator, string $output): void
    {
        $observation = $validator->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe(self::$input), $output, self::$input));

        self::assertSame($verdict, $observation['verdict'], $validator->analyzerId().': '.$observation['evidence']['summary']);
    }

    private static function clock(): Closure
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-25T12:00:00Z');
    }
}
