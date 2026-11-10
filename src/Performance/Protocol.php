<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Performance;

use JsonException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final readonly class Protocol
{
    public const string SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/performance-protocol.schema.json';

    public const string STUDY_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/performance-study.schema.json';

    public const string CALIBRATION = 'calibration';

    /**
     * @param  array{implementations: list<string>, panel: array{inputs_configuration: string, expected_rejections: string}, host: array{cpus: string, scaling_governor: string, boost: string}, seed: int, calibration_runs: int, warm_up_runs: int, measured_runs: int, reference: array{generator: string, sha256: string, byte_size: int, repetitions: int}, eligibility: string, quantiles: list<float|int>, bootstrap: array{resamples: int, confidence: float}, repeatability_tolerance: float}  $document
     */
    private function __construct(
        public array $document,
        public string $contents,
    ) {}

    public static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read the performance protocol {$path}");
        }

        return self::fromJson($contents, $path);
    }

    public static function fromJson(string $contents, string $description = 'the performance protocol'): self
    {
        try {
            $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("{$description} is not valid JSON: {$exception->getMessage()}");
        }
        if (! is_array($document)) {
            throw new RuntimeException("{$description} does not hold a JSON object");
        }
        (new SchemaValidator)->assertValid($document, self::SCHEMA, "performance protocol {$description}");
        $quantiles = $document['quantiles'];
        sort($quantiles);
        if ($quantiles !== $document['quantiles']) {
            throw new RuntimeException("The quantiles of {$description} are not in increasing order");
        }

        /** @psalm-suppress ArgumentTypeCoercion */
        return new self($document, $contents);
    }

    /**
     * @return array{string, string}
     */
    public function implementations(): array
    {
        return [$this->document['implementations'][0], $this->document['implementations'][1]];
    }

    public function seedOf(string|int $session): int
    {
        return $this->document['seed'] + self::sessionNumber($session);
    }

    /**
     * @return list<array{label: string, implementation_id: string, role: 'calibration'|'warm_up'|'measured'}>
     */
    public function plan(string|int $session): array
    {
        $randomizer = new Randomizer(new Mt19937($this->seedOf($session)));
        $roles = self::sessionNumber($session) === 0
            ? array_fill(0, $this->document['calibration_runs'], 'calibration')
            : [...array_fill(0, $this->document['warm_up_runs'], 'warm_up'), ...array_fill(0, $this->document['measured_runs'], 'measured')];

        $runs = [];
        foreach ($roles as $role) {
            foreach ($randomizer->shuffleArray($this->document['implementations']) as $implementationId) {
                $runs[] = ['label' => sprintf('%02d-%s', count($runs) + 1, (string) $implementationId), 'implementation_id' => (string) $implementationId, 'role' => $role];
            }
        }

        return $runs;
    }

    public function study(string $studyId, string|int $session, string $plannedAt): array
    {
        $study = [
            'schema' => self::STUDY_SCHEMA,
            'id' => $studyId,
            'session' => self::sessionNumber($session) === 0 ? self::CALIBRATION : self::sessionNumber($session),
            'seed' => $this->seedOf($session),
            'protocol' => ['sha256' => hash('sha256', $this->contents), 'byte_size' => strlen($this->contents)],
            'planned_at' => $plannedAt,
            'runs' => $this->plan($session),
        ];
        (new SchemaValidator)->assertValid($study, self::STUDY_SCHEMA, 'performance study');

        return $study;
    }

    private static function sessionNumber(string|int $session): int
    {
        if ($session === self::CALIBRATION) {
            return 0;
        }
        if (is_int($session) && $session >= 1) {
            return $session;
        }
        if (is_string($session) && preg_match('/^[1-9][0-9]{0,5}$/', $session) === 1) {
            return (int) $session;
        }

        throw new RuntimeException("The session must be calibration or a positive number, got {$session}");
    }
}
