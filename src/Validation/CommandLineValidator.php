<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

abstract class CommandLineValidator implements Validator
{
    public const string OBSERVATION_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/observation.schema.json';

    private ?string $version = null;

    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        protected readonly CommandRunner $runner,
        private readonly Closure $clock,
        protected readonly float $timeoutSeconds = 120.0,
    ) {}

    abstract public function analyzerId(): string;

    abstract protected function property(): string;

    /**
     * @return array{id: string, description: string, parameters: array}
     */
    abstract protected function policy(): array;

    /**
     * @return list<string>
     */
    abstract protected function versionCommand(): array;

    abstract protected function versionPattern(): string;

    abstract protected function judge(string $outputPath): Judgement;

    final public function observe(ValidationSubject $subject): array
    {
        $outputArtifact = null;
        if ($subject->inputArtifact === null) {
            $judgement = new Judgement('not_evaluated', 'The input was unavailable, so there is no output to validate', null, '');
        } elseif ($subject->outputPath === null) {
            $judgement = new Judgement('not_evaluated', 'The attempt produced no output to validate', null, '');
        } else {
            $outputArtifact = Artifact::describe($subject->outputPath);
            $judgement = $this->judge($subject->outputPath);
        }

        $evidence = [
            'summary' => $judgement->summary,
            'exit_code' => $judgement->exitCode,
            'raw_output' => $judgement->rawOutput,
        ];
        if ($judgement->details !== []) {
            $evidence['details'] = $judgement->details;
        }

        return [
            'schema' => self::OBSERVATION_SCHEMA,
            'id' => $subject->attemptId.'/'.$this->analyzerId(),
            'attempt_id' => $subject->attemptId,
            'analyzer' => $this->analyzer(),
            'policy' => $this->policy(),
            'property' => $this->property(),
            'input_artifact' => $subject->inputArtifact,
            'output_artifact' => $outputArtifact,
            'verdict' => $judgement->verdict,
            'evidence' => $evidence,
            'observed_at' => ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    final public function analyzer(): array
    {
        return ['id' => $this->analyzerId(), 'revision' => ['kind' => 'version', 'value' => $this->version()]];
    }

    final public function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $result = $this->runner->run($this->versionCommand(), $this->timeoutSeconds);
        if ($result->exitCode !== 0 || preg_match($this->versionPattern(), $result->output, $matches) !== 1) {
            throw new RuntimeException("Unable to determine the version of {$this->analyzerId()}: {$result->output}");
        }

        return $this->version = $matches[1];
    }

    final protected function timedOut(CommandResult $result): Judgement
    {
        return new Judgement('inconclusive', "{$this->analyzerId()} did not finish within {$this->timeoutSeconds} seconds", null, $result->output);
    }
}
