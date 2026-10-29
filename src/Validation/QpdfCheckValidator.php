<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class QpdfCheckValidator extends CommandLineValidator
{
    public function analyzerId(): string
    {
        return 'qpdf';
    }

    protected function property(): string
    {
        return 'structure';
    }

    protected function policy(): array
    {
        return [
            'id' => 'qpdf-check',
            'description' => 'Syntax and stream encoding check of the whole file; warnings mean qpdf recovered from damage it may not fully describe',
            'parameters' => ['arguments' => ['--check']],
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

    protected function judge(string $outputPath): Judgement
    {
        $result = $this->runner->run(['qpdf', '--check', $outputPath], $this->timeoutSeconds);
        if ($result->timedOut) {
            return $this->timedOut($result);
        }

        [$verdict, $summary] = match ($result->exitCode) {
            0 => ['valid', 'qpdf found no syntax or stream encoding errors'],
            2 => ['invalid', 'qpdf found errors'],
            3 => ['inconclusive', 'qpdf found warnings and no errors'],
            default => ['inconclusive', 'qpdf '.$result->describe()],
        };

        return new Judgement($verdict, $summary, $result->exitCode, $result->output);
    }
}
