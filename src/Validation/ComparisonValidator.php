<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;
use SignerPHP\PdfSigningQualification\Artifact;

abstract class ComparisonValidator extends ToolValidator
{
    abstract protected function compare(string $inputPath, string $outputPath): Judgement;

    final protected function judgeOutput(ValidationSubject $subject, string $outputPath): Judgement
    {
        $inputPath = $subject->inputPath ?? throw new RuntimeException("{$this->analyzerId()} compares the output with its input, but the attempt {$subject->attemptId} gave no input path");
        if (Artifact::describe($inputPath) !== $subject->inputArtifact) {
            throw new RuntimeException("The input {$inputPath} does not match the input artifact of the attempt {$subject->attemptId}");
        }

        return $this->compare($inputPath, $outputPath);
    }
}
