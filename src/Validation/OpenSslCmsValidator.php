<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;

final class OpenSslCmsValidator extends CommandLineValidator
{
    private const int VERIFICATION_FAILURE_EXIT_CODE = 4;

    private const array ARGUMENTS = ['cms', '-verify', '-binary', '-inform', 'DER', '-noverify', '-out', '/dev/null'];

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
            'description' => 'Each /ByteRange array in the file delimits the signed bytes and the hexadecimal CMS in its gap; the CMS is verified over the signed bytes without certificate chain verification',
            'parameters' => ['arguments' => self::ARGUMENTS],
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
        $document = (string) file_get_contents($outputPath);
        preg_match_all('#/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]#', $document, $matches, PREG_SET_ORDER);
        if ($matches === []) {
            return new Judgement('invalid', 'No /ByteRange array was found', null, '');
        }

        $signatures = [];
        $rawOutput = '';
        foreach ($matches as $number => $match) {
            $byteRange = array_map(intval(...), array_slice($match, 1));
            $signature = ['byte_range' => $byteRange] + $this->verify($document, $byteRange);
            $signatures[] = $signature;
            $rawOutput .= 'Signature #'.($number + 1).' ['.implode(' ', $byteRange)."]:\n".$signature['output'];
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
        if (preg_match('/^<([0-9A-Fa-f]*)>$/', $gap, $hexMatch) !== 1 || strlen($hexMatch[1]) % 2 !== 0) {
            return ['outcome' => 'invalid', 'problem' => 'the byte range gap is not a hexadecimal string', 'exit_code' => null, 'output' => ''];
        }

        $cms = self::withoutPadding((string) hex2bin($hexMatch[1]));
        if ($cms === null) {
            return ['outcome' => 'invalid', 'problem' => 'the byte range gap does not hold a DER encoded CMS', 'exit_code' => null, 'output' => ''];
        }

        $workDirectory = self::temporaryDirectory();
        $cmsPath = $workDirectory.'/signature.der';
        $contentPath = $workDirectory.'/signed-content.bin';

        try {
            file_put_contents($cmsPath, $cms);
            file_put_contents($contentPath, substr($document, 0, $firstLength).substr($document, $secondOffset, $secondLength));
            $result = $this->runner->run(['openssl', ...self::ARGUMENTS, '-in', $cmsPath, '-content', $contentPath], $this->timeoutSeconds);
        } finally {
            @unlink($cmsPath);
            @unlink($contentPath);
            @rmdir($workDirectory);
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

    private static function withoutPadding(string $der): ?string
    {
        if (strlen($der) < 2 || $der[0] !== "\x30") {
            return null;
        }

        $lengthByte = ord($der[1]);
        if ($lengthByte < 0x80) {
            $totalLength = 2 + $lengthByte;
        } else {
            $lengthOctets = $lengthByte & 0x7F;
            if ($lengthOctets === 0 || $lengthOctets > 4 || strlen($der) < 2 + $lengthOctets) {
                return null;
            }
            $totalLength = 2 + $lengthOctets + (int) hexdec(bin2hex(substr($der, 2, $lengthOctets)));
        }

        if ($totalLength > strlen($der) || trim(substr($der, $totalLength), "\0") !== '') {
            return null;
        }

        return substr($der, 0, $totalLength);
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
