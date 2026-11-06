<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Run\Environment;
use SignerPHP\PdfSigningQualification\Signing\ResourceLimits;

final class EnvironmentTest extends TestCase
{
    public function test_resource_limits_come_from_the_runner(): void
    {
        $environment = Environment::describe(false, new ResourceLimits(timeoutSeconds: 30.0, memoryBytes: 2048));

        self::assertSame(['timeout_seconds' => 30.0, 'address_space_bytes' => 2048, 'file_size_bytes' => 268_435_456, 'storage_bytes' => 536_870_912, 'open_files' => 256], $environment['resource_limits']);
    }

    public function test_environment_does_not_assume_a_runtime_that_the_implementation_may_not_use(): void
    {
        self::assertArrayNotHasKey('java_version', Environment::describe(false, new ResourceLimits));
    }
}
