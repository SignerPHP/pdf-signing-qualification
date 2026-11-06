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

    private const array ENVIRONMENT_VARIABLES = ['MALLOC_ARENA_MAX=1'];

    private const string ENGINE = 'openpdf';

    public function __construct(
        private readonly string $installationDirectory = '/opt/implementations/jsignpdf',
        private readonly string $signingScript = '/opt/implementations/jsignpdf-sign.php',
        private readonly string $javaBinary = '/opt/java/bin/java',
        private readonly string $wrapperDirectory = '/opt/implementations/jsignpdf-php',
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

    public function runtime(): array
    {
        return [
            'java_version' => $this->javaVersion(),
            'java_options' => self::JAVA_OPTIONS,
            'environment_variables' => self::ENVIRONMENT_VARIABLES,
            'engine' => self::ENGINE,
            'wrapper' => ['id' => 'jsignpdf-php', 'revision' => $this->wrapperRevision()],
        ];
    }

    public function command(SigningRequest $request): array
    {
        $attemptDirectory = dirname($request->outputPath);
        $javaOptions = [...self::JAVA_OPTIONS, '-Djava.io.tmpdir='.$attemptDirectory, '-Duser.home='.$attemptDirectory];

        return [
            'env', ...self::ENVIRONMENT_VARIABLES,
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
            '--engine='.self::ENGINE,
        ];
    }

    private function javaVersion(): string
    {
        $releasePath = dirname($this->javaBinary, 2).'/release';
        $release = is_file($releasePath) ? parse_ini_file($releasePath, false, INI_SCANNER_RAW) : false;
        $version = is_array($release) ? ($release['JAVA_RUNTIME_VERSION'] ?? null) : null;
        if (! is_string($version) || trim($version, " \"'") === '') {
            throw new RuntimeException("Unable to read the Java version from {$releasePath}");
        }

        return trim($version, " \"'");
    }

    /**
     * @return array{kind: 'commit', value: string}
     */
    private function wrapperRevision(): array
    {
        $installedPath = $this->wrapperDirectory.'/vendor/composer/installed.php';
        $installed = is_file($installedPath) ? require $installedPath : null;
        $commit = is_array($installed) && is_array($installed['root'] ?? null) ? ($installed['root']['reference'] ?? null) : null;
        if (! is_string($commit) || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            throw new RuntimeException("Unable to determine the jsignpdf-php commit installed in {$this->wrapperDirectory}");
        }

        return ['kind' => 'commit', 'value' => $commit];
    }
}
