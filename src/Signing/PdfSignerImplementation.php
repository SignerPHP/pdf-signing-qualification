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

    public function compatibilityCheck(): array
    {
        return [
            'command' => ['php', '-d', 'memory_limit=-1', $this->installationDirectory.'/bin/signer-sign', '--help'],
            'options' => self::COMMAND_OPTIONS,
        ];
    }
}
