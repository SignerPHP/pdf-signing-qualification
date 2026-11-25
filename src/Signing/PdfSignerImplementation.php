<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

use RuntimeException;

final class PdfSignerImplementation implements SigningImplementation
{
    private const array SUPPORTED_SIGNING = [
        'digest_algorithm' => 'sha256',
        'signature_algorithm' => 'rsa-pkcs1-v1_5',
        'sub_filter' => 'adbe.pkcs7.detached',
        'appearance' => 'invisible',
        'timestamp' => false,
        'revocation_info' => false,
    ];

    private const array DEFAULT_APPEARANCE_PLACEMENT = ['page' => 1, 'left' => 36, 'top' => 36, 'width' => 240, 'height' => 84];

    private const array COMMAND_OPTIONS = ['--input', '--output', '--cert', '--password', '--no-default-appearance', '--no-default-timestamp'];

    private const array PADES_OPTIONS = ['--pades-baseline-b', '--pades-baseline-t', '--pades-baseline-lt', '--pades-baseline-lta', '--timestamp-url', '--certification-level', '--policy', '--policy-tsa-url'];

    private const array PADES_SIGNING = [
        'digest_algorithm' => 'sha256',
        'signature_algorithm' => 'rsa-pkcs1-v1_5',
        'sub_filter' => 'ETSI.CAdES.detached',
        'appearance' => 'invisible',
    ];

    public function __construct(
        private readonly string $installationDirectory = '/opt/implementations/pdf-signer',
    ) {}

    public function id(): string
    {
        return 'pdf-signer';
    }

    public function revision(): array
    {
        $installedPath = $this->installationDirectory.'/vendor/composer/installed.php';
        $installed = is_file($installedPath) ? require $installedPath : null;
        $commit = is_array($installed) && is_array($installed['root'] ?? null) ? ($installed['root']['reference'] ?? null) : null;
        if (! is_string($commit) || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            throw new RuntimeException("Unable to determine the pdf-signer commit installed in {$this->installationDirectory}");
        }

        return ['kind' => 'commit', 'value' => $commit];
    }

    public function supports(array $signing): bool
    {
        if (array_key_exists('signers', $signing)) {
            return self::supportsPades($signing);
        }

        $visible = self::SUPPORTED_SIGNING;
        $visible['appearance'] = 'visible';
        $visible['placement'] = self::DEFAULT_APPEARANCE_PLACEMENT;

        return SupportedSigning::matches($signing, [self::SUPPORTED_SIGNING, $visible]);
    }

    public function runtime(): array
    {
        return [];
    }

    public function command(SigningRequest $request): array
    {
        if (! array_key_exists('signers', $request->signing)) {
            return [
                'php',
                '-d', 'memory_limit=-1',
                $this->installationDirectory.'/bin/signer-sign',
                '--input='.$request->inputPath,
                '--output='.$request->outputPath,
                '--cert='.$request->credential->pkcs12Path,
                '--password='.$request->credential->password,
                ...($request->signing['appearance'] === 'visible' ? [] : ['--no-default-appearance']),
                '--no-default-timestamp',
            ];
        }

        $credentials = [$request->credential, ...$request->additionalCredentials];
        $commands = [];
        foreach ($credentials as $index => $credential) {
            $last = $index === count($credentials) - 1;
            $commands[] = $this->padesCommand(
                $request,
                $index === 0 ? $request->inputPath : self::intermediatePath($request, $index),
                $last ? $request->outputPath : self::intermediatePath($request, $index + 1),
                $credential,
                $index === 0,
            );
        }

        return SequentialCommand::of($commands);
    }

    public function compatibilityCheck(?array $signing = null): array
    {
        return [
            'command' => ['php', '-d', 'memory_limit=-1', $this->installationDirectory.'/bin/signer-sign', '--help'],
            'options' => $signing !== null && array_key_exists('signers', $signing) ? [...self::COMMAND_OPTIONS, ...self::PADES_OPTIONS] : self::COMMAND_OPTIONS,
        ];
    }

    private static function supportsPades(array $signing): bool
    {
        foreach (self::PADES_SIGNING as $key => $value) {
            if (($signing[$key] ?? null) !== $value) {
                return false;
            }
        }
        $policy = $signing['signature_policy'] ?? null;
        if ($policy === null) {
            return true;
        }

        return $policy === 'icp-brasil-ad-rt'
            && $signing['signers'] === ['valid']
            && ($signing['certification_level'] ?? null) === 2
            && $signing['timestamp'] === true
            && $signing['revocation_info'] === true
            && $signing['document_timestamp'] === true;
    }

    /**
     * @return list<string>
     */
    private function padesCommand(SigningRequest $request, string $inputPath, string $outputPath, TestCredential $credential, bool $first): array
    {
        $signing = $request->signing;
        $command = [
            'php',
            '-d', 'memory_limit=-1',
            $this->installationDirectory.'/bin/signer-sign',
            '--input='.$inputPath,
            '--output='.$outputPath,
            '--cert='.$credential->pkcs12Path,
            '--password='.$credential->password,
            '--no-default-appearance',
        ];
        $timestampUrl = $request->timestampUrl ?? throw new RuntimeException('A test-PKI scenario needs the timestamp URL of the test PKI');
        if (($signing['signature_policy'] ?? null) === 'icp-brasil-ad-rt') {
            return [...$command, '--policy=br-iti', '--policy-tsa-url='.$timestampUrl];
        }

        $command[] = '--no-default-timestamp';
        $command[] = match (true) {
            ($signing['document_timestamp'] ?? false) === true => '--pades-baseline-lta',
            ($signing['revocation_info'] ?? false) === true => '--pades-baseline-lt',
            ($signing['timestamp'] ?? false) === true => '--pades-baseline-t',
            default => '--pades-baseline-b',
        };
        if (($signing['timestamp'] ?? false) === true) {
            $command[] = '--timestamp-url='.$timestampUrl;
        }
        if ($first && isset($signing['certification_level'])) {
            $command[] = '--certification-level='.$signing['certification_level'];
        }

        return $command;
    }

    private static function intermediatePath(SigningRequest $request, int $signature): string
    {
        return dirname($request->outputPath)."/signature-{$signature}.pdf";
    }
}
