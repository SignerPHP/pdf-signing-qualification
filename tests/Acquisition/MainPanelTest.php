<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Acquisition;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Acquisition\Input;
use SignerPHP\PdfSigningQualification\Acquisition\SourceConfiguration;
use SignerPHP\PdfSigningQualification\Panel\PanelSelector;
use SignerPHP\PdfSigningQualification\Panel\PlanSource;
use SignerPHP\PdfSigningQualification\Panel\SelectionPlan;
use SignerPHP\PdfSigningQualification\Run\ExpectedRejections;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class MainPanelTest extends TestCase
{
    private const string DIRECTORY = __DIR__.'/../../config/main-panel';

    private const string PLAN = self::DIRECTORY.'/selection.json';

    private const string CONFIGURATION = self::DIRECTORY.'/inputs.json';

    private const string POOL = self::DIRECTORY.'/pool.json';

    private const string EXPECTED_REJECTIONS = self::DIRECTORY.'/expected-rejections.json';

    private const string DOCUMENT = __DIR__.'/../../docs/main-panel.md';

    /**
     * @return list<Input>
     */
    private static function inputs(): array
    {
        return SourceConfiguration::fromFile(self::CONFIGURATION);
    }

    private static function pool(): array
    {
        $pool = json_decode((string) file_get_contents(self::POOL), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($pool);

        return $pool;
    }

    private static function document(): string
    {
        return (string) file_get_contents(self::DOCUMENT);
    }

    /**
     * @return array<string, PlanSource>
     */
    private static function planSources(): array
    {
        $sources = [];
        foreach (SelectionPlan::fromFile(self::PLAN)->sources as $planSource) {
            $sources[$planSource->source->id] = $planSource;
        }

        return $sources;
    }

    public function test_panel_is_declared_stratified(): void
    {
        $configuration = json_decode((string) file_get_contents(self::CONFIGURATION), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('stratified', $configuration['selection']);
    }

    public function test_every_input_is_a_candidate_of_its_plan_source_with_its_document_license(): void
    {
        $planSources = self::planSources();
        $outsideThePlan = [];
        foreach (self::inputs() as $input) {
            $planSource = $planSources[$input->source->id] ?? null;
            if ($planSource === null
                || $input->source != $planSource->source
                || ! $planSource->isIncluded($input->path)
                || $planSource->exclusionIndex($input->path) !== null
                || $input->license !== $planSource->documentLicense) {
                $outsideThePlan[] = $input->id();
            }
        }

        self::assertSame([], $outsideThePlan);
    }

    public function test_every_input_and_its_source_have_an_established_free_license(): void
    {
        $nonFreeLicenses = [];
        foreach (self::inputs() as $input) {
            foreach (['source' => $input->source->license, 'document' => $input->license] as $scope => $license) {
                if (! in_array($license['expression'], FreeLicenses::EXPRESSIONS, true)) {
                    $nonFreeLicenses[] = "{$input->id()} ({$scope}): {$license['expression']}";
                }
            }
        }

        self::assertSame([], $nonFreeLicenses);
    }

    public function test_pool_follows_its_schema_and_was_built_from_the_versioned_plan(): void
    {
        $pool = self::pool();

        self::assertSame([], (new SchemaValidator)->errors($pool, PanelSelector::POOL_SCHEMA));
        self::assertSame(hash_file('sha256', self::PLAN), $pool['plan_sha256']);
        self::assertSame(count(self::inputs()), $pool['selected']);
        self::assertSame(array_sum(array_column($pool['sources'], 'candidates')), $pool['candidates']);
    }

    public function test_pool_counts_the_inputs_of_each_source_and_stratum(): void
    {
        $pool = self::pool();
        $inputs = self::inputs();

        foreach ($pool['sources'] as $source) {
            $inputsOfSource = array_filter($inputs, static fn (Input $input): bool => $input->source->id === $source['id']);
            self::assertSame(count($inputsOfSource), $source['selected'], $source['id']);
        }
        self::assertSame(SourceConfiguration::strata(), array_column($pool['strata'], 'stratum'));
        foreach ($pool['strata'] as $stratum) {
            $inputsOfStratum = array_filter($inputs, static fn (Input $input): bool => in_array($stratum['stratum'], $input->strata, true));
            self::assertSame(count($inputsOfStratum), $stratum['selected'], $stratum['stratum']);
        }
    }

    public function test_every_stratum_holds_its_quota_or_all_its_candidates(): void
    {
        $quota = SelectionPlan::fromFile(self::PLAN)->quotaPerStratum;

        foreach (self::pool()['strata'] as $stratum) {
            self::assertGreaterThanOrEqual(min($quota, $stratum['candidates']), $stratum['selected'], $stratum['stratum']);
        }
    }

    public function test_panel_includes_large_documents(): void
    {
        $large = array_filter(self::inputs(), static fn (Input $input): bool => in_array('large', $input->strata, true));

        self::assertNotSame([], $large);
    }

    public function test_document_states_the_pool_and_panel_count_of_every_stratum(): void
    {
        preg_match_all('/^\| `([a-z]+)` \| [^|]+ \| (\d+) \| (\d+) \|$/m', self::document(), $rows, PREG_SET_ORDER);
        $documented = [];
        foreach ($rows as [, $stratum, $candidates, $selected]) {
            $documented[] = ['stratum' => $stratum, 'candidates' => (int) $candidates, 'selected' => (int) $selected];
        }

        self::assertSame(self::pool()['strata'], $documented);
    }

    public function test_document_states_the_size_of_the_pool_and_of_the_panel(): void
    {
        $pool = self::pool();

        self::assertStringContainsString("{$pool['selected']} inputs drawn from a pool of {$pool['candidates']} candidates", self::document());
    }

    public function test_document_gives_every_rejected_source_and_exclusion(): void
    {
        $plan = json_decode((string) file_get_contents(self::PLAN), true, flags: JSON_THROW_ON_ERROR);
        $document = self::document();

        foreach ($plan['rejected_sources'] as $rejected) {
            self::assertStringContainsString($rejected['url'], $document);
        }
        foreach ($plan['sources'] as $source) {
            foreach ($source['exclude'] as $exclusion) {
                self::assertStringContainsString("`{$exclusion['pattern']}`", $document);
            }
        }
    }

    public function test_expected_rejections_name_only_inputs_of_the_panel(): void
    {
        $rejections = ExpectedRejections::fromFile(self::EXPECTED_REJECTIONS, self::inputs(), __DIR__.'/../../config/scenarios');

        self::assertInstanceOf(ExpectedRejections::class, $rejections);
        foreach (['basic-invisible-sha256', 'basic-visible-sha256'] as $scenarioId) {
            self::assertTrue($rejections->declares('qpdf:qpdf/qtest/qpdf/enc-XI-long-password.pdf', $scenarioId), $scenarioId);
        }
    }

    public function test_input_declared_in_the_pilot_sample_too_has_the_same_strata_there(): void
    {
        $pilotStrata = [];
        foreach (SourceConfiguration::fromFile(__DIR__.'/../../config/inputs.json') as $input) {
            $pilotStrata[$input->sha256] = $input->strata;
        }

        $shared = 0;
        foreach (self::inputs() as $input) {
            if (! isset($pilotStrata[$input->sha256])) {
                continue;
            }
            $shared++;
            $panelStrata = $input->strata;
            $strataInPilot = $pilotStrata[$input->sha256];
            sort($panelStrata);
            sort($strataInPilot);
            self::assertSame($strataInPilot, $panelStrata, $input->id());
        }
        self::assertGreaterThan(0, $shared);
    }
}
