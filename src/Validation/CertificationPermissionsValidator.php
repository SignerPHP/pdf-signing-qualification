<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class CertificationPermissionsValidator extends ComparisonValidator
{
    private const array PERMISSION_LEVELS = [1, 2, 3];

    private const string DOCUMENT_TIMESTAMP_TYPE = '/DocTimeStamp';

    private const string PARAMETERS_NOT_A_DICTIONARY = 'parameters not a dictionary';

    public function analyzerId(): string
    {
        return 'qpdf-certification-permissions';
    }

    protected function property(): string
    {
        return 'certification_permissions';
    }

    protected function policy(): array
    {
        return [
            'id' => 'qpdf-certification-permissions',
            'description' => 'From qpdf\'s JSON listing of the input and of the output: an input whose catalog has no /Perms /DocMDP is not applicable; otherwise the permission level /P of the DocMDP transform in the /Reference of that signature (2 when /TransformParams or /P is absent) judges the signatures the output adds, those with a /ByteRange and /Contents the input does not hold, each classified from its own dictionary: a certification signature has a DocMDP transform or is registered as /Perms /DocMDP, a document timestamp has /Type /DocTimeStamp, and any other is an approval signature. Under /P 1 any added approval or certification signature is invalid; under /P 2 and /P 3 an approval signature is permitted and a certification signature is not, because a document has only one; a document timestamp is permitted at every level, because ETSI EN 319 142-1 clause 5.4.3 has DocMDP restrictions ignore it. The output must still register the certification of the input as /Perms /DocMDP. A problem with the added signatures is inconclusive instead of invalid when qpdf had to recover the input, and a missing /Perms /DocMDP when qpdf had to recover the output, because the listing may miss what it looks for; with no problem, a listing qpdf had to recover is inconclusive. A certification without a DocMDP transform, /TransformParams that is not a dictionary, a level other than 1, 2 or 3, an added signature whose dictionary cannot be read and a document qpdf cannot read are inconclusive. Other changes of the added revision and field locks (/FieldMDP) are not judged',
            'parameters' => [
                'listing' => ['qpdf', ...QpdfObjects::ARGUMENTS],
                'permission_levels' => self::PERMISSION_LEVELS,
                'default_permission_level' => 2,
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

    protected function compare(string $inputPath, string $outputPath): Judgement
    {
        $inputResult = $this->runner->run(QpdfObjects::command($inputPath), $this->timeoutSeconds, new CommandAccess([$inputPath]));
        if ($inputResult->timedOut) {
            return $this->timedOut($inputResult);
        }
        $input = QpdfObjects::fromResult($inputResult);
        $inputCatalog = $input?->catalog();
        if ($input === null || $inputCatalog === null) {
            return new Judgement('inconclusive', 'qpdf could not read the catalog of the input: qpdf '.$inputResult->describe(), $inputResult->exitCode, $inputResult->output);
        }

        $certificationReference = self::certificationReference($input, $inputCatalog);
        $certification = $input->resolve($certificationReference);
        if ($certificationReference === null) {
            return $input->isComplete()
                ? new Judgement('not_applicable', 'The input has no certification signature in /Perms /DocMDP', 0, '')
                : new Judgement('inconclusive', 'No certification signature was found in /Perms /DocMDP of an input qpdf had to recover', $inputResult->exitCode, '');
        }
        if (! is_array($certification) || ! array_key_exists('/ByteRange', $certification)) {
            return new Judgement('inconclusive', 'The /Perms /DocMDP of the input does not hold a signature dictionary', $inputResult->exitCode, '');
        }
        $permission = self::permission($input, $certification);
        if ($permission === null) {
            return new Judgement('inconclusive', 'The certification signature of the input has no DocMDP transform in /Reference, so its permissions cannot be read', $inputResult->exitCode, '');
        }
        if ($permission === self::PARAMETERS_NOT_A_DICTIONARY) {
            return new Judgement('inconclusive', 'The DocMDP transform parameters of the input are not a dictionary, so its permission level cannot be read', $inputResult->exitCode, '');
        }
        if (! in_array($permission, self::PERMISSION_LEVELS, true)) {
            return new Judgement('inconclusive', 'The DocMDP transform parameters of the input do not give a permission level of 1, 2 or 3: '.json_encode($permission), $inputResult->exitCode, '');
        }

        $outputResult = $this->runner->run(QpdfObjects::command($outputPath), $this->timeoutSeconds, new CommandAccess([$outputPath]));
        if ($outputResult->timedOut) {
            return $this->timedOut($outputResult);
        }
        $output = QpdfObjects::fromResult($outputResult);
        $outputCatalog = $output?->catalog();
        $outputListing = SignatureListing::fromResult($outputResult);
        if ($output === null || $outputCatalog === null || $outputListing->signatures === null) {
            return new Judgement('inconclusive', 'qpdf could not read the catalog of the output: qpdf '.$outputResult->describe(), $outputResult->exitCode, $outputResult->output);
        }

        $inputKeys = SignatureListing::fromResult($inputResult)->keys();
        $outputCertification = $output->resolve(self::certificationReference($output, $outputCatalog));
        $outputCertificationKey = is_array($outputCertification) ? self::key($output, $outputCertification) : null;
        $addedSignatures = [];
        foreach ($outputListing->signatures as $signature) {
            if (isset($inputKeys[SignatureListing::key($signature)])) {
                continue;
            }
            $dictionary = self::signatureDictionary($output, $signature);
            $kind = match (true) {
                $dictionary === null => 'unreadable',
                self::key($output, $dictionary) === $outputCertificationKey || self::permission($output, $dictionary) !== null => 'certification',
                $signature['type'] === self::DOCUMENT_TIMESTAMP_TYPE => 'document_timestamp',
                default => 'approval',
            };
            $addedSignatures[] = ['object' => $signature['object'], 'field' => $signature['field'], 'kind' => $kind];
        }
        $details = ['certification' => $certificationReference, 'permission' => $permission, 'added_signatures' => $addedSignatures];

        $describe = static fn (array $signatures): string => implode(', ', array_map(static fn (array $signature): string => ($signature['field'] ?? '') !== '' ? $signature['field'] : $signature['object'], $signatures));
        $ofKinds = static fn (string ...$kinds): array => array_values(array_filter($addedSignatures, static fn (array $signature): bool => in_array($signature['kind'], $kinds, true)));
        $restrictedSignatures = $ofKinds('approval', 'certification');
        $addedCertifications = $ofKinds('certification');
        $documentTimestamps = $ofKinds('document_timestamp');
        $unreadableSignatures = $ofKinds('unreadable');

        $problems = [];
        $undecided = [];
        $addedProblem = match (true) {
            $permission === 1 && $restrictedSignatures !== [] => 'the certification of the input permits no change (/P 1), but the output adds the signature(s) '.$describe($restrictedSignatures),
            $addedCertifications !== [] => 'the input is already certified, but the output adds the certification signature(s) '.$describe($addedCertifications),
            default => null,
        };
        if ($addedProblem !== null && $input->isComplete()) {
            $problems[] = $addedProblem;
        } elseif ($addedProblem !== null) {
            $undecided[] = $addedProblem.'; qpdf had to recover the input, so it may not have listed them in the input';
        }
        if ($outputCertificationKey !== self::key($input, $certification)) {
            if ($output->isComplete()) {
                $problems[] = 'the output no longer registers the certification of the input as /Perms /DocMDP';
            } else {
                $undecided[] = 'the output does not register the certification of the input as /Perms /DocMDP; qpdf had to recover the output, so it may not have listed it';
            }
        }
        if ($unreadableSignatures !== []) {
            $undecided[] = 'the dictionary of the added signature(s) '.$describe($unreadableSignatures).' cannot be read';
        }

        if ($problems !== []) {
            return new Judgement('invalid', ucfirst(implode('; ', $problems)), $outputResult->exitCode, '', $details);
        }
        if ($undecided !== []) {
            return new Judgement('inconclusive', ucfirst(implode('; ', $undecided)), $outputResult->exitCode, '', $details);
        }
        $recoveredDocuments = array_keys(array_filter(['input' => ! $input->isComplete(), 'output' => ! $output->isComplete()]));
        if ($recoveredDocuments !== []) {
            return new Judgement('inconclusive', 'qpdf had to recover the '.implode(' and the ', $recoveredDocuments).', so it may not have listed every signature', $outputResult->exitCode, '', $details);
        }

        $timestamps = $documentTimestamps === [] ? '' : $describe($documentTimestamps);
        if ($restrictedSignatures === []) {
            return new Judgement('valid', "The output adds no approval or certification signature to the certified input (/P {$permission})".($timestamps === '' ? '' : ", only the document timestamp(s) {$timestamps}, which DocMDP restrictions ignore"), $outputResult->exitCode, '', $details);
        }

        return new Judgement('valid', "The certification of the input permits signing (/P {$permission}), and the output adds only the approval signature(s) ".$describe($restrictedSignatures).($timestamps === '' ? '' : " and the document timestamp(s) {$timestamps}"), $outputResult->exitCode, '', $details);
    }

    /**
     * @param  array{object: string, field: ?string, type: ?string, sub_filter: ?string, byte_range: ?list<int>, contents: ?string}  $signature
     */
    private static function signatureDictionary(QpdfObjects $document, array $signature): ?array
    {
        $pending = [$document->resolve($signature['object'])];
        $key = json_encode([$signature['byte_range'], $signature['contents']], JSON_THROW_ON_ERROR);
        while ($pending !== []) {
            $value = array_shift($pending);
            if (! is_array($value)) {
                continue;
            }
            if (array_key_exists('/ByteRange', $value) && self::key($document, $value) === $key) {
                return $value;
            }
            array_push($pending, ...array_values($value));
        }

        return null;
    }

    private static function certificationReference(QpdfObjects $document, array $catalog): mixed
    {
        $permissions = $document->resolve($catalog['/Perms'] ?? null);

        return is_array($permissions) ? ($permissions['/DocMDP'] ?? null) : null;
    }

    private static function permission(QpdfObjects $document, array $signature): mixed
    {
        $references = $document->resolve($signature['/Reference'] ?? null);
        foreach (is_array($references) ? $references : [] as $reference) {
            $reference = $document->resolve($reference);
            if (! is_array($reference) || $document->resolve($reference['/TransformMethod'] ?? null) !== '/DocMDP') {
                continue;
            }
            $parameters = $document->resolve($reference['/TransformParams'] ?? null);
            if ($parameters === null) {
                return 2;
            }

            if (! is_array($parameters) || ($parameters !== [] && array_is_list($parameters))) {
                return self::PARAMETERS_NOT_A_DICTIONARY;
            }

            return $document->resolve($parameters['/P'] ?? null) ?? 2;
        }

        return null;
    }

    private static function key(QpdfObjects $document, array $signature): string
    {
        $contents = $document->bytes($signature['/Contents'] ?? null);

        return json_encode([$document->byteRange($signature), $contents === null ? null : bin2hex($contents)], JSON_THROW_ON_ERROR);
    }
}
