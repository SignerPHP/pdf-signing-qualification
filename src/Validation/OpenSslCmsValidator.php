<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;

final class OpenSslCmsValidator extends CommandLineValidator
{
    private const int VERIFICATION_FAILURE_EXIT_CODE = 4;

    private const array UNDECODABLE_EXIT_CODES = [2, 3];

    private const array DECODE_ARGUMENTS = ['cms', '-cmsout', '-noout', '-inform', 'DER'];

    private const array ARGUMENTS = ['cms', '-verify', '-binary', '-inform', 'DER', '-noverify', '-out', '/dev/null'];

    private const array ENCAPSULATED_ARGUMENTS = ['cms', '-verify', '-binary', '-inform', 'DER', '-noverify', '-out'];

    private const string DOCUMENT_TIMESTAMP_SUB_FILTER = '/ETSI.RFC3161';

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
            'description' => 'Every dictionary with a /ByteRange, as listed by qpdf, delimits the signed bytes and the hexadecimal CMS in its gap; the CMS must decode and is verified over the signed bytes without certificate chain verification, except that the RFC 3161 token of a document timestamp (/SubFilter /ETSI.RFC3161) signs its encapsulated TSTInfo, so it is verified as encapsulated CMS and the TSTInfo it yields must carry, as its message imprint, the SHA-256, SHA-384 or SHA-512 digest of the signed bytes',
            'parameters' => [
                'locator_arguments' => ['qpdf', ...SignatureListing::ARGUMENTS],
                'decode_arguments' => self::DECODE_ARGUMENTS,
                'arguments' => self::ARGUMENTS,
                'encapsulated_sub_filters' => [self::DOCUMENT_TIMESTAMP_SUB_FILTER],
                'encapsulated_arguments' => self::ENCAPSULATED_ARGUMENTS,
                'message_imprint_digests' => array_keys(DocumentTimestampImprintValidator::DIGESTS),
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

    protected function judge(string $documentPath): Judgement
    {
        $document = file_get_contents($documentPath);
        if ($document === false) {
            throw new RuntimeException("Unable to read the document {$documentPath}");
        }

        $listing = SignatureListing::read($this->runner, $documentPath, $this->timeoutSeconds);
        if ($listing->result->timedOut) {
            return $this->timedOut($listing->result);
        }
        if ($listing->signatures === null) {
            return new Judgement('inconclusive', 'qpdf could not list the signature dictionaries: qpdf '.$listing->result->describe(), $listing->result->exitCode, $listing->result->output);
        }

        if ($listing->signatures === []) {
            $documentWasRead = $listing->isComplete();

            return Judgement::noSignature($documentWasRead ? 'No dictionary with a /ByteRange was found' : 'No dictionary with a /ByteRange was found in a file qpdf had to recover', $listing->result->exitCode, '', documentWasRead: $documentWasRead);
        }

        $signatures = [];
        $rawOutput = '';
        foreach ($listing->signatures as $number => $listed) {
            $byteRange = $listed['byte_range'];
            $signature = ['byte_range' => $byteRange] + ($byteRange === null
                ? ['outcome' => 'invalid', 'problem' => 'the /ByteRange is not an array of four integers', 'exit_code' => null, 'output' => '']
                : $this->verify($document, $byteRange, $listed['sub_filter'] === self::DOCUMENT_TIMESTAMP_SUB_FILTER));
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

    /**
     * @param  list<int>  $byteRange
     * @return array{outcome: string, problem?: string, exit_code: ?int, output: string}
     */
    private function verify(string $document, array $byteRange, bool $encapsulated): array
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

        $signedBytes = substr($document, 0, $firstLength).substr($document, $secondOffset, $secondLength);
        [$decoded, $result, $encapsulatedContent] = $this->decodeAndVerify((string) hex2bin($hex), $encapsulated ? null : $signedBytes);

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
        if ($encapsulated && ! self::carriesImprint((string) $encapsulatedContent, $signedBytes)) {
            return ['outcome' => 'invalid', 'problem' => 'the message imprint of the document timestamp is not the digest of the bytes its range covers', 'exit_code' => 0, 'output' => $result->output];
        }

        return ['outcome' => 'valid', 'exit_code' => 0, 'output' => $result->output];
    }

    private static function carriesImprint(string $timestampInformation, string $signedBytes): bool
    {
        foreach (DocumentTimestampImprintValidator::DIGESTS as $algorithm => $prefix) {
            if (str_contains($timestampInformation, $prefix.hash($algorithm, $signedBytes, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{CommandResult, ?CommandResult, ?string}
     */
    private function decodeAndVerify(string $cms, ?string $signedContent): array
    {
        $workDirectory = self::temporaryDirectory();
        $cmsPath = $workDirectory.'/signature.der';
        $contentPath = $workDirectory.'/signed-content.bin';
        $encapsulatedPath = $workDirectory.'/encapsulated-content.der';

        try {
            self::writeFile($cmsPath, $cms);
            if ($signedContent !== null) {
                self::writeFile($contentPath, $signedContent);
            }

            $decoded = $this->runner->run(['openssl', ...self::DECODE_ARGUMENTS, '-in', $cmsPath], $this->timeoutSeconds, new CommandAccess([$cmsPath]));
            if ($decoded->exitCode !== 0) {
                return [$decoded, null, null];
            }

            if ($signedContent !== null) {
                return [$decoded, $this->runner->run(['openssl', ...self::ARGUMENTS, '-in', $cmsPath, '-content', $contentPath], $this->timeoutSeconds, new CommandAccess([$cmsPath, $contentPath])), null];
            }
            $verified = $this->runner->run(['openssl', ...self::ENCAPSULATED_ARGUMENTS, $encapsulatedPath, '-in', $cmsPath], $this->timeoutSeconds, new CommandAccess([$cmsPath], [$workDirectory]));
            $encapsulatedContent = is_file($encapsulatedPath) ? file_get_contents($encapsulatedPath) : false;

            return [$decoded, $verified, $encapsulatedContent === false ? null : $encapsulatedContent];
        } finally {
            @unlink($cmsPath);
            @unlink($contentPath);
            @unlink($encapsulatedPath);
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
