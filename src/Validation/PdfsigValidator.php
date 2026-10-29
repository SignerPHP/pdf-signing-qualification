<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class PdfsigValidator extends CommandLineValidator
{
    private const string VALID_STATE = 'Signature is Valid.';

    private const array UNDECIDED_STATES = ['Signature has not yet been verified.', 'Unknown Validation Failure.'];

    public function analyzerId(): string
    {
        return 'pdfsig';
    }

    protected function property(): string
    {
        return 'cms_signature';
    }

    protected function policy(): array
    {
        return [
            'id' => 'pdfsig-no-certificate',
            'description' => 'Cryptographic validation of every signature over its signed ranges, without certificate validation',
            'parameters' => ['arguments' => ['-nocert']],
        ];
    }

    protected function versionCommand(): array
    {
        return ['pdfsig', '-v'];
    }

    protected function versionPattern(): string
    {
        return '/^pdfsig version (\S+)$/m';
    }

    protected function judge(string $outputPath): Judgement
    {
        $result = $this->runner->run(['pdfsig', '-nocert', $outputPath], $this->timeoutSeconds);
        if ($result->timedOut) {
            return $this->timedOut($result);
        }

        if (str_contains($result->output, 'does not contain any signatures')) {
            return new Judgement('invalid', 'pdfsig found no signature', $result->exitCode, $result->output);
        }

        $blocks = preg_split('/^Signature #(\d+):$/m', $result->output, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $signatures = [];
        for ($index = 1; $index + 1 < count($blocks); $index += 2) {
            $state = preg_match('/^\s*- Signature Validation: (.+)$/m', $blocks[$index + 1], $matches) === 1 ? trim($matches[1]) : null;
            $field = preg_match('/^\s*- Signature Field Name: (.+)$/m', $blocks[$index + 1], $matches) === 1 ? trim($matches[1]) : null;
            $signatures[] = [
                'number' => (int) $blocks[$index],
                'field' => $field,
                'validation' => $state,
                'total_document_signed' => str_contains($blocks[$index + 1], '- Total document signed'),
            ];
        }

        $details = ['signatures' => $signatures];
        if ($result->exitCode !== 0 || $signatures === []) {
            return new Judgement('inconclusive', 'pdfsig '.$result->describe().' without a signature report', $result->exitCode, $result->output, $details);
        }

        $states = array_column($signatures, 'validation');
        if (in_array(null, $states, true) || array_intersect($states, self::UNDECIDED_STATES) !== []) {
            return new Judgement('inconclusive', 'pdfsig did not decide every signature', $result->exitCode, $result->output, $details);
        }

        $invalidStates = array_values(array_unique(array_diff($states, [self::VALID_STATE])));
        if ($invalidStates !== []) {
            return new Judgement('invalid', 'pdfsig rejected a signature: '.implode(' ', $invalidStates), $result->exitCode, $result->output, $details);
        }

        return new Judgement('valid', 'pdfsig validated '.count($signatures).' signature(s)', $result->exitCode, $result->output, $details);
    }
}
