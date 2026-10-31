<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class PyHankoValidator extends CommandLineValidator
{
    private const array ARGUMENTS = ['sign', 'validate', '--trust-replace', '--no-revocation-check'];

    private const string HYBRID_REFERENCE_REFUSAL = 'Settings do not permit validation of signatures in hybrid-reference files.';

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
            'description' => 'Integrity and cryptographic validity of every embedded signature, with no trust roots and no revocation lookup, so trust does not affect the verdict',
            'parameters' => ['arguments' => self::ARGUMENTS],
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

    protected function judge(string $outputPath): Judgement
    {
        $result = $this->runner->run(['pyhanko', ...self::ARGUMENTS, $outputPath], $this->timeoutSeconds);
        if ($result->timedOut) {
            return $this->timedOut($result);
        }

        $signatures = [];
        $pendingError = null;
        foreach (preg_split('/\R/', $result->output) ?: [] as $line) {
            if (preg_match('/An error occurred while validating this signature: (.+)$/', $line, $match) === 1) {
                $pendingError = $match[1];
            } elseif (preg_match('/^(.+):([0-9a-f]{64}):(INTACT:\S*|INVALID)$/', $line, $match) === 1) {
                $signature = [
                    'field' => $match[1],
                    'signer_certificate_sha256' => $match[2],
                    'status' => $match[3],
                ];
                if ($pendingError !== null) {
                    $signature['error'] = $pendingError;
                    $pendingError = null;
                }
                $signatures[] = $signature;
            }
        }
        $details = ['signatures' => $signatures];

        $completed = $result->exitCode === 0 || ($result->exitCode === 1 && str_contains($result->output, 'Error: Validation failed'));
        if (! $completed) {
            return new Judgement('inconclusive', 'pyHanko '.$result->describe().' before reporting every signature', $result->exitCode, $result->output, $details);
        }
        if ($signatures === []) {
            return new Judgement('invalid', 'pyHanko found no signature', $result->exitCode, $result->output, $details);
        }

        $refusedFields = array_column(array_filter($signatures, static fn (array $signature): bool => ($signature['error'] ?? null) === self::HYBRID_REFERENCE_REFUSAL), 'field');
        $invalidFields = array_column(array_filter($signatures, static fn (array $signature): bool => $signature['status'] === 'INVALID' && ! in_array($signature['field'], $refusedFields, true)), 'field');
        if ($invalidFields !== []) {
            return new Judgement('invalid', 'pyHanko judged the signature(s) '.implode(', ', $invalidFields).' not intact or not cryptographically valid', $result->exitCode, $result->output, $details);
        }
        if ($refusedFields !== []) {
            return new Judgement('inconclusive', 'pyHanko refused to judge the signature(s) '.implode(', ', $refusedFields).' under its strict syntax policy: '.self::HYBRID_REFERENCE_REFUSAL, $result->exitCode, $result->output, $details);
        }

        return new Judgement('valid', 'pyHanko judged '.count($signatures).' signature(s) intact and cryptographically valid', $result->exitCode, $result->output, $details);
    }
}
