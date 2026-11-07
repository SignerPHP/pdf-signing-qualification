<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Acquisition;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Acquisition\Input;
use SignerPHP\PdfSigningQualification\Acquisition\SourceConfiguration;

final class PilotSampleTest extends TestCase
{
    private const string CONFIGURATION = __DIR__.'/../../config/inputs.json';

    private const string DOCUMENT = __DIR__.'/../../docs/pilot-sample.md';

    private const array FREE_LICENSES = ['Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'CC-BY-4.0', 'CC0-1.0', 'MIT', 'MPL-2.0'];

    /**
     * @return list<string>
     */
    private static function configuredIds(): array
    {
        $ids = array_map(static fn (Input $input): string => $input->id(), SourceConfiguration::fromFile(self::CONFIGURATION));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function documentedIds(): array
    {
        preg_match_all('/^\| `([a-z0-9-]+:[^`]+)` \|/m', (string) file_get_contents(self::DOCUMENT), $matches);
        $ids = $matches[1];
        sort($ids);

        return $ids;
    }

    public function test_sample_size_is_within_the_pilot_range(): void
    {
        $count = count(self::configuredIds());

        self::assertGreaterThanOrEqual(20, $count);
        self::assertLessThanOrEqual(30, $count);
    }

    public function test_document_lists_every_configured_input_once(): void
    {
        self::assertSame(self::configuredIds(), self::documentedIds());
    }

    public function test_every_input_and_its_source_have_an_established_free_license(): void
    {
        $nonFreeLicenses = [];
        foreach (SourceConfiguration::fromFile(self::CONFIGURATION) as $input) {
            foreach (['source' => $input->source->license, 'document' => $input->license] as $scope => $license) {
                if (! in_array($license['expression'], self::FREE_LICENSES, true)) {
                    $nonFreeLicenses[] = "{$input->id()} ({$scope}): {$license['expression']}";
                }
            }
        }

        self::assertSame([], $nonFreeLicenses);
    }

    public function test_documented_strata_of_each_input_match_the_configuration(): void
    {
        preg_match_all('/^\| `([a-z0-9-]+:[^`]+)` \| [^|]+ \| ([^|]+) \|/m', (string) file_get_contents(self::DOCUMENT), $rows, PREG_SET_ORDER);
        $documentedStrata = [];
        foreach ($rows as [, $id, $strataCell]) {
            preg_match_all('/`([a-z]+)`/', $strataCell, $strata);
            $documentedStrata[$id] = $strata[1];
        }
        ksort($documentedStrata);

        $configuredStrata = [];
        foreach (SourceConfiguration::fromFile(self::CONFIGURATION) as $input) {
            $configuredStrata[$input->id()] = $input->strata;
        }
        ksort($configuredStrata);

        self::assertSame($configuredStrata, $documentedStrata);
    }

    public function test_documented_stratum_counts_match_the_configuration(): void
    {
        preg_match_all('/^\| `([a-z]+)` \| [^|]+ \| (\d+) \|$/m', (string) file_get_contents(self::DOCUMENT), $strata);
        $documentedCounts = array_map(intval(...), array_combine($strata[1], $strata[2]));
        ksort($documentedCounts);

        $configuredCounts = array_count_values(array_merge(...array_map(static fn (Input $input): array => $input->strata, SourceConfiguration::fromFile(self::CONFIGURATION))));
        ksort($configuredCounts);

        self::assertNotSame([], $documentedCounts);
        self::assertSame($configuredCounts, $documentedCounts);
    }

    public function test_sample_is_declared_failure_enriched(): void
    {
        $configuration = json_decode((string) file_get_contents(self::CONFIGURATION), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('failure_enriched', $configuration['selection']);
    }
}
