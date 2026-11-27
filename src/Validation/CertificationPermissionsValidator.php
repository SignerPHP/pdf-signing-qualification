<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class CertificationPermissionsValidator extends ComparisonValidator
{
    private const array PERMISSION_LEVELS = [1, 2, 3];

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
            'description' => 'From qpdf\'s JSON listing of the input and of the output: an input whose catalog has no /Perms /DocMDP is not applicable; otherwise the permission level /P of the DocMDP transform in the /Reference of that signature (2 when absent) judges the signatures the output adds, those with a /ByteRange and /Contents the input does not hold: under /P 1 any added signature is invalid, under /P 2 and /P 3 an approval signature is permitted and a certification signature (one with a DocMDP transform, or registered as /Perms /DocMDP) is not, because a document has only one; the output must still register the certification of the input as /Perms /DocMDP. Other changes of the added revision and field locks (/FieldMDP) are not judged. A certification without a DocMDP transform, a level other than 1, 2 or 3, a document qpdf cannot read and a listing qpdf had to recover are inconclusive',
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
        $inputResult = $this->runner->run(QpdfObjects::command($inputPath), $this->timeoutSeconds);
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
        if (! in_array($permission, self::PERMISSION_LEVELS, true)) {
            return new Judgement('inconclusive', 'The DocMDP permission level of the input is '.json_encode($permission).', which is not 1, 2 or 3', $inputResult->exitCode, '');
        }

        $outputResult = $this->runner->run(QpdfObjects::command($outputPath), $this->timeoutSeconds);
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
        $outputCertificationReference = self::certificationReference($output, $outputCatalog);
        $addedSignatures = [];
        foreach ($outputListing->signatures as $signature) {
            if (isset($inputKeys[SignatureListing::key($signature)])) {
                continue;
            }
            $dictionary = $output->resolve($signature['object']);
            $isCertification = $signature['object'] === $outputCertificationReference || (is_array($dictionary) && self::permission($output, $dictionary) !== null);
            $addedSignatures[] = ['object' => $signature['object'], 'field' => $signature['field'], 'kind' => $isCertification ? 'certification' : 'approval'];
        }
        $details = ['certification' => $certificationReference, 'permission' => $permission, 'added_signatures' => $addedSignatures];

        $describe = static fn (array $signatures): string => implode(', ', array_map(static fn (array $signature): string => ($signature['field'] ?? '') !== '' ? $signature['field'] : $signature['object'], $signatures));
        $addedCertifications = array_values(array_filter($addedSignatures, static fn (array $signature): bool => $signature['kind'] === 'certification'));
        $problems = [];
        if ($permission === 1 && $addedSignatures !== []) {
            $problems[] = 'the certification of the input permits no change (/P 1), but the output adds the signature(s) '.$describe($addedSignatures);
        } elseif ($addedCertifications !== []) {
            $problems[] = 'the input is already certified, but the output adds the certification signature(s) '.$describe($addedCertifications);
        }
        $outputCertification = $output->resolve($outputCertificationReference);
        if (! is_array($outputCertification) || self::key($output, $outputCertification) !== self::key($input, $certification)) {
            $problems[] = 'the output no longer registers the certification of the input as /Perms /DocMDP';
        }

        if ($problems !== []) {
            return new Judgement('invalid', ucfirst(implode('; ', $problems)), $outputResult->exitCode, '', $details);
        }
        $recoveredDocuments = array_keys(array_filter(['input' => ! $input->isComplete(), 'output' => ! $output->isComplete()]));
        if ($recoveredDocuments !== []) {
            return new Judgement('inconclusive', 'qpdf had to recover the '.implode(' and the ', $recoveredDocuments).', so it may not have listed every signature', $outputResult->exitCode, '', $details);
        }

        return new Judgement('valid', "The certification of the input permits signing (/P {$permission}), and the output adds only the approval signature(s) ".$describe($addedSignatures), $outputResult->exitCode, '', $details);
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

            return is_array($parameters) ? $document->resolve($parameters['/P'] ?? 2) : 2;
        }

        return null;
    }

    private static function key(QpdfObjects $document, array $signature): string
    {
        $contents = $document->bytes($signature['/Contents'] ?? null);

        return json_encode([$document->byteRange($signature), $contents === null ? null : bin2hex($contents)], JSON_THROW_ON_ERROR);
    }
}
