<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;

final class PriorSignaturesValidator extends ComparisonValidator
{
    public function analyzerId(): string
    {
        return 'qpdf-prior-signatures';
    }

    protected function property(): string
    {
        return 'prior_signatures';
    }

    protected function policy(): array
    {
        return [
            'id' => 'qpdf-prior-signatures-unchanged',
            'description' => 'Every dictionary with a /ByteRange in the input, as listed by qpdf, must still be in the output with the same /ByteRange and /Contents, as the value of the same field, and the bytes its range covers must be the same in the input and in the output; an input without one is not applicable',
            'parameters' => ['locator_arguments' => ['qpdf', ...SignatureListing::ARGUMENTS]],
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
        $inputListing = SignatureListing::read($this->runner, $inputPath, $this->timeoutSeconds);
        if ($inputListing->result->timedOut) {
            return $this->timedOut($inputListing->result);
        }
        if ($inputListing->signatures === null) {
            return new Judgement('inconclusive', 'qpdf could not list the signatures of the input: qpdf '.$inputListing->result->describe(), $inputListing->result->exitCode, $inputListing->result->output);
        }
        if ($inputListing->signatures === []) {
            return $inputListing->isComplete()
                ? new Judgement('not_applicable', 'The input has no dictionary with a /ByteRange', 0, '')
                : new Judgement('inconclusive', 'No dictionary with a /ByteRange was found in an input qpdf had to recover', $inputListing->result->exitCode, '');
        }

        $outputListing = SignatureListing::read($this->runner, $outputPath, $this->timeoutSeconds);
        if ($outputListing->result->timedOut) {
            return $this->timedOut($outputListing->result);
        }
        if ($outputListing->signatures === null) {
            return new Judgement('inconclusive', 'qpdf could not list the signatures of the output: qpdf '.$outputListing->result->describe(), $outputListing->result->exitCode, $outputListing->result->output);
        }

        $inputSize = self::size($inputPath);
        $signatures = array_map(static fn (array $signature): array => $signature + self::check($signature, $inputPath, $inputSize, $outputPath, $outputListing), $inputListing->signatures);
        $details = ['signatures' => array_map(static fn (array $signature): array => array_diff_key($signature, ['contents' => true]), $signatures)];
        $exitCode = $outputListing->result->exitCode;

        $describe = static fn (array $signature): string => ($signature['field'] ?? $signature['object']).': '.$signature['problem'];
        $broken = array_filter($signatures, static fn (array $signature): bool => $signature['outcome'] === 'broken');
        if ($broken !== []) {
            return new Judgement('invalid', implode('; ', array_map($describe, $broken)), $exitCode, '', $details);
        }
        $undecided = array_filter($signatures, static fn (array $signature): bool => $signature['outcome'] === 'inconclusive');
        if ($undecided !== []) {
            return new Judgement('inconclusive', implode('; ', array_map($describe, $undecided)), $exitCode, '', $details);
        }

        if (! $inputListing->isComplete()) {
            return new Judgement('inconclusive', 'The output keeps the '.count($signatures).' signature(s) qpdf listed in the input unchanged, but qpdf had to recover the input, so it may not have listed every signature', $exitCode, '', $details);
        }

        return new Judgement('valid', 'The output keeps the '.count($signatures).' signature(s) of the input unchanged', $exitCode, '', $details);
    }

    /**
     * @param  array{object: string, field: ?string, byte_range: ?list<int>, contents: ?string}  $signature
     * @return array{outcome: string, problem?: string}
     */
    private static function check(array $signature, string $inputPath, int $inputSize, string $outputPath, SignatureListing $outputListing): array
    {
        $byteRange = $signature['byte_range'];
        if ($byteRange === null || $signature['contents'] === null) {
            return ['outcome' => 'inconclusive', 'problem' => 'its /ByteRange or /Contents in the input cannot be read'];
        }
        [$firstOffset, $firstLength, $secondOffset, $secondLength] = $byteRange;
        if ($firstOffset + $firstLength > $inputSize || $secondOffset + $secondLength > $inputSize) {
            return ['outcome' => 'inconclusive', 'problem' => 'its byte range extends past the end of the input, so the input does not hold every byte it covers'];
        }

        $key = SignatureListing::key($signature);
        $matches = array_values(array_filter($outputListing->signatures ?? [], static fn (array $candidate): bool => SignatureListing::key($candidate) === $key));
        if ($matches === []) {
            return $outputListing->isComplete()
                ? ['outcome' => 'broken', 'problem' => 'the output has no dictionary with the same /ByteRange and /Contents']
                : ['outcome' => 'inconclusive', 'problem' => 'no dictionary with the same /ByteRange and /Contents was found in an output qpdf had to recover'];
        }
        if ($signature['field'] !== null && ! in_array($signature['field'], array_column($matches, 'field'), true)) {
            return ['outcome' => 'broken', 'problem' => "it is no longer the value of the field {$signature['field']}"];
        }

        if (self::coveredDigest($inputPath, $byteRange) !== self::coveredDigest($outputPath, $byteRange)) {
            return ['outcome' => 'broken', 'problem' => 'the bytes its range covers changed'];
        }

        return ['outcome' => 'intact'];
    }

    private static function size(string $path): int
    {
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException("Unable to read the size of the document {$path}");
        }

        return $size;
    }

    /**
     * @param  list<int>  $byteRange
     */
    private static function coveredDigest(string $path, array $byteRange): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to read the document {$path}");
        }

        try {
            $context = hash_init('sha256');
            foreach (array_chunk($byteRange, 2) as [$offset, $length]) {
                if (fseek($handle, $offset) !== 0) {
                    throw new RuntimeException("Unable to read the document {$path}");
                }
                $hashed = hash_update_stream($context, $handle, $length);
                hash_update($context, pack('J', $hashed));
            }

            return hash_final($context);
        } finally {
            fclose($handle);
        }
    }
}
