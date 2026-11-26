<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Signing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Signing\JSignPdfImplementation;
use SignerPHP\PdfSigningQualification\Signing\PdfSignerImplementation;
use SignerPHP\PdfSigningQualification\Signing\Scenarios;
use SignerPHP\PdfSigningQualification\Signing\SigningRequest;
use SignerPHP\PdfSigningQualification\Signing\TestCredential;

final class PdfSignerPadesScenariosTest extends TestCase
{
    private const string SCENARIO_DIRECTORY = __DIR__.'/../../config/scenarios';

    private const string SIGN = '/opt/implementations/pdf-signer/bin/signer-sign';

    private const string TSA = 'http://127.0.0.1:8080/tsa';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/pades-scenarios-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        touch($this->directory.'/a.p12');
        touch($this->directory.'/b.p12');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    public static function versionThreeScenarios(): iterable
    {
        foreach (Scenarios::ids(self::SCENARIO_DIRECTORY) as $id) {
            if (str_starts_with($id, 'basic-')) {
                continue;
            }
            yield $id => [$id];
        }
    }

    #[DataProvider('versionThreeScenarios')]
    public function test_pdf_signer_supports_every_test_pki_scenario_and_jsignpdf_none(string $id): void
    {
        $signing = Scenarios::load(self::SCENARIO_DIRECTORY, $id)['signing'];

        self::assertTrue((new PdfSignerImplementation)->supports($signing));
        self::assertFalse((new JSignPdfImplementation)->supports($signing));
    }

    public static function commands(): iterable
    {
        $common = static fn (string $input, string $output, string $credential): array => ['php', '-d', 'memory_limit=-1', self::SIGN, "--input={$input}", "--output={$output}", "--cert={$credential}", '--password=secret', '--no-default-appearance'];

        yield 'baseline B' => ['pades-baseline-b', [[...$common('in.pdf', 'out/output.pdf', 'a.p12'), '--no-default-timestamp', '--pades-baseline-b']]];
        yield 'baseline T' => ['pades-baseline-t', [[...$common('in.pdf', 'out/output.pdf', 'a.p12'), '--no-default-timestamp', '--pades-baseline-t', '--timestamp-url='.self::TSA]]];
        yield 'baseline LT' => ['pades-baseline-lt', [[...$common('in.pdf', 'out/output.pdf', 'a.p12'), '--no-default-timestamp', '--pades-baseline-lt', '--timestamp-url='.self::TSA]]];
        yield 'baseline LTA' => ['pades-baseline-lta', [[...$common('in.pdf', 'out/output.pdf', 'a.p12'), '--no-default-timestamp', '--pades-baseline-lta', '--timestamp-url='.self::TSA]]];
        yield 'certification' => ['pades-certification-docmdp-2', [[...$common('in.pdf', 'out/output.pdf', 'a.p12'), '--no-default-timestamp', '--pades-baseline-b', '--certification-level=2']]];
        yield 'ICP-Brasil AD-RT' => ['icp-brasil-ad-rt', [[...$common('in.pdf', 'out/output.pdf', 'a.p12'), '--policy=br-iti', '--policy-tsa-url='.self::TSA]]];
        yield 'two approvals' => ['pades-two-approval-signatures', [
            [...$common('in.pdf', 'out/signature-1.pdf', 'a.p12'), '--no-default-timestamp', '--pades-baseline-b'],
            [...$common('out/signature-1.pdf', 'out/output.pdf', 'b.p12'), '--no-default-timestamp', '--pades-baseline-b'],
        ]];
        yield 'certification then approval' => ['pades-certification-then-approval', [
            [...$common('in.pdf', 'out/signature-1.pdf', 'a.p12'), '--no-default-timestamp', '--pades-baseline-b', '--certification-level=2'],
            [...$common('out/signature-1.pdf', 'out/output.pdf', 'b.p12'), '--no-default-timestamp', '--pades-baseline-b'],
        ]];
    }

    /**
     * @param  list<list<string>>  $expected
     */
    #[DataProvider('commands')]
    public function test_command_follows_the_scenario(string $id, array $expected): void
    {
        $signing = Scenarios::load(self::SCENARIO_DIRECTORY, $id)['signing'];
        $credentials = [TestCredential::existing($this->directory.'/a.p12', 'secret'), TestCredential::existing($this->directory.'/b.p12', 'secret')];
        $request = new SigningRequest('in.pdf', 'out/output.pdf', $credentials[0], $signing, array_slice($credentials, 1, count($signing['signers']) - 1), self::TSA);
        $expected = array_map(fn (array $command): array => array_map(fn (string $argument): string => str_replace(['=a.p12', '=b.p12'], ['='.$this->directory.'/a.p12', '='.$this->directory.'/b.p12'], $argument), $command), $expected);

        $command = (new PdfSignerImplementation)->command($request);

        if (count($expected) === 1) {
            self::assertSame($expected[0], $command);
        } else {
            self::assertSame(['sh', '-ec', implode(' && ', array_map(static fn (array $part): string => implode(' ', array_map(escapeshellarg(...), $part)), $expected))], $command);
        }
    }

    public function test_icp_brasil_preset_is_supported_only_as_the_preset_gives_it(): void
    {
        $signing = Scenarios::load(self::SCENARIO_DIRECTORY, 'icp-brasil-ad-rt')['signing'];

        self::assertFalse((new PdfSignerImplementation)->supports(['certification_level' => 1] + $signing));
        self::assertFalse((new PdfSignerImplementation)->supports(['signers' => ['valid', 'valid']] + $signing));
        self::assertFalse((new PdfSignerImplementation)->supports(['document_timestamp' => false] + $signing));
    }

    public function test_compatibility_of_a_test_pki_scenario_needs_its_options(): void
    {
        $signing = Scenarios::load(self::SCENARIO_DIRECTORY, 'pades-baseline-lta')['signing'];
        $options = (new PdfSignerImplementation)->compatibilityCheck($signing)['options'];

        foreach (['--pades-baseline-lta', '--timestamp-url', '--certification-level', '--policy', '--policy-tsa-url'] as $option) {
            self::assertContains($option, $options);
        }
        self::assertNotContains('--pades-baseline-lta', (new PdfSignerImplementation)->compatibilityCheck()['options']);
    }
}
