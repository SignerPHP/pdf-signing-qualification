<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class PyHankoValidator extends CommandLineValidator
{
    private const array ARGUMENTS = ['sign', 'validate', '--trust-replace', '--no-revocation-check'];

    private const string HYBRID_REFERENCE_REFUSAL = 'Settings do not permit validation of signatures in hybrid-reference files.';

    private const string DOCUMENT_TIMESTAMP_TYPE = '/DocTimeStamp';

    public function analyzerId(): string
    {
        return 'pyhanko';
    }

    protected function property(): string
    {
        return 'cms_signature';
    }

    protected function policy(): array
    {
        return [
            'id' => 'pyhanko-integrity-without-trust',
            'description' => 'Integrity and cryptographic validity of every embedded signature other than a document timestamp, with no trust roots and no revocation lookup, so trust does not affect the verdict; pyHanko finds signatures through the form and does not validate document timestamps, so every dictionary with a /ByteRange that qpdf lists in the document, other than one of type /DocTimeStamp, must be reported by pyHanko under its full field name; a signature that pyHanko does not report, or refuses to validate with one of the inconclusive refusals, is not judged, so it makes the verdict inconclusive unless another signature is invalid; so does a listing that qpdf cannot produce or had to recover, which cannot show that every signature was reported; when pyHanko finds no signature but qpdf lists one, an output stays invalid and an input is inconclusive',
            'parameters' => [
                'arguments' => self::ARGUMENTS,
                'inconclusive_refusals' => [self::HYBRID_REFERENCE_REFUSAL],
                'signature_listing' => ['qpdf', ...SignatureListing::ARGUMENTS],
                'not_judged_types' => [self::DOCUMENT_TIMESTAMP_TYPE],
                'unjudged_signatures' => 'inconclusive',
            ],
        ];
    }

    protected function versionCommand(): array
    {
        return ['pyhanko', '--version'];
    }

    protected function versionPattern(): string
    {
        return '/^pyHanko, version (\S+)/m';
    }

    protected function judge(string $documentPath): Judgement
    {
        $result = $this->runner->run(['pyhanko', ...self::ARGUMENTS, $documentPath], $this->timeoutSeconds);
        if ($result->timedOut) {
            return $this->timedOut($result);
        }

        $signatures = [];
        $pendingError = null;
        foreach (preg_split('/\R/', $result->output) ?: [] as $line) {
            if (preg_match('/^(.*):([0-9a-f]{64}):(INTACT:\S*|INVALID)$/', $line, $match) === 1) {
                $signature = [
                    'field' => $match[1],
                    'signer_certificate_sha256' => $match[2],
                    'status' => $match[3],
                ];
                if ($pendingError !== null) {
                    $signature['error'] = $pendingError;
                }
                $signatures[] = $signature;
                $pendingError = null;
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2},\d{3} - cli - ERROR - An error occurred while validating this signature: (.+)$/', $line, $match) === 1) {
                $pendingError = $match[1];
            } else {
                $pendingError = null;
            }
        }
        $details = ['signatures' => $signatures];

        $completed = $result->exitCode === 0 || ($result->exitCode === 1 && str_contains($result->output, 'Error: Validation failed'));
        if (! $completed) {
            return new Judgement('inconclusive', 'pyHanko '.$result->describe().' before reporting every signature', $result->exitCode, $result->output, $details);
        }
        if ($signatures === [] && $result->exitCode !== 0) {
            return new Judgement('inconclusive', 'pyHanko ended with a failed validation, but no signature line could be read', $result->exitCode, $result->output, $details);
        }

        $isRefused = static fn (array $signature): bool => ($signature['error'] ?? null) === self::HYBRID_REFERENCE_REFUSAL;
        $refusedFields = array_column(array_filter($signatures, $isRefused), 'field');
        $invalidFields = array_column(array_filter($signatures, static fn (array $signature): bool => $signature['status'] === 'INVALID' && ! $isRefused($signature)), 'field');
        if ($invalidFields !== []) {
            return new Judgement('invalid', 'pyHanko judged the signature(s) '.implode(', ', $invalidFields).' not intact or not cryptographically valid', $result->exitCode, $result->output, $details);
        }

        $listing = SignatureListing::read($this->runner, $documentPath, $this->timeoutSeconds);
        $unjudged = $listing->signatures === null ? [] : self::unjudgedSignatures($listing->signatures, array_column($signatures, 'field'));
        if ($listing->signatures !== null) {
            $details['unjudged_signatures'] = $unjudged;
        }
        $unjudgedProblem = 'did not judge the signature(s) '.implode(', ', array_map(static fn (array $signature): string => $signature['field'] ?? $signature['object'], $unjudged)).' that qpdf lists in the document';

        if ($signatures === []) {
            return $unjudged === []
                ? Judgement::noSignature('pyHanko found no signature', $result->exitCode, $result->output, $details)
                : new Judgement('invalid', 'pyHanko found no signature, but '.$unjudgedProblem, $result->exitCode, $result->output, $details, inputVerdict: 'inconclusive');
        }

        $problems = [];
        if ($refusedFields !== []) {
            $problems[] = 'pyHanko refused to judge the signature(s) '.implode(', ', $refusedFields).' under its strict syntax policy: '.self::HYBRID_REFERENCE_REFUSAL;
        }
        if ($listing->signatures === null) {
            $problems[] = 'qpdf could not list the signatures of the document, so it cannot be checked that pyHanko judged every one: qpdf '.$listing->result->describe();
        } elseif ($unjudged !== []) {
            $problems[] = 'pyHanko '.$unjudgedProblem;
        }
        $judged = 'pyHanko judged '.count($signatures).' signature(s) intact and cryptographically valid';
        if ($listing->signatures !== null && ! $listing->isComplete()) {
            $problems[] = ($problems === [] ? $judged.', but ' : '').'qpdf had to recover the document, so it may not have listed every signature';
        }
        if ($problems !== []) {
            $summary = count($problems) === 1 ? $problems[0] : implode('; ', array_map(static fn (string $problem): string => rtrim($problem, '.'), $problems));

            return new Judgement('inconclusive', $summary, $result->exitCode, $result->output, $details);
        }

        return new Judgement('valid', $judged, $result->exitCode, $result->output, $details);
    }

    private static function listedName(string $reportedField): string
    {
        $escapes = ['n' => "\n", 'r' => "\r", 't' => "\t", '\\' => '\\', "'" => "'", '"' => '"'];

        return (string) preg_replace_callback(
            '/(?<![^.])b(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")(?![^.])/s',
            static function (array $literal) use ($escapes): string {
                $bytes = (string) preg_replace_callback(
                    '/\\\\(x[0-9a-f]{2}|.)/s',
                    static fn (array $escape): string => str_starts_with($escape[1], 'x') && strlen($escape[1]) === 3 ? (string) hex2bin(substr($escape[1], 1)) : ($escapes[$escape[1]] ?? '\\'.$escape[1]),
                    $literal[1] !== '' ? $literal[1] : ($literal[2] ?? ''),
                );

                return mb_check_encoding($bytes, 'UTF-8') ? $bytes : 'b:'.bin2hex($bytes);
            },
            $reportedField,
        );
    }

    /**
     * @param  list<array{object: string, field: ?string, type: ?string, byte_range: ?list<int>, contents: ?string}>  $listedSignatures
     * @param  list<string>  $reportedFields
     * @return list<array{object: string, field: ?string}>
     */
    private static function unjudgedSignatures(array $listedSignatures, array $reportedFields): array
    {
        $reportedCounts = array_count_values(array_map(self::listedName(...), $reportedFields));
        $unjudged = [];
        foreach ($listedSignatures as $signature) {
            if ($signature['type'] === self::DOCUMENT_TIMESTAMP_TYPE) {
                continue;
            }
            $field = $signature['field'];
            if ($field !== null && ($reportedCounts[$field] ?? 0) > 0) {
                $reportedCounts[$field]--;

                continue;
            }
            $unjudged[] = ['object' => $signature['object'], 'field' => $field];
        }

        return $unjudged;
    }
}
