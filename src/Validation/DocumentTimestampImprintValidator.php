<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

/**
 * Whether each document timestamp covers its own /ByteRange: the message imprint of the
 * RFC 3161 token in its /Contents must be the digest of the bytes the range covers
 * (ISO 32000-2, 12.8.5). The imprint is found as the DER OCTET STRING of that digest inside
 * the token, for SHA-256, SHA-384 and SHA-512.
 */
final class DocumentTimestampImprintValidator extends ToolValidator
{
    private const array DIGESTS = ['sha256' => "\x04\x20", 'sha384' => "\x04\x30", 'sha512' => "\x04\x40"];

    public function analyzerId(): string
    {
        return 'document-timestamp-imprint';
    }

    protected function property(): string
    {
        return 'timestamp';
    }

    protected function policy(): array
    {
        return [
            'id' => 'document-timestamp-imprint',
            'description' => 'Every dictionary with /SubFilter /ETSI.RFC3161 that qpdf lists in the output holds in /Contents a token containing the DER OCTET STRING of the SHA-256, SHA-384 or SHA-512 digest of the bytes its /ByteRange covers, which is where an RFC 3161 message imprint carries it; an output without a document timestamp is invalid, because the analyzer runs only for scenarios that add one',
            'parameters' => [
                'listing' => ['qpdf', ...QpdfObjects::ARGUMENTS],
                'digest_algorithms' => array_keys(self::DIGESTS),
            ],
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

    protected function judgeOutput(ValidationSubject $subject, string $outputPath): Judgement
    {
        $result = $this->runner->run(QpdfObjects::command($outputPath), $this->timeoutSeconds);
        if ($result->timedOut) {
            return $this->timedOut($result);
        }
        $document = QpdfObjects::fromResult($result);
        if ($document === null) {
            return new Judgement('inconclusive', 'qpdf '.$result->describe().' without a listing the analyzer can read', $result->exitCode, $result->output);
        }

        $timestamps = [];
        foreach ($document->signatureDictionaries() as $reference => $dictionary) {
            if (($dictionary['/SubFilter'] ?? null) !== '/ETSI.RFC3161') {
                continue;
            }
            $timestamps[] = ['object' => $reference, 'imprint' => $this->imprint($outputPath, $document->byteRange($dictionary), $document->bytes($dictionary['/Contents'] ?? null))];
        }
        $details = ['document_timestamps' => $timestamps];

        if ($timestamps === []) {
            return new Judgement($document->isComplete() ? 'invalid' : 'inconclusive', 'The output has no document timestamp', $result->exitCode, '', $details);
        }
        $states = array_column($timestamps, 'imprint');
        if (in_array('mismatch', $states, true)) {
            return new Judgement('invalid', 'A document timestamp does not carry the digest of the bytes its /ByteRange covers', $result->exitCode, '', $details);
        }
        if (in_array('unreadable', $states, true)) {
            return new Judgement('inconclusive', 'The /ByteRange or /Contents of a document timestamp cannot be read', $result->exitCode, '', $details);
        }

        return new Judgement('valid', count($timestamps).' document timestamp(s) carry the digest of the bytes they cover', $result->exitCode, '', $details);
    }

    /**
     * @param  list<int>|null  $byteRange
     * @return 'match'|'mismatch'|'unreadable'
     */
    private function imprint(string $outputPath, ?array $byteRange, ?string $token): string
    {
        if ($byteRange === null || $token === null || $byteRange[2] < $byteRange[0] + $byteRange[1]) {
            return 'unreadable';
        }
        $size = filesize($outputPath);
        if ($size === false || $size < $byteRange[2] + $byteRange[3]) {
            return 'unreadable';
        }

        $contexts = array_map(static fn (string $algorithm): \HashContext => hash_init($algorithm), array_combine(array_keys(self::DIGESTS), array_keys(self::DIGESTS)));
        $handle = fopen($outputPath, 'rb');
        if ($handle === false) {
            return 'unreadable';
        }
        try {
            foreach ([[$byteRange[0], $byteRange[1]], [$byteRange[2], $byteRange[3]]] as [$offset, $length]) {
                if (fseek($handle, $offset) !== 0) {
                    return 'unreadable';
                }
                while ($length > 0) {
                    $chunk = fread($handle, min($length, 1 << 20));
                    if ($chunk === false || $chunk === '') {
                        return 'unreadable';
                    }
                    foreach ($contexts as $context) {
                        hash_update($context, $chunk);
                    }
                    $length -= strlen($chunk);
                }
            }
        } finally {
            fclose($handle);
        }

        foreach ($contexts as $algorithm => $context) {
            if (str_contains($token, self::DIGESTS[$algorithm].hash_final($context, true))) {
                return 'match';
            }
        }

        return 'mismatch';
    }
}
