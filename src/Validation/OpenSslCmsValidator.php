<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;

final class OpenSslCmsValidator extends CommandLineValidator
{
    private const int VERIFICATION_FAILURE_EXIT_CODE = 4;

    private const array UNDECODABLE_EXIT_CODES = [2, 3];

    private const array LOCATOR_ARGUMENTS = ['--json=2', '--json-key=qpdf', '--no-warn'];

    private const array DECODE_ARGUMENTS = ['cms', '-cmsout', '-noout', '-inform', 'DER'];

    private const array ARGUMENTS = ['cms', '-verify', '-binary', '-inform', 'DER', '-noverify', '-out', '/dev/null'];

    private const string PDF_WHITESPACE = "\x00\t\n\x0C\r ";

    public function analyzerId(): string
    {
        return 'openssl-cms';
    }

    protected function property(): string
    {
        return 'cms_signature';
    }

    protected function policy(): array
    {
        return [
            'id' => 'openssl-cms-byte-range-without-chain',
            'description' => 'Every dictionary with a /ByteRange, as listed by qpdf, delimits the signed bytes and the hexadecimal CMS in its gap; the CMS must decode and is verified over the signed bytes without certificate chain verification',
            'parameters' => [
                'locator_arguments' => ['qpdf', ...self::LOCATOR_ARGUMENTS],
                'decode_arguments' => self::DECODE_ARGUMENTS,
                'arguments' => self::ARGUMENTS,
            ],
        ];
    }

    protected function versionCommand(): array
    {
        return ['openssl', 'version'];
    }

    protected function versionPattern(): string
    {
        return '/^OpenSSL (\d+\.\d+\.\d+)/';
    }

    protected function judge(string $outputPath): Judgement
    {
        $document = file_get_contents($outputPath);
        if ($document === false) {
            throw new RuntimeException("Unable to read the output {$outputPath}");
        }

        $located = $this->runner->run(['qpdf', ...self::LOCATOR_ARGUMENTS, $outputPath], $this->timeoutSeconds);
        if ($located->timedOut) {
            return $this->timedOut($located);
        }
        $objects = in_array($located->exitCode, [0, 3], true) ? self::objects($located->output) : null;
        if ($objects === null) {
            return new Judgement('inconclusive', 'qpdf could not list the signature dictionaries: qpdf '.$located->describe(), $located->exitCode, $located->output);
        }

        $byteRanges = [];
        self::collectByteRanges($objects, $objects, $byteRanges);
        if ($byteRanges === []) {
            return new Judgement('invalid', 'No dictionary with a /ByteRange was found', null, '');
        }

        $signatures = [];
        $rawOutput = '';
        foreach ($byteRanges as $number => $byteRange) {
            $signature = ['byte_range' => $byteRange] + ($byteRange === null
                ? ['outcome' => 'invalid', 'problem' => 'the /ByteRange is not an array of four integers', 'exit_code' => null, 'output' => '']
                : $this->verify($document, $byteRange));
            $signatures[] = $signature;
            $rawOutput .= 'Signature #'.($number + 1).' ['.implode(' ', $byteRange ?? ['malformed'])."]:\n".$signature['output'];
        }

        $details = ['signatures' => array_map(static fn (array $signature): array => array_diff_key($signature, ['output' => true]), $signatures)];
        $exitCode = count($signatures) === 1 ? $signatures[0]['exit_code'] : null;
        $outcomes = array_column($signatures, 'outcome');

        if (in_array('invalid', $outcomes, true)) {
            $problems = array_values(array_unique(array_column(array_filter($signatures, static fn (array $signature): bool => $signature['outcome'] === 'invalid'), 'problem')));

            return new Judgement('invalid', 'openssl rejected a signature: '.implode('; ', $problems), $exitCode, $rawOutput, $details);
        }
        if (in_array('timed_out', $outcomes, true)) {
            return new Judgement('inconclusive', "openssl did not finish within {$this->timeoutSeconds} seconds", $exitCode, $rawOutput, $details);
        }
        if (in_array('inconclusive', $outcomes, true)) {
            return new Judgement('inconclusive', 'openssl could not verify a signature', $exitCode, $rawOutput, $details);
        }

        return new Judgement('valid', 'openssl verified '.count($signatures).' CMS signature(s) over their byte ranges', $exitCode, $rawOutput, $details);
    }

    private static function objects(string $json): ?array
    {
        $decoded = json_decode($json, true);
        $objects = is_array($decoded) ? ($decoded['qpdf'][1] ?? null) : null;

        return is_array($objects) ? $objects : null;
    }

    /**
     * @param  list<list<int>|null>  $byteRanges
     */
    private static function collectByteRanges(mixed $value, array $objects, array &$byteRanges): void
    {
        if (! is_array($value)) {
            return;
        }
        if (array_key_exists('/ByteRange', $value)) {
            $byteRanges[] = self::byteRange($value['/ByteRange'], $objects);
        }
        foreach ($value as $key => $child) {
            if ($key !== '/ByteRange') {
                self::collectByteRanges($child, $objects, $byteRanges);
            }
        }
    }

    /**
     * @return list<int>|null
     */
    private static function byteRange(mixed $value, array $objects): ?array
    {
        $value = self::resolve($value, $objects);
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 4) {
            return null;
        }

        $byteRange = [];
        foreach ($value as $element) {
            $element = self::resolve($element, $objects);
            if (! is_int($element) || $element < 0) {
                return null;
            }
            $byteRange[] = $element;
        }

        return $byteRange;
    }

    private static function resolve(mixed $value, array $objects): mixed
    {
        if (is_string($value) && preg_match('/^\d+ \d+ R$/', $value) === 1) {
            return $objects['obj:'.$value]['value'] ?? null;
        }

        return $value;
    }

    /**
     * @param  list<int>  $byteRange
     * @return array{outcome: string, problem?: string, exit_code: ?int, output: string}
     */
    private function verify(string $document, array $byteRange): array
    {
        [$firstOffset, $firstLength, $secondOffset, $secondLength] = $byteRange;
        if ($firstOffset !== 0 || $firstLength >= $secondOffset || $secondOffset + $secondLength > strlen($document)) {
            return ['outcome' => 'invalid', 'problem' => 'the byte range does not fit the file', 'exit_code' => null, 'output' => ''];
        }

        $gap = substr($document, $firstLength, $secondOffset - $firstLength);
        $whitespace = preg_quote(self::PDF_WHITESPACE, '/');
        if (preg_match('/\A<([0-9A-Fa-f'.$whitespace.']*)>\z/', $gap, $hexMatch) !== 1) {
            return ['outcome' => 'invalid', 'problem' => 'the byte range gap is not a hexadecimal string', 'exit_code' => null, 'output' => ''];
        }

        $hex = str_replace(str_split(self::PDF_WHITESPACE), '', $hexMatch[1]);
        if (strlen($hex) % 2 !== 0) {
            $hex .= '0';
        }

        [$decoded, $result] = $this->decodeAndVerify((string) hex2bin($hex), substr($document, 0, $firstLength).substr($document, $secondOffset, $secondLength));

        if ($result === null) {
            if ($decoded->timedOut) {
                return ['outcome' => 'timed_out', 'exit_code' => null, 'output' => $decoded->output];
            }
            if (in_array($decoded->exitCode, self::UNDECODABLE_EXIT_CODES, true)) {
                return ['outcome' => 'invalid', 'problem' => 'the byte range gap does not hold a decodable CMS', 'exit_code' => $decoded->exitCode, 'output' => $decoded->output];
            }

            return ['outcome' => 'inconclusive', 'exit_code' => $decoded->exitCode, 'output' => $decoded->output];
        }
        if ($result->timedOut) {
            return ['outcome' => 'timed_out', 'exit_code' => null, 'output' => $result->output];
        }
        if ($result->exitCode === self::VERIFICATION_FAILURE_EXIT_CODE) {
            return ['outcome' => 'invalid', 'problem' => 'CMS verification failed', 'exit_code' => $result->exitCode, 'output' => $result->output];
        }
        if ($result->exitCode !== 0) {
            return ['outcome' => 'inconclusive', 'exit_code' => $result->exitCode, 'output' => $result->output];
        }

        return ['outcome' => 'valid', 'exit_code' => 0, 'output' => $result->output];
    }

    /**
     * @return array{CommandResult, ?CommandResult}
     */
    private function decodeAndVerify(string $cms, string $signedContent): array
    {
        $workDirectory = self::temporaryDirectory();
        $cmsPath = $workDirectory.'/signature.der';
        $contentPath = $workDirectory.'/signed-content.bin';

        try {
            self::writeFile($cmsPath, $cms);
            self::writeFile($contentPath, $signedContent);

            $decoded = $this->runner->run(['openssl', ...self::DECODE_ARGUMENTS, '-in', $cmsPath], $this->timeoutSeconds);
            if ($decoded->exitCode !== 0) {
                return [$decoded, null];
            }

            return [$decoded, $this->runner->run(['openssl', ...self::ARGUMENTS, '-in', $cmsPath, '-content', $contentPath], $this->timeoutSeconds)];
        } finally {
            @unlink($cmsPath);
            @unlink($contentPath);
            @rmdir($workDirectory);
        }
    }

    private static function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) !== strlen($content)) {
            throw new RuntimeException("Unable to write {$path} for openssl");
        }
    }

    private static function temporaryDirectory(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'openssl-cms-');
        if ($path === false || ! unlink($path) || ! mkdir($path, 0700)) {
            throw new RuntimeException('Unable to create a temporary directory for openssl');
        }

        return $path;
    }
}
