<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Run\Environment;
use SignerPHP\PdfSigningQualification\Signing\ResourceLimits;

final class EnvironmentTest extends TestCase
{
    /** @var list<string> */
    private array $cpuInfoPaths = [];

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->cpuInfoPaths);
    }

    public function test_resource_limits_come_from_the_runner(): void
    {
        $environment = Environment::describe(false, new ResourceLimits(timeoutSeconds: 30.0, memoryBytes: 2048));

        self::assertSame(['timeout_seconds' => 30.0, 'address_space_bytes' => 2048, 'file_size_bytes' => 268_435_456, 'storage_bytes' => 536_870_912, 'open_files' => 256], $environment['resource_limits']);
    }

    public function test_environment_does_not_assume_a_runtime_that_the_implementation_may_not_use(): void
    {
        self::assertArrayNotHasKey('java_version', Environment::describe(false, new ResourceLimits));
    }

    public function test_cpu_model_is_read_from_the_host(): void
    {
        $cpuInfoPath = $this->cpuInfo("processor\t: 0\nvendor_id\t: AuthenticAMD\nmodel name\t: AMD Ryzen 7 3700X 8-Core Processor\n\nprocessor\t: 1\nmodel name\t: AMD Ryzen 7 3700X 8-Core Processor\n");

        $environment = Environment::describe(false, new ResourceLimits, cpuInfoPath: $cpuInfoPath);

        self::assertSame(['status' => 'available', 'name' => 'AMD Ryzen 7 3700X 8-Core Processor'], $environment['cpu_model']);
    }

    public function test_cpu_model_that_the_host_does_not_report_is_unavailable_with_its_reason(): void
    {
        $cpuInfoPath = $this->cpuInfo("processor\t: 0\nBogoMIPS\t: 50.00\nCPU implementer\t: 0x41\n");

        $environment = Environment::describe(false, new ResourceLimits, cpuInfoPath: $cpuInfoPath);

        self::assertSame(['status' => 'unavailable', 'reason' => "{$cpuInfoPath} has no model name"], $environment['cpu_model']);
    }

    public function test_cpu_model_is_unavailable_when_the_host_information_cannot_be_read(): void
    {
        $environment = Environment::describe(false, new ResourceLimits, cpuInfoPath: '/nonexistent/cpuinfo');

        self::assertSame(['status' => 'unavailable', 'reason' => 'Unable to read /nonexistent/cpuinfo'], $environment['cpu_model']);
    }

    private function cpuInfo(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cpuinfo-');
        file_put_contents($path, $contents);
        $this->cpuInfoPaths[] = $path;

        return $path;
    }
}
