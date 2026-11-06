<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

use RuntimeException;

final class JSignPdfImplementation implements SigningImplementation
{
    private const array SUPPORTED_SIGNING = [
        'digest_algorithm' => 'sha256',
        'signature_algorithm' => 'rsa-pkcs1-v1_5',
        'sub_filter' => 'adbe.pkcs7.detached',
        'appearance' => 'invisible',
        'timestamp' => false,
        'revocation_info' => false,
    ];

    private const array JAVA_OPTIONS = [
        '-Xmx512m',
        '-XX:+UseSerialGC',
        '-XX:CompressedClassSpaceSize=64m',
        '-XX:ReservedCodeCacheSize=48m',
        '-XX:-UsePerfData',
    ];

    public function __construct(
        private readonly string $installationDirectory = '/opt/implementations/jsignpdf',
        private readonly string $signingScript = '/opt/implementations/jsignpdf-sign.php',
        private readonly string $javaBinary = '/opt/java/bin/java',
    ) {}

    public function id(): string
    {
        return 'jsignpdf';
    }

    public function revision(): array
    {
        $versions = [];
        foreach ((array) glob($this->installationDirectory.'/lib/jsignpdf-*.jar') as $path) {
            if (preg_match('/^jsignpdf-(\d+\.\d+\.\d+)\.jar$/', basename((string) $path), $matches) === 1) {
                $versions[] = $matches[1];
            }
        }
        if (count($versions) !== 1) {
            throw new RuntimeException("Unable to determine the JSignPdf version installed in {$this->installationDirectory}");
        }

        return ['kind' => 'version', 'value' => $versions[0]];
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
        $javaOptions = [...self::JAVA_OPTIONS, '-Djava.io.tmpdir='.dirname($request->outputPath)];

        return [
            'env', 'MALLOC_ARENA_MAX=1',
            'php',
            '-d', 'memory_limit=-1',
            $this->signingScript,
            '--input='.$request->inputPath,
            '--output='.$request->outputPath,
            '--cert='.$request->credential->pkcs12Path,
            '--password='.$request->credential->password,
            '--java='.$this->javaBinary,
            '--jsignpdf='.$this->installationDirectory,
            ...array_map(static fn (string $option): string => '--java-option='.$option, $javaOptions),
            '--hash-algorithm=SHA256',
            '--engine=openpdf',
        ];
    }
}
