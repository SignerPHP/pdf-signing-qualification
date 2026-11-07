<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Regression;

use SignerPHP\PdfSigningQualification\Validation\CommandRunner;

final class HybridXrefCondition implements RegressionCondition
{
    private const float TIMEOUT_SECONDS = 30.0;

    public function __construct(
        private readonly CommandRunner $runner,
    ) {}

    public function evaluate(string $inputPath, string $outputPath): array
    {
        $result = $this->runner->run(['qpdf', '--show-object=trailer', $outputPath], self::TIMEOUT_SECONDS);
        if ($result->timedOut || $result->exitCode !== 0) {
            return ['status' => 'undetermined', 'evidence' => "qpdf could not show the final trailer of the output: it {$result->describe()}: ".trim($result->output)];
        }
        if (preg_match('#/XRefStm\s+(\d+)#', $result->output, $matches) !== 1) {
            return ['status' => 'absent', 'evidence' => 'The final trailer of the output has no /XRefStm'];
        }

        $offset = (int) $matches[1];
        $inputSize = (int) filesize($inputPath);

        return $offset < $inputSize
            ? ['status' => 'present', 'evidence' => "The final trailer of the output has /XRefStm {$offset}, inside the {$inputSize} bytes of the input"]
            : ['status' => 'absent', 'evidence' => "The final trailer of the output has /XRefStm {$offset}, after the {$inputSize} bytes of the input"];
    }
}
