<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Run\Environment;
use SignerPHP\PdfSigningQualification\Signing\ResourceLimits;

final class EnvironmentTest extends TestCase
{
    public function test_java_version_and_resource_limits_come_from_the_installation_and_the_runner(): void
    {
        $environment = Environment::describe(false, new ResourceLimits(timeoutSeconds: 30.0, memoryBytes: 2048));

        self::assertSame('21.0.13+9-LTS', $environment['java_version']);
        self::assertSame(['timeout_seconds' => 30.0, 'address_space_bytes' => 2048, 'file_size_bytes' => 268_435_456, 'storage_bytes' => 536_870_912, 'open_files' => 256], $environment['resource_limits']);
    }

    public function test_unreadable_java_version_stops_the_run_instead_of_being_guessed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read the Java version from /nonexistent/release');

        Environment::describe(false, new ResourceLimits, javaReleasePath: '/nonexistent/release');
    }
}
