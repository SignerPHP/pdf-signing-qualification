<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use SignerPHP\PdfSigningQualification\Run\RunRecords;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class RunFixture
{
    private const string PREFIX = SchemaValidator::SCHEMA_PREFIX.'v1/';

    public array $run;

    /** @var list<array> */
    private array $attempts = [];

    /** @var list<array> */
    private array $observations = [];

    private ?array $regressions = null;

    /**
     * @param  array<string, string>  $analyzerVersions
     */
    public function __construct(string $runId, array $analyzerVersions = ['pdfsig' => '22.12.0', 'qpdf' => '11.3.0'], string $scenarioId = 'basic-invisible-sha256')
    {
        $this->run = [
            'schema' => self::PREFIX.'run.schema.json',
            'id' => $runId,
            'scenario_id' => $scenarioId,
            'started_at' => '2026-11-07T12:00:00Z',
            'finished_at' => '2026-11-07T12:01:00Z',
            'environment' => [
                'os' => ['name' => 'Debian GNU/Linux 12 (bookworm)', 'kernel' => '6.1.0', 'architecture' => 'x86_64'],
                'container_image' => ['status' => 'available', 'id' => 'sha256:'.str_repeat('c', 64)],
                'php_version' => '8.4.27',
                'resource_limits' => ['timeout_seconds' => 120, 'address_space_bytes' => 1_073_741_824, 'file_size_bytes' => 268_435_456, 'storage_bytes' => 536_870_912, 'open_files' => 256],
            ],
            'implementation' => ['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => str_repeat('a', 40)]],
            'analyzers' => array_map(static fn (string $analyzerId, string $version): array => ['id' => $analyzerId, 'revision' => ['kind' => 'version', 'value' => $version]], array_keys($analyzerVersions), $analyzerVersions),
            'source_configuration' => ['sha256' => str_repeat('d', 64), 'byte_size' => 13_321],
            'expected_rejections' => ['sha256' => str_repeat('e', 64), 'byte_size' => 373],
        ];
    }

    public function implementationCommit(string $commit): self
    {
        $this->run['implementation']['revision']['value'] = $commit;
        foreach ($this->attempts as $index => $attempt) {
            $this->attempts[$index]['implementation']['revision']['value'] = $commit;
        }

        return $this;
    }

    /**
     * @param  array<string, string>  $verdicts
     */
    public function succeeded(string $sourceId, array $verdicts, string $outputSeed = 'output', string $capability = 'supported', string $inputContent = 'input'): self
    {
        $output = ['sha256' => hash('sha256', $this->run['id'].$sourceId.$outputSeed), 'byte_size' => 2048];

        return $this->attempt($sourceId, $capability, ['outcome' => 'succeeded', 'exit_code' => 0, 'started_at' => '2026-11-07T12:00:10Z', 'finished_at' => '2026-11-07T12:00:11Z', 'error' => null], $output, $verdicts, $inputContent);
    }

    public function failed(string $sourceId, string $outcome = 'failed', ?int $exitCode = 1, string $capability = 'supported'): self
    {
        $execution = ['outcome' => $outcome, 'exit_code' => $exitCode, 'started_at' => '2026-11-07T12:00:10Z', 'finished_at' => '2026-11-07T12:00:11Z', 'error' => ['message' => "pdf-signer {$outcome}"]];

        return $this->attempt($sourceId, $capability, $execution, null, [], 'input');
    }

    public function unsupported(string $sourceId): self
    {
        return $this->attempt($sourceId, 'unsupported', ['outcome' => 'not_run', 'exit_code' => null, 'started_at' => null, 'finished_at' => null, 'error' => null], null, [], 'input');
    }

    public function unavailable(string $sourceId): self
    {
        $this->attempts[] = [
            'schema' => self::PREFIX.'attempt.schema.json',
            'id' => $this->attemptId($sourceId),
            'run_id' => $this->run['id'],
            'scenario_id' => $this->run['scenario_id'],
            'implementation' => $this->run['implementation'],
            'input' => ['status' => 'unavailable', 'source_id' => $sourceId, 'reason' => 'missing_upstream', 'expected_sha256' => hash('sha256', 'input'), 'message' => 'The upstream file is missing (HTTP 404)'],
            'capability' => 'not_evaluated',
            'execution' => ['outcome' => 'not_run', 'exit_code' => null, 'started_at' => null, 'finished_at' => null, 'error' => null],
            'output' => null,
        ];
        $this->observeAll($sourceId, null, null, []);

        return $this;
    }

    public function policy(string $analyzerId, string $policyId): self
    {
        foreach ($this->observations as $index => $observation) {
            if ($observation['analyzer']['id'] === $analyzerId) {
                $this->observations[$index]['policy']['id'] = $policyId;
            }
        }

        return $this;
    }

    /**
     * @param  array<string, string>  $verdicts
     */
    public function regression(string $caseId, string $condition, array $verdicts = [], string $outcome = 'succeeded', string $inputContent = 'input'): self
    {
        $this->regressions ??= ['schema' => self::PREFIX.'regressions.schema.json', 'run_id' => $this->run['id'], 'attempts' => [], 'observations' => [], 'cases' => []];
        $sourceId = "regression:{$caseId}.pdf";
        $attempts = $this->attempts;
        $observations = $this->observations;
        if ($outcome === 'succeeded') {
            $this->succeeded($sourceId, $verdicts, inputContent: $inputContent);
        } else {
            $this->failed($sourceId, $outcome);
        }
        array_push($this->regressions['attempts'], ...array_slice($this->attempts, count($attempts)));
        array_push($this->regressions['observations'], ...array_slice($this->observations, count($observations)));
        $this->attempts = $attempts;
        $this->observations = $observations;

        return $this->regressionCase($caseId, $this->attemptId($sourceId), $condition);
    }

    public function regressionOnInput(string $caseId, string $sourceId, string $condition): self
    {
        $this->regressions ??= ['schema' => self::PREFIX.'regressions.schema.json', 'run_id' => $this->run['id'], 'attempts' => [], 'observations' => [], 'cases' => []];

        return $this->regressionCase($caseId, $this->attemptId($sourceId), $condition);
    }

    public function regressionNotReplayed(string $caseId): self
    {
        $this->regressions ??= ['schema' => self::PREFIX.'regressions.schema.json', 'run_id' => $this->run['id'], 'attempts' => [], 'observations' => [], 'cases' => []];

        return $this->regressionCase($caseId, null, 'not_evaluated');
    }

    public function compatibility(array $compatibility): self
    {
        $this->run['implementation_compatibility'] = $compatibility;

        return $this;
    }

    public function records(): RunRecords
    {
        return new RunRecords($this->run, $this->attempts, $this->observations, $this->regressions);
    }

    private function regressionCase(string $caseId, ?string $attemptId, string $condition): self
    {
        $this->regressions['cases'][] = [
            'case_id' => $caseId,
            'scenario_id' => $this->run['scenario_id'],
            'definition' => ['sha256' => hash('sha256', $caseId), 'byte_size' => 600],
            'attempt_id' => $attemptId,
            'condition' => ['status' => $condition, 'evidence' => "The condition of {$caseId} is {$condition}"],
        ];

        return $this;
    }

    public function write(string $directory): string
    {
        mkdir($directory, 0777, true);
        $encode = static fn (array $document): string => json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        file_put_contents($directory.'/attempts.json', $encode($this->attempts));
        file_put_contents($directory.'/observations.json', $encode($this->observations));
        if ($this->regressions !== null) {
            file_put_contents($directory.'/regressions.json', $encode($this->regressions));
        }
        file_put_contents($directory.'/run.json', $encode($this->run));

        return $directory;
    }

    public function attemptId(string $sourceId): string
    {
        return "{$this->run['id']}/{$this->run['scenario_id']}/{$this->run['implementation']['id']}/{$sourceId}";
    }

    /**
     * @param  array<string, string>  $verdicts
     */
    private function attempt(string $sourceId, string $capability, array $execution, ?array $output, array $verdicts, string $inputContent): self
    {
        $input = ['sha256' => hash('sha256', $inputContent), 'byte_size' => strlen($inputContent)];
        $this->attempts[] = [
            'schema' => self::PREFIX.'attempt.schema.json',
            'id' => $this->attemptId($sourceId),
            'run_id' => $this->run['id'],
            'scenario_id' => $this->run['scenario_id'],
            'implementation' => $this->run['implementation'],
            'input' => ['status' => 'available', 'source_id' => $sourceId, ...$input],
            'capability' => $capability,
            'execution' => $execution,
            'output' => $output,
        ];
        $this->observeAll($sourceId, $input, $output, $verdicts);

        return $this;
    }

    /**
     * @param  array<string, string>  $verdicts
     */
    private function observeAll(string $sourceId, ?array $input, ?array $output, array $verdicts): void
    {
        foreach ($this->run['analyzers'] as $analyzer) {
            $verdict = $verdicts[$analyzer['id']] ?? 'not_evaluated';
            $this->observations[] = [
                'schema' => self::PREFIX.'observation.schema.json',
                'id' => $this->attemptId($sourceId).'/'.$analyzer['id'],
                'attempt_id' => $this->attemptId($sourceId),
                'analyzer' => $analyzer,
                'policy' => ['id' => $analyzer['id'].'-default'],
                'property' => $analyzer['id'] === 'qpdf' ? 'structure' : 'cms_signature',
                'input_artifact' => $input,
                'output_artifact' => $verdict === 'not_evaluated' ? null : $output,
                'verdict' => $verdict,
                'evidence' => ['summary' => "{$analyzer['id']} judged the output {$verdict}"],
                'observed_at' => '2026-11-07T12:00:12Z',
            ];
        }
    }
}
