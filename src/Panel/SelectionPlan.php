<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Panel;

use InvalidArgumentException;
use SignerPHP\PdfSigningQualification\Acquisition\Source;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final readonly class SelectionPlan
{
    public const string SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/panel-selection.schema.json';

    /**
     * @param  list<PlanSource>  $sources
     */
    public function __construct(
        public string $sha256,
        public string $seed,
        public int $quotaPerStratum,
        public int $largeByteSize,
        public int $largePageCount,
        public array $sources,
    ) {}

    public static function fromFile(string $path): self
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException("Unable to read the selection plan {$path}");
        }

        return self::fromJson($contents);
    }

    public static function fromJson(string $contents): self
    {
        $errors = (new SchemaValidator)->errors((object) json_decode($contents, flags: JSON_THROW_ON_ERROR), self::SCHEMA);
        if ($errors !== []) {
            throw new InvalidArgumentException("The selection plan does not follow its schema:\n".implode("\n", $errors));
        }

        /** @var array{seed: string, quota_per_stratum: int, large: array{byte_size: int, page_count: int}, sources: list<array{id: string, url: string, revision: array{kind: string, value: string}, file_url: string, license: array{expression: string, evidence_url: string}, listing_url: string, document_license: array{expression: string, evidence_url: string}, include: list<string>, exclude: list<array{pattern: string, reason: string}>}>} $plan */
        $plan = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $sources = [];
        foreach ($plan['sources'] as $source) {
            if (isset($sources[$source['id']])) {
                throw new InvalidArgumentException("Source {$source['id']} is declared more than once");
            }
            $sources[$source['id']] = new PlanSource(
                new Source($source['id'], $source['url'], $source['revision'], $source['file_url'], $source['license']),
                $source['listing_url'],
                $source['document_license'],
                $source['include'],
                $source['exclude'],
            );
        }

        return new self(
            hash('sha256', $contents),
            $plan['seed'],
            $plan['quota_per_stratum'],
            $plan['large']['byte_size'],
            $plan['large']['page_count'],
            array_values($sources),
        );
    }
}
