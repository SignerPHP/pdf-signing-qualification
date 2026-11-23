<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use Closure;
use DateTimeImmutable;

/**
 * Where a PAdES output registers its certification signature and its document timestamps,
 * rules that the cryptographic validators tolerate: a certification signature is the /DocMDP
 * entry of the catalog's /Perms (ISO 32000-1, 12.8.4) with the scenario's permission level, and a
 * document timestamp has /Type /DocTimeStamp and is the value of a signature field of the form
 * (ISO 32000-2, 12.8.5).
 */
final class PadesStructureValidator extends ToolValidator
{
    private const string DOCUMENT_TIMESTAMP_SUB_FILTER = '/ETSI.RFC3161';

    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        CommandRunner $runner,
        Closure $clock,
        private readonly ?int $certificationLevel,
        private readonly bool $documentTimestamp,
        float $timeoutSeconds = 120.0,
    ) {
        parent::__construct($runner, $clock, $timeoutSeconds);
    }

    public function analyzerId(): string
    {
        return 'qpdf-pades-structure';
    }

    protected function property(): string
    {
        return 'structure';
    }

    protected function policy(): array
    {
        return [
            'id' => 'qpdf-pades-structure',
            'description' => 'From qpdf\'s JSON listing of the output: the catalog has no /DocMDP or /DocTimeStamp entry and /Perms no /DocTimeStamp; a certification scenario registers a signature dictionary as /Perms /DocMDP whose DocMDP transform has the scenario\'s permission level /P (2 when absent), and an approval scenario has no /Perms /DocMDP; every dictionary with /SubFilter /ETSI.RFC3161 has /Type /DocTimeStamp and is the /V of a field of /AcroForm /Fields; there is at least one when the scenario adds a document timestamp and none otherwise. A listing qpdf had to recover is inconclusive',
            'parameters' => [
                'listing' => ['qpdf', ...QpdfObjects::ARGUMENTS],
                'certification_level' => $this->certificationLevel,
                'document_timestamp' => $this->documentTimestamp,
            ],
        ];
    }

    protected function versionCommand(): array
    {
        return ['qpdf', '--version'];
    }

    protected function versionPattern(): string
    {
        return '/^qpdf version (\S+)$/m';
    }

    protected function judgeOutput(ValidationSubject $subject, string $outputPath): Judgement
    {
        $result = $this->runner->run(QpdfObjects::command($outputPath), $this->timeoutSeconds);
        if ($result->timedOut) {
            return $this->timedOut($result);
        }
        $document = QpdfObjects::fromResult($result);
        $catalog = $document?->catalog();
        if ($document === null || $catalog === null) {
            return new Judgement('inconclusive', 'qpdf '.$result->describe().' without a catalog the analyzer can read', $result->exitCode, $result->output);
        }

        $problems = [];
        foreach (['/DocMDP', '/DocTimeStamp'] as $key) {
            if (array_key_exists($key, $catalog)) {
                $problems[] = "the catalog has a {$key} entry";
            }
        }
        $perms = $document->resolve($catalog['/Perms'] ?? null);
        if (is_array($perms) && array_key_exists('/DocTimeStamp', $perms)) {
            $problems[] = '/Perms has a /DocTimeStamp entry';
        }
        array_push($problems, ...$this->certificationProblems($document, is_array($perms) ? $perms : []));

        $fieldValues = $document->signatureFieldValues();
        $documentTimestamps = [];
        foreach ($document->signatureDictionaries() as $reference => $dictionary) {
            if (($dictionary['/SubFilter'] ?? null) !== self::DOCUMENT_TIMESTAMP_SUB_FILTER) {
                continue;
            }
            $documentTimestamps[] = $reference;
            if (($dictionary['/Type'] ?? null) !== '/DocTimeStamp') {
                $type = is_string($dictionary['/Type'] ?? null) ? $dictionary['/Type'] : 'none';
                $problems[] = "the document timestamp {$reference} has /Type {$type}, not /DocTimeStamp";
            }
            if (! isset($fieldValues[$reference])) {
                $problems[] = "the document timestamp {$reference} is not the value of a signature field of /AcroForm /Fields";
            }
        }
        if ($this->documentTimestamp && $documentTimestamps === []) {
            $problems[] = 'the scenario adds a document timestamp, but the output has none';
        }
        if (! $this->documentTimestamp && $documentTimestamps !== []) {
            $problems[] = 'the scenario adds no document timestamp, but the output has '.count($documentTimestamps);
        }

        $details = ['document_timestamps' => $documentTimestamps, 'problems' => $problems];
        if ($problems !== []) {
            return new Judgement('invalid', 'The PAdES structure of the output is wrong: '.implode('; ', $problems), $result->exitCode, '', $details);
        }
        if (! $document->isComplete()) {
            return new Judgement('inconclusive', 'qpdf had to recover the output, so its listing may miss objects', $result->exitCode, '', $details);
        }

        return new Judgement('valid', 'The certification signature and the document timestamps are registered where the scenario requires', $result->exitCode, '', $details);
    }

    /**
     * @return list<string>
     */
    private function certificationProblems(QpdfObjects $document, array $perms): array
    {
        $reference = $perms['/DocMDP'] ?? null;
        if ($this->certificationLevel === null) {
            return $reference === null ? [] : ['the scenario has no certification signature, but /Perms has a /DocMDP entry'];
        }

        $signature = $document->resolve($reference);
        if (! is_array($signature) || ! array_key_exists('/ByteRange', $signature)) {
            return ['the certification signature is not registered as /Perms /DocMDP'];
        }
        $references = $document->resolve($signature['/Reference'] ?? null);
        foreach (is_array($references) ? $references : [] as $signatureReference) {
            $signatureReference = $document->resolve($signatureReference);
            if (! is_array($signatureReference) || ($signatureReference['/TransformMethod'] ?? null) !== '/DocMDP') {
                continue;
            }
            $parameters = $document->resolve($signatureReference['/TransformParams'] ?? null);
            $level = is_array($parameters) ? $document->resolve($parameters['/P'] ?? 2) : 2;

            return $level === $this->certificationLevel ? [] : ['the DocMDP permission level is '.json_encode($level).", not {$this->certificationLevel}"];
        }

        return ['the certification signature has no DocMDP transform in /Reference'];
    }
}
