<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;

final class SignatureCoverageValidator extends ComparisonValidator
{
    private const string PDF_WHITESPACE = "\x00\t\n\x0C\r ";

    public function analyzerId(): string
    {
        return 'qpdf-byte-range';
    }

    protected function property(): string
    {
        return 'byte_range';
    }

    protected function policy(): array
    {
        return [
            'id' => 'qpdf-byte-range-whole-file',
            'description' => 'The signatures added by the implementation are the dictionaries with a /ByteRange that qpdf lists in the output and not in the input; the range of each must start at the first byte, end at the last byte of the output, and leave out exactly the hexadecimal string of its own /Contents',
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
            return new Judgement('inconclusive', 'qpdf could not list the signatures of the input, so the added signature cannot be told apart: qpdf '.$inputListing->result->describe(), $inputListing->result->exitCode, $inputListing->result->output);
        }

        $outputListing = SignatureListing::read($this->runner, $outputPath, $this->timeoutSeconds);
        if ($outputListing->result->timedOut) {
            return $this->timedOut($outputListing->result);
        }
        if ($outputListing->signatures === null) {
            return new Judgement('inconclusive', 'qpdf could not list the signatures of the output: qpdf '.$outputListing->result->describe(), $outputListing->result->exitCode, $outputListing->result->output);
        }

        $inputKeys = $inputListing->keys();
        $added = array_values(array_filter($outputListing->signatures, static fn (array $signature): bool => ! isset($inputKeys[SignatureListing::key($signature)])));
        $exitCode = $outputListing->result->exitCode;
        if ($added === []) {
            return $outputListing->isComplete()
                ? new Judgement('invalid', 'The output holds no signature that is not already in the input', $exitCode, '')
                : new Judgement('inconclusive', 'No signature that is not already in the input was found in an output qpdf had to recover', $exitCode, '');
        }

        $output = file_get_contents($outputPath);
        if ($output === false) {
            throw new RuntimeException("Unable to read the document {$outputPath}");
        }

        $signatures = array_map(static fn (array $signature): array => $signature + self::check($signature, $output), $added);
        $details = [
            'output_byte_size' => strlen($output),
            'signatures' => array_map(static fn (array $signature): array => array_diff_key($signature, ['contents' => true]), $signatures),
        ];

        $name = static fn (array $signature): string => $signature['field'] ?? $signature['object'];
        $problems = array_filter($signatures, static fn (array $signature): bool => $signature['outcome'] !== 'valid');
        if ($problems === []) {
            return new Judgement('valid', implode(', ', array_map($name, $signatures)).' covers every byte of the output except its /Contents', $exitCode, '', $details);
        }

        $summary = implode('; ', array_map(static fn (array $signature): string => $name($signature).': '.$signature['problem'], $problems));
        $verdict = in_array('invalid', array_column($problems, 'outcome'), true) ? 'invalid' : 'inconclusive';

        return new Judgement($verdict, $summary, $exitCode, '', $details);
    }

    /**
     * @param  array{object: string, field: ?string, byte_range: ?list<int>, contents: ?string}  $signature
     * @return array{outcome: string, problem?: string}
     */
    private static function check(array $signature, string $output): array
    {
        $byteRange = $signature['byte_range'];
        if ($byteRange === null) {
            return ['outcome' => 'invalid', 'problem' => 'the /ByteRange is not an array of four integers'];
        }

        [$firstOffset, $firstLength, $secondOffset, $secondLength] = $byteRange;
        $size = strlen($output);
        if ($firstOffset !== 0) {
            return ['outcome' => 'invalid', 'problem' => "the byte range starts at byte {$firstOffset}, leaving the bytes before it uncovered"];
        }
        if ($firstLength >= $secondOffset || $secondOffset + $secondLength > $size) {
            return ['outcome' => 'invalid', 'problem' => 'the byte range does not fit the file'];
        }
        if ($secondOffset + $secondLength < $size) {
            return ['outcome' => 'invalid', 'problem' => 'the byte range does not reach the end of the file, leaving '.($size - $secondOffset - $secondLength).' bytes uncovered'];
        }

        $whitespace = preg_quote(self::PDF_WHITESPACE, '/');
        $gap = substr($output, $firstLength, $secondOffset - $firstLength);
        if (preg_match('/\A<([0-9A-Fa-f'.$whitespace.']*)>\z/', $gap, $match) !== 1) {
            return ['outcome' => 'invalid', 'problem' => 'the bytes the range leaves out are not the hexadecimal string of its /Contents'];
        }
        if ($signature['contents'] === null) {
            return ['outcome' => 'inconclusive', 'problem' => 'qpdf did not give the bytes of its /Contents, so the bytes the range leaves out cannot be explained'];
        }

        $hex = strtolower(str_replace(str_split(self::PDF_WHITESPACE), '', $match[1]));
        if (strlen($hex) % 2 !== 0) {
            $hex .= '0';
        }
        if ($hex !== $signature['contents']) {
            return ['outcome' => 'invalid', 'problem' => 'the bytes the range leaves out are not the hexadecimal string of its /Contents'];
        }

        return ['outcome' => 'valid'];
    }
}
