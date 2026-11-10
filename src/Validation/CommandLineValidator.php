<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

abstract class CommandLineValidator extends ToolValidator implements BaselineValidator
{
    public const string BASELINE_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/baseline.schema.json';

    abstract protected function judge(string $documentPath): Judgement;

    final protected function judgeOutput(ValidationSubject $subject, string $outputPath): Judgement
    {
        return $this->judge($outputPath);
    }

    final public function baseline(BaselineSubject $subject): array
    {
        $judgement = $this->judge($subject->inputPath);

        return [
            'schema' => self::BASELINE_SCHEMA,
            'id' => "{$subject->runId}/{$subject->sourceId}/{$this->analyzerId()}",
            'run_id' => $subject->runId,
            'source_id' => $subject->sourceId,
            'analyzer' => $this->analyzer(),
            'policy' => $this->policy(),
            'property' => $this->property(),
            'input_artifact' => $subject->inputArtifact,
            'verdict' => $judgement->inputVerdict ?? $judgement->verdict,
            'evidence' => self::evidence($judgement),
            'observed_at' => $this->now(),
        ];
    }
}
