<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Pki;

use InvalidArgumentException;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Signing\TestCredential;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;

/**
 * The test PKI of one run: generated in the run's scratch directory by /opt/test-pki/generate.sh
 * and served on the loopback interface by /opt/test-pki/server.php, so the sandbox keeps no
 * network interface other than loopback. Its keys are generated at run time and removed with
 * the scratch directory; nothing of it is a real certificate.
 */
final class TestPki
{
    public const string DIRECTORY = '/opt/test-pki';

    private const float GENERATION_TIMEOUT_SECONDS = 120.0;

    private const float STARTUP_TIMEOUT_SECONDS = 10.0;

    private const array CERTIFICATES = ['valid' => ['signer-a', 'signer-b'], 'revoked' => ['signer-revoked']];

    /**
     * @param  resource|null  $server
     */
    private function __construct(
        private readonly string $directory,
        private readonly string $baseUrl,
        private readonly string $password,
        private mixed $server,
    ) {}

    public static function start(CommandRunner $runner, string $directory, string $scripts = self::DIRECTORY): self
    {
        $port = self::freePort();
        $baseUrl = "http://127.0.0.1:{$port}";
        $password = bin2hex(random_bytes(16));

        $result = $runner->run(['env', "PKI_PASSWORD={$password}", $scripts.'/generate.sh', $directory, $baseUrl], self::GENERATION_TIMEOUT_SECONDS);
        if ($result->timedOut || $result->exitCode !== 0 || ! is_file($directory.'/.done')) {
            throw new RuntimeException("Unable to generate the test PKI in {$directory}: {$result->describe()}: {$result->output}");
        }

        $server = proc_open(
            ['setsid', 'php', '-S', "127.0.0.1:{$port}", $scripts.'/server.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PKI_DIR' => $directory, 'PHP_CLI_SERVER_WORKERS' => '4', 'PATH' => (string) getenv('PATH')],
        );
        if (! is_resource($server)) {
            throw new RuntimeException('Unable to start the test PKI server');
        }
        $pki = new self($directory, $baseUrl, $password, $server);

        $deadline = microtime(true) + self::STARTUP_TIMEOUT_SECONDS;
        while (@file_get_contents($baseUrl.'/health') !== 'ok') {
            if (microtime(true) > $deadline || ! proc_get_status($server)['running']) {
                $pki->stop();

                throw new RuntimeException("The test PKI server did not answer at {$baseUrl} within ".self::STARTUP_TIMEOUT_SECONDS.' seconds');
            }
            usleep(100_000);
        }

        return $pki;
    }

    public function timestampUrl(): string
    {
        return $this->baseUrl.'/tsa';
    }

    public function rootCertificatePath(): string
    {
        return $this->directory.'/root.der';
    }

    /**
     * @return array{sha256: string, byte_size: int}
     */
    public function rootCertificate(): array
    {
        return Artifact::describe($this->rootCertificatePath());
    }

    public function password(): string
    {
        return $this->password;
    }

    /**
     * The credentials of the signers in order: the first and second `valid` signers get two
     * distinct certificates, a `revoked` signer the certificate revoked before anything is signed.
     *
     * @param  list<string>  $signers
     * @return list<TestCredential>
     */
    public function credentials(array $signers): array
    {
        $used = [];
        $credentials = [];
        foreach ($signers as $signer) {
            $names = self::CERTIFICATES[$signer] ?? throw new InvalidArgumentException("Unknown signer certificate state {$signer}");
            $index = $used[$signer] ?? 0;
            $name = $names[$index] ?? throw new InvalidArgumentException("The test PKI has no more {$signer} certificates");
            $used[$signer] = $index + 1;
            $credentials[] = TestCredential::existing($this->directory.'/'.$name.'.pfx', $this->password);
        }

        return $credentials;
    }

    public function stop(): void
    {
        if (! is_resource($this->server)) {
            return;
        }
        $status = proc_get_status($this->server);
        if ($status['running'] && function_exists('posix_kill')) {
            posix_kill(-$status['pid'], 15);
        }
        proc_terminate($this->server);
        proc_close($this->server);
        $this->server = null;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException("Unable to find a free loopback port: {$errorMessage}");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
