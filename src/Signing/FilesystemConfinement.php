<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

use RuntimeException;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;

final class FilesystemConfinement
{
    public const string MECHANISM = 'landlock';

    public const int MINIMUM_ABI = 3;

    public const array SYSTEM_DIRECTORIES = ['/bin', '/dev', '/etc', '/lib', '/lib64', '/opt', '/proc', '/sbin', '/sys', '/usr'];

    public const array WRITABLE_PATHS = ['/dev/null'];

    private const float PROBE_TIMEOUT_SECONDS = 30.0;

    private ?int $abi = null;

    /**
     * @param  list<string>  $readableDirectories
     * @param  list<string>  $writablePaths
     */
    public function __construct(
        private readonly CommandRunner $runner = new ProcessRunner,
        private readonly array $readableDirectories = self::SYSTEM_DIRECTORIES,
        private readonly array $writablePaths = self::WRITABLE_PATHS,
        private readonly string $measurementProcess = AttemptRunner::MEASUREMENT_PROCESS,
    ) {}

    public function assertAvailable(): void
    {
        $this->abi();
    }

    /**
     * @return array{mechanism: string, landlock_abi: int, readable_directories: list<string>, writable_paths: list<string>}
     */
    public function record(): array
    {
        return [
            'mechanism' => self::MECHANISM,
            'landlock_abi' => $this->abi(),
            'readable_directories' => $this->existing($this->readableDirectories),
            'writable_paths' => $this->existing($this->writablePaths),
        ];
    }

    /**
     * @param  list<string>  $readablePaths
     * @param  list<string>  $writablePaths
     * @return list<string>
     */
    public function arguments(string $workDirectory, array $readablePaths, array $writablePaths = []): array
    {
        $arguments = [];
        foreach ([...$this->existing($this->readableDirectories), ...$readablePaths] as $path) {
            array_push($arguments, '--read', $path);
        }
        foreach ([...$this->existing($this->writablePaths), $workDirectory, ...$writablePaths] as $path) {
            array_push($arguments, '--write', $path);
        }

        return $arguments;
    }

    private function abi(): int
    {
        if ($this->abi !== null) {
            return $this->abi;
        }

        $result = $this->runner->run(['python3', '-I', $this->measurementProcess, '--landlock-abi'], self::PROBE_TIMEOUT_SECONDS);
        $output = trim($result->output);
        if ($result->timedOut || $result->exitCode !== 0 || preg_match('/^[0-9]+$/', $output) !== 1) {
            throw new RuntimeException('Attempts must be confined with Landlock, but the probe '.$result->describe().($output === '' ? '' : ": {$output}").'. Run them on a kernel with Landlock enabled.');
        }
        $abi = (int) $output;
        if ($abi < self::MINIMUM_ABI) {
            throw new RuntimeException('Attempts must be confined with Landlock ABI '.self::MINIMUM_ABI." or later, which controls truncation, but the kernel offers ABI {$abi}");
        }

        return $this->abi = $abi;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function existing(array $paths): array
    {
        return array_values(array_filter($paths, file_exists(...)));
    }
}
