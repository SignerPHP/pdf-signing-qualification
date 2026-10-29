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

    public function __construct(
        private readonly string $installationDirectory = '/opt/implementations/pdf-signer',
    ) {}

    public function id(): string
    {
        return 'pdf-signer';
    }

    public function revision(): array
    {
        $installed = require $this->installationDirectory.'/vendor/composer/installed.php';
        $commit = is_array($installed) && is_array($installed['root'] ?? null) ? ($installed['root']['reference'] ?? null) : null;
        if (! is_string($commit) || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            throw new RuntimeException("Unable to determine the pdf-signer commit installed in {$this->installationDirectory}");
        }

        return ['kind' => 'commit', 'value' => $commit];
    }

    public function supports(array $signing): bool
    {
        ksort($signing);
        $supported = self::SUPPORTED_SIGNING;
        ksort($supported);

        return $signing === $supported;
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
            '--no-default-appearance',
            '--no-default-timestamp',
        ];
    }
}
