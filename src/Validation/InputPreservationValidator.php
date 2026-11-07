<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;

final class InputPreservationValidator extends ComparisonValidator
{
    public function analyzerId(): string
    {
        return 'cmp';
    }

    protected function property(): string
    {
        return 'input_preservation';
    }

    protected function policy(): array
    {
        return [
            'id' => 'cmp-input-prefix',
            'description' => 'The output must start with every byte of the input, compared byte for byte over the input byte size, as an incremental update leaves them',
            'parameters' => ['arguments' => ['-n']],
        ];
    }

    protected function versionCommand(): array
    {
        return ['cmp', '--version'];
    }

    protected function versionPattern(): string
    {
        return '/^cmp \(GNU diffutils\) (\S+)$/m';
    }

    protected function compare(string $inputPath, string $outputPath): Judgement
    {
        $inputSize = filesize($inputPath);
        if ($inputSize === false) {
            throw new RuntimeException("Unable to read the size of the input {$inputPath}");
        }

        $result = $this->runner->run(['cmp', '-n', (string) $inputSize, $inputPath, $outputPath], $this->timeoutSeconds);
        if ($result->timedOut) {
            return $this->timedOut($result);
        }

        $details = ['compared_bytes' => $inputSize];

        return match ($result->exitCode) {
            0 => new Judgement('valid', 'The output starts with every byte of the input', 0, $result->output, $details),
            1 => new Judgement('invalid', 'The output does not start with the bytes of the input: '.trim($result->output), 1, $result->output, $details),
            default => new Judgement('inconclusive', 'cmp '.$result->describe(), $result->exitCode, $result->output, $details),
        };
    }
}
