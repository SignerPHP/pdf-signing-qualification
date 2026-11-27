<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use Closure;
use DateTimeImmutable;

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
            'description' => 'From qpdf\'s JSON listing of the output, leaving out the signature dictionaries of the input (same /ByteRange and /Contents): the catalog has no /DocMDP or /DocTimeStamp entry and /Perms no /DocTimeStamp other than one the input holds with the same value, every reference in it resolved; a certification scenario registers a signature dictionary as /Perms /DocMDP whose DocMDP transform has the scenario\'s permission level /P (2 when absent), and an approval scenario adds none to /Perms /DocMDP; every added dictionary with /SubFilter /ETSI.RFC3161 has /Type /DocTimeStamp and is the /V of a field of /AcroForm /Fields; there is at least one when the scenario adds a document timestamp and none otherwise. A listing qpdf had to recover is inconclusive',
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
        $result = $this->runner->run(QpdfObjects::command($outputPath), $this->timeoutSeconds, new CommandAccess([$outputPath]));
        if ($result->timedOut) {
            return $this->timedOut($result);
        }
        $document = QpdfObjects::fromResult($result);
        $catalog = $document?->catalog();
        if ($document === null || $catalog === null) {
            return new Judgement('inconclusive', 'qpdf '.$result->describe().' without a catalog the analyzer can read', $result->exitCode, $result->output);
        }

        $inputKeys = [];
        $inputDocument = null;
        $inputCatalog = [];
        if ($subject->inputPath !== null) {
            $inputListing = SignatureListing::read($this->runner, $subject->inputPath, $this->timeoutSeconds);
            if ($inputListing->signatures === null || ! $inputListing->isComplete()) {
                return new Judgement('inconclusive', 'qpdf cannot list every signature of the input, so those the implementation added cannot be told apart', $inputListing->result->exitCode, $inputListing->result->output);
            }
            $inputKeys = $inputListing->keys();
            $inputDocument = QpdfObjects::fromResult($inputListing->result);
            $inputCatalog = $inputDocument?->catalog();
            if ($inputDocument === null || $inputCatalog === null) {
                return new Judgement('inconclusive', 'qpdf lists no catalog of the input, so the entries the implementation added cannot be told apart', $inputListing->result->exitCode, $inputListing->result->output);
            }
        }

        $perms = $document->resolve($catalog['/Perms'] ?? null);
        $perms = is_array($perms) ? $perms : [];
        $inputPerms = $inputDocument?->resolve($inputCatalog['/Perms'] ?? null);
        $inputPerms = is_array($inputPerms) ? $inputPerms : [];
        $problems = [];
        $inheritedEntries = [];
        $entries = [
            ['the catalog', $catalog, $inputCatalog, '/DocMDP'],
            ['the catalog', $catalog, $inputCatalog, '/DocTimeStamp'],
            ['/Perms', $perms, $inputPerms, '/DocTimeStamp'],
        ];
        foreach ($entries as [$holder, $outputEntries, $inputEntries, $key]) {
            if (! array_key_exists($key, $outputEntries)) {
                continue;
            }
            if (! array_key_exists($key, $inputEntries)) {
                $problems[] = "{$holder} has a {$key} entry";
            } elseif ($document->resolveNested($outputEntries[$key]) !== $inputDocument?->resolveNested($inputEntries[$key])) {
                $problems[] = "{$holder} {$key} entry differs from the input's";
            } else {
                $inheritedEntries[] = "{$holder} {$key}";
            }
        }
        array_push($problems, ...$this->certificationProblems($document, $perms, $inputKeys));

        $fieldValues = $document->signatureFieldValues();
        $documentTimestamps = [];
        foreach ($document->signatureDictionaries() as $reference => $dictionary) {
            if (($dictionary['/SubFilter'] ?? null) !== self::DOCUMENT_TIMESTAMP_SUB_FILTER || self::isPrior($document, $dictionary, $inputKeys)) {
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

        $details = ['document_timestamps' => $documentTimestamps, 'inherited_entries' => $inheritedEntries, 'problems' => $problems];
        if ($problems !== []) {
            return new Judgement('invalid', 'The PAdES structure of the output is wrong: '.implode('; ', $problems), $result->exitCode, '', $details);
        }
        if (! $document->isComplete()) {
            return new Judgement('inconclusive', 'qpdf had to recover the output, so its listing may miss objects', $result->exitCode, '', $details);
        }

        return new Judgement('valid', 'The certification signature and the document timestamps are registered where the scenario requires', $result->exitCode, '', $details);
    }

    /**
     * @param  array<string, true>  $inputKeys
     */
    private static function isPrior(QpdfObjects $document, array $dictionary, array $inputKeys): bool
    {
        $contents = $document->bytes($dictionary['/Contents'] ?? null);

        return isset($inputKeys[SignatureListing::key(['object' => '', 'field' => null, 'type' => null, 'sub_filter' => null, 'byte_range' => $document->byteRange($dictionary), 'contents' => $contents === null ? null : bin2hex($contents)])]);
    }

    /**
     * @param  array<string, true>  $inputKeys
     * @return list<string>
     */
    private function certificationProblems(QpdfObjects $document, array $perms, array $inputKeys): array
    {
        $reference = $perms['/DocMDP'] ?? null;
        $signature = $document->resolve($reference);
        $prior = is_array($signature) && self::isPrior($document, $signature, $inputKeys);
        if ($this->certificationLevel === null) {
            return $reference === null || $prior ? [] : ['the scenario has no certification signature, but /Perms /DocMDP holds a signature the implementation added'];
        }

        if (! is_array($signature) || ! array_key_exists('/ByteRange', $signature)) {
            return ['the certification signature is not registered as /Perms /DocMDP'];
        }
        if ($prior) {
            return ['/Perms /DocMDP still holds the certification signature of the input, not the one the scenario adds'];
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
