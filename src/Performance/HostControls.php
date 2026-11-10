<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Performance;

use RuntimeException;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class HostControls
{
    public const string SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/host-controls.schema.json';

    public static function record(Protocol $protocol, string $label, string $recordedAt, string $systemDirectory = '/sys', string $procDirectory = '/proc'): array
    {
        $host = $protocol->document['host'];
        $cpusetPath = $systemDirectory.'/fs/cgroup/cpuset.cpus.effective';
        $cpusetContents = self::read($cpusetPath);
        $cpuset = $cpusetContents === null || preg_match('/^[0-9]+(-[0-9]+)?(,[0-9]+(-[0-9]+)?)*$/', $cpusetContents) !== 1
            ? ['status' => 'unavailable', 'reason' => "Unable to read a CPU list from {$cpusetPath}"]
            : ['status' => 'available', 'cpus' => $cpusetContents];

        $cpus = self::expand($cpuset['status'] === 'available' ? $cpuset['cpus'] : $host['cpus']);
        $governors = [];
        foreach ($cpus as $cpu) {
            $governors[(string) $cpu] = self::read("{$systemDirectory}/devices/system/cpu/cpu{$cpu}/cpufreq/scaling_governor") ?? 'unavailable';
        }
        $boost = match (self::read($systemDirectory.'/devices/system/cpu/cpufreq/boost')) {
            '1' => 'enabled',
            '0' => 'disabled',
            default => 'unavailable',
        };
        $loadAverage = null;
        if (preg_match('/^([0-9.]+) ([0-9.]+) ([0-9.]+) /', self::read($procDirectory.'/loadavg') ?? '', $match) === 1) {
            $loadAverage = [(float) $match[1], (float) $match[2], (float) $match[3]];
        }

        $violations = [];
        if ($cpuset['status'] === 'unavailable') {
            $violations[] = "{$cpuset['reason']}, so the pinning to the CPUs {$host['cpus']} could not be checked";
        } elseif ($cpus !== self::expand($host['cpus'])) {
            $violations[] = "The sandbox runs on the CPUs {$cpuset['cpus']}, not on the CPUs {$host['cpus']} of the protocol";
        }
        foreach ($governors as $cpu => $governor) {
            if ($governor !== $host['scaling_governor']) {
                $violations[] = "The scaling governor of CPU {$cpu} is {$governor}, not {$host['scaling_governor']}";
            }
        }
        if ($boost !== $host['boost']) {
            $violations[] = "The frequency boost is {$boost}, not {$host['boost']}";
        }

        $controls = [
            'schema' => self::SCHEMA,
            'label' => $label,
            'recorded_at' => $recordedAt,
            'cpuset' => $cpuset,
            'scaling_governors' => (object) $governors,
            'boost' => $boost,
            'load_average' => $loadAverage,
            'violations' => $violations,
        ];
        (new SchemaValidator)->assertValid($controls, self::SCHEMA, 'host controls');
        $controls['scaling_governors'] = $governors;

        return $controls;
    }

    /**
     * @return list<int>
     */
    public static function expand(string $cpuList): array
    {
        $cpus = [];
        foreach (explode(',', $cpuList) as $range) {
            $bounds = array_map(intval(...), explode('-', $range));
            $last = $bounds[1] ?? $bounds[0];
            if ($last < $bounds[0]) {
                throw new RuntimeException("The CPU range {$range} ends before it starts");
            }
            array_push($cpus, ...range($bounds[0], $last));
        }
        $cpus = array_values(array_unique($cpus));
        sort($cpus);

        return $cpus;
    }

    private static function read(string $path): ?string
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;
        if ($contents === false || trim($contents) === '') {
            return null;
        }

        return trim($contents);
    }
}
