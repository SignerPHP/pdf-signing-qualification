<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Performance;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Performance\HostControls;

final class HostControlsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/host-controls-'.bin2hex(random_bytes(6));
        $this->host('4-7', [4 => 'performance', 5 => 'performance', 6 => 'performance', 7 => 'performance'], '0', "0.52 0.71 0.90 1/321 4567\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    /**
     * @param  array<int, string>  $governors
     */
    private function host(?string $cpuset, array $governors, ?string $boost, ?string $loadAverage): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
        mkdir($this->directory.'/sys/fs/cgroup', 0777, true);
        mkdir($this->directory.'/sys/devices/system/cpu/cpufreq', 0777, true);
        mkdir($this->directory.'/proc');
        if ($cpuset !== null) {
            file_put_contents($this->directory.'/sys/fs/cgroup/cpuset.cpus.effective', $cpuset."\n");
        }
        foreach ($governors as $cpu => $governor) {
            mkdir($this->directory."/sys/devices/system/cpu/cpu{$cpu}/cpufreq", 0777, true);
            file_put_contents($this->directory."/sys/devices/system/cpu/cpu{$cpu}/cpufreq/scaling_governor", $governor."\n");
        }
        if ($boost !== null) {
            file_put_contents($this->directory.'/sys/devices/system/cpu/cpufreq/boost', $boost."\n");
        }
        if ($loadAverage !== null) {
            file_put_contents($this->directory.'/proc/loadavg', $loadAverage);
        }
    }

    private function record(): array
    {
        return HostControls::record(ProtocolTest::protocol(), '01-pdf-signer', '2026-11-09T12:00:00Z', $this->directory.'/sys', $this->directory.'/proc');
    }

    public function test_host_that_meets_the_protocol_has_no_violation(): void
    {
        $controls = $this->record();

        self::assertSame(['status' => 'available', 'cpus' => '4-7'], $controls['cpuset']);
        self::assertSame([4 => 'performance', 5 => 'performance', 6 => 'performance', 7 => 'performance'], $controls['scaling_governors']);
        self::assertSame('disabled', $controls['boost']);
        self::assertSame([0.52, 0.71, 0.9], $controls['load_average']);
        self::assertSame([], $controls['violations']);
    }

    public function test_cpus_other_than_those_of_the_protocol_are_a_violation(): void
    {
        $this->host('0-15', [], '0', null);

        self::assertContains('The sandbox runs on the CPUs 0-15, not on the CPUs 4-7 of the protocol', $this->record()['violations']);
    }

    public function test_cpuset_written_as_a_list_matches_the_same_cpus_as_a_range(): void
    {
        $this->host('4,5,6-7', [4 => 'performance', 5 => 'performance', 6 => 'performance', 7 => 'performance'], '0', null);

        self::assertSame([], $this->record()['violations']);
    }

    public function test_unreadable_cpuset_is_unavailable_and_a_violation(): void
    {
        $this->host(null, [4 => 'performance', 5 => 'performance', 6 => 'performance', 7 => 'performance'], '0', null);
        $controls = $this->record();

        self::assertSame('unavailable', $controls['cpuset']['status']);
        self::assertStringContainsString('could not be checked', $controls['violations'][0]);
        self::assertSame(['4', '5', '6', '7'], array_map(strval(...), array_keys($controls['scaling_governors'])));
    }

    public function test_governor_other_than_that_of_the_protocol_is_a_violation_per_cpu(): void
    {
        $this->host('4-7', [4 => 'performance', 5 => 'powersave', 6 => 'performance'], '0', null);
        $controls = $this->record();

        self::assertSame('unavailable', $controls['scaling_governors']['7']);
        self::assertSame([
            'The scaling governor of CPU 5 is powersave, not performance',
            'The scaling governor of CPU 7 is unavailable, not performance',
        ], $controls['violations']);
    }

    public function test_boost_enabled_or_unreadable_is_a_violation(): void
    {
        $this->host('4-7', [4 => 'performance', 5 => 'performance', 6 => 'performance', 7 => 'performance'], '1', null);
        self::assertSame(['The frequency boost is enabled, not disabled'], $this->record()['violations']);

        $this->host('4-7', [4 => 'performance', 5 => 'performance', 6 => 'performance', 7 => 'performance'], null, null);
        self::assertSame(['The frequency boost is unavailable, not disabled'], $this->record()['violations']);
    }

    public function test_unreadable_load_average_is_null(): void
    {
        $this->host('4-7', [4 => 'performance', 5 => 'performance', 6 => 'performance', 7 => 'performance'], '0', null);

        self::assertNull($this->record()['load_average']);
    }
}
