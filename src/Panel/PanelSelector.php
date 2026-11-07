<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Panel;

use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\Fetcher;
use SignerPHP\PdfSigningQualification\Acquisition\SourceConfiguration;
use SignerPHP\PdfSigningQualification\Run\RunReport;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class PanelSelector
{
    public const string POOL_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/panel-pool.schema.json';

    /**
     * @param  \Closure(string): void  $progress
     */
    public function __construct(
        private readonly Fetcher $fetcher,
        private readonly StrataClassifier $classifier,
        private readonly string $cacheDirectory,
        private readonly \Closure $progress,
    ) {}

    /**
     * @return array{inputs: array, pool: array}
     */
    public function select(SelectionPlan $plan, string $analyzerVersion): array
    {
        $candidates = [];
        $poolSources = [];
        foreach ($plan->sources as $planSource) {
            $excluded = array_map(static fn (array $exclusion): array => [...$exclusion, 'files' => 0], $planSource->exclude);
            $candidateCount = 0;
            $unaddressable = 0;
            foreach ($this->listing($planSource) as $file) {
                if (! $planSource->isIncluded($file['path'])) {
                    continue;
                }
                $exclusion = $planSource->exclusion($file['path']);
                if ($exclusion !== null) {
                    $index = array_search($exclusion, $planSource->exclude, true);
                    $excluded[(int) $index]['files']++;

                    continue;
                }
                if (preg_match($this->inputPathPattern(), $file['path']) !== 1) {
                    $unaddressable++;

                    continue;
                }
                $candidateCount++;
                $candidates[$planSource->source->id.':'.$file['path']] = [$planSource, $file];
            }
            $poolSources[] = ['id' => $planSource->source->id, 'candidates' => $candidateCount, 'selected' => 0, 'excluded' => $excluded, 'unaddressable' => $unaddressable];
        }

        $strataById = [];
        $describedById = [];
        $classified = 0;
        foreach ($candidates as $id => [$planSource, $file]) {
            $path = $this->cachedFile($planSource, $file);
            $strata = self::inSchemaOrder($this->classifier->classify($path));
            $strataById[$id] = $strata;
            $describedById[$id] = [
                'source' => $planSource->source->id,
                'path' => $file['path'],
                'sha256' => (string) hash_file('sha256', $path),
                'byte_size' => (int) filesize($path),
                'license' => $planSource->documentLicense,
                'strata' => $strata,
            ];
            if (++$classified % 100 === 0) {
                ($this->progress)("{$classified} of ".count($candidates).' candidates classified');
            }
        }

        $selectedIds = QuotaSampler::sample($strataById, $plan->seed, $plan->quotaPerStratum);
        $selectedInputs = array_values(array_intersect_key($describedById, array_flip($selectedIds)));

        $configuration = [
            'schema' => SourceConfiguration::SCHEMA,
            'selection' => 'stratified',
            'sources' => array_map(static fn (PlanSource $planSource): array => [
                'id' => $planSource->source->id,
                'url' => $planSource->source->url,
                'revision' => $planSource->source->revision,
                'file_url' => $planSource->source->fileUrlTemplate,
                'license' => $planSource->source->license,
            ], $plan->sources),
            'inputs' => $selectedInputs,
        ];
        SourceConfiguration::fromArray($configuration);

        foreach ($poolSources as $index => $poolSource) {
            $poolSources[$index]['selected'] = count(array_filter($selectedInputs, static fn (array $input): bool => $input['source'] === $poolSource['id']));
        }
        $pool = [
            'schema' => self::POOL_SCHEMA,
            'plan_sha256' => $plan->sha256,
            'analyzer' => ['name' => 'qpdf', 'version' => $analyzerVersion],
            'candidates' => count($candidates),
            'selected' => count($selectedInputs),
            'sources' => $poolSources,
            'strata' => array_map(static fn (string $stratum): array => [
                'stratum' => $stratum,
                'candidates' => count(array_filter($strataById, static fn (array $strata): bool => in_array($stratum, $strata, true))),
                'selected' => count(array_filter($selectedInputs, static fn (array $input): bool => in_array($stratum, $input['strata'], true))),
            ], RunReport::strata()),
        ];
        (new SchemaValidator)->assertValid($pool, self::POOL_SCHEMA, 'panel pool');

        return ['inputs' => $configuration, 'pool' => $pool];
    }

    private function inputPathPattern(): string
    {
        /** @var array{'$defs': array{relativePath: array{pattern: string, maxLength: int}}} $schema */
        $schema = json_decode((string) file_get_contents(SchemaValidator::SCHEMA_DIRECTORY.'v1/common.schema.json'), true, flags: JSON_THROW_ON_ERROR);
        $relativePath = $schema['$defs']['relativePath'];

        return '#(?=^.{1,'.$relativePath['maxLength'].'}$)'.$relativePath['pattern'].'#u';
    }

    /**
     * @param  list<string>  $strata
     * @return list<string>
     */
    private static function inSchemaOrder(array $strata): array
    {
        return array_values(array_intersect(RunReport::strata(), $strata));
    }

    /**
     * @return list<array{path: string, sha: string}>
     */
    private function listing(PlanSource $planSource): array
    {
        $url = $planSource->listingUrl();
        $listing = json_decode($this->fetcher->fetch($url), true);
        if (! is_array($listing) || ! isset($listing['tree']) || ! is_array($listing['tree'])) {
            throw new RuntimeException("The listing at {$url} is not a Git tree");
        }
        if (($listing['truncated'] ?? false) !== false) {
            throw new RuntimeException("The listing at {$url} is truncated");
        }

        $files = [];
        foreach ($listing['tree'] as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'blob' && is_string($entry['path'] ?? null) && is_string($entry['sha'] ?? null)) {
                $files[] = ['path' => $entry['path'], 'sha' => $entry['sha']];
            }
        }
        usort($files, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        return $files;
    }

    /**
     * @param  array{path: string, sha: string}  $file
     */
    private function cachedFile(PlanSource $planSource, array $file): string
    {
        if (preg_match('/^[0-9a-f]{40}$/', $file['sha']) !== 1) {
            throw new RuntimeException("The listing gives {$file['path']} the invalid object id {$file['sha']}");
        }
        $directory = "{$this->cacheDirectory}/{$planSource->source->id}";
        $path = "{$directory}/{$file['sha']}";
        if (is_file($path) && self::gitBlobId((string) file_get_contents($path)) === $file['sha']) {
            return $path;
        }

        $url = $planSource->source->fileUrl($file['path']);
        $content = $this->fetcher->fetch($url);
        if (self::gitBlobId($content) !== $file['sha']) {
            throw new RuntimeException("{$url} does not match the object {$file['sha']} of the listing");
        }
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create {$directory}");
        }
        $temporaryPath = "{$path}.partial";
        if (file_put_contents($temporaryPath, $content) !== strlen($content) || ! rename($temporaryPath, $path)) {
            throw new RuntimeException("Unable to write {$path}");
        }

        return $path;
    }

    private static function gitBlobId(string $content): string
    {
        return sha1('blob '.strlen($content)."\0".$content);
    }
}
