<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use Closure;
use DateTimeImmutable;

final class EuDssValidator extends ToolValidator
{
    public const string JAR = '/opt/validators/dss-validator.jar';

    public const array JAVA_OPTIONS = ['-Xmx1g', '-XX:+UseSerialGC', '-XX:-UsePerfData'];

    /**
     * @param  Closure(): DateTimeImmutable  $clock
     * @param  array{sha256: string, byte_size: int}  $trustedRoot
     */
    public function __construct(
        CommandRunner $runner,
        Closure $clock,
        private readonly array $signing,
        private readonly string $trustedRootPath,
        private readonly array $trustedRoot,
        float $timeoutSeconds = 300.0,
        private readonly string $javaBinary = '/opt/java/bin/java',
    ) {
        parent::__construct($runner, $clock, $timeoutSeconds);
    }

    public function analyzerId(): string
    {
        return 'eu-dss';
    }

    protected function property(): string
    {
        return 'profile';
    }

    public static function expectedFormat(array $signing): string
    {
        $level = match (true) {
            ($signing['document_timestamp'] ?? false) === true => 'LTA',
            ($signing['revocation_info'] ?? false) === true => 'LT',
            ($signing['timestamp'] ?? false) === true => 'T',
            default => 'B',
        };

        return ($signing['sub_filter'] === 'ETSI.CAdES.detached' ? 'PAdES-BASELINE-' : 'PKCS7-').$level;
    }

    protected function policy(): array
    {
        return [
            'id' => 'eu-dss-default-policy-test-pki',
            'description' => 'EU DSS validation under its default policy, trusting only the root certificate of the run\'s test PKI, which is generated for each run and recorded in the details of each observation, and fetching missing certificates, CRLs and OCSP responses from the test PKI through the URLs the certificates carry. Only the signatures the implementation added are judged: a signature whose /ByteRange, as DSS reports it, is the /ByteRange of a signature of the input, as qpdf lists it, is prior and only recorded; field names are recorded, not compared. A report without the /ByteRange of every signature, and an input signature whose /ByteRange qpdf cannot read when the count does not match, are inconclusive. Valid when the output holds one added signature per signer, every one TOTAL_PASSED and detected at the expected format; a missing or extra signature, TOTAL_FAILED or another format is invalid; INDETERMINATE, with nothing invalid, is inconclusive',
            'parameters' => [
                'expected_format' => self::expectedFormat($this->signing),
                'expected_signatures' => $this->expectedSignatures(),
                'java_options' => self::JAVA_OPTIONS,
                'input_signature_listing' => ['qpdf', ...SignatureListing::ARGUMENTS],
            ],
        ];
    }

    protected function versionCommand(): array
    {
        return [$this->javaBinary, ...self::JAVA_OPTIONS, '-jar', self::JAR, '--version'];
    }

    protected function versionPattern(): string
    {
        return '/^dss-validator (\S+)$/m';
    }

    protected function judgeOutput(ValidationSubject $subject, string $outputPath): Judgement
    {
        $result = $this->runner->run([$this->javaBinary, ...self::JAVA_OPTIONS, '-jar', self::JAR, $this->trustedRootPath, $outputPath], $this->timeoutSeconds);
        if ($result->timedOut) {
            $timedOut = $this->timedOut($result);

            return new Judgement($timedOut->verdict, $timedOut->summary, $timedOut->exitCode, $timedOut->rawOutput, ['trusted_root' => $this->trustedRoot]);
        }
        $report = AddedSignatures::report($result);
        if ($report === null) {
            return new Judgement('inconclusive', 'DSS '.$result->describe().' without a report the analyzer can read', $result->exitCode, $result->output, ['trusted_root' => $this->trustedRoot]);
        }
        $inputByteRanges = AddedSignatures::inputByteRanges($this->runner, $subject->inputPath, $this->timeoutSeconds);

        $added = [];
        $prior = [];
        foreach (array_filter($report['signatures'], is_array(...)) as $signature) {
            $fields = is_array($signature['fields'] ?? null) ? array_values(array_filter($signature['fields'], is_string(...))) : [];
            $summary = [
                'id' => $signature['id'] ?? null,
                'fields' => $fields,
                'byte_range' => $signature['byte_range'] ?? null,
                'indication' => $signature['indication'] ?? null,
                'sub_indication' => $signature['sub_indication'] ?? null,
                'format' => $signature['format'] ?? null,
                'signed_by' => $signature['signed_by'] ?? null,
            ];
            if ($inputByteRanges !== null && AddedSignatures::isPrior($signature['byte_range'] ?? null, $inputByteRanges)) {
                $prior[] = $summary;
            } else {
                $added[] = $summary;
            }
        }
        $signatures = $added;
        $details = [
            'trusted_root' => $this->trustedRoot,
            'signatures' => $added,
            'prior_signatures' => $prior,
            'timestamps' => is_array($report['timestamps'] ?? null) ? $report['timestamps'] : [],
        ];
        if ($inputByteRanges === null && count($added) !== $this->expectedSignatures()) {
            return new Judgement('inconclusive', 'qpdf cannot list the signatures of the input, or the /ByteRange of one of them, so the '.count($added).' signature(s) DSS reports cannot be told apart from those of the input', $result->exitCode, $result->output, $details);
        }
        $expectedFormat = self::expectedFormat($this->signing);

        $problems = [];
        if (count($signatures) !== $this->expectedSignatures()) {
            $problems[] = 'DSS found '.count($signatures).' signature(s) not in the input, the scenario applies '.$this->expectedSignatures();
        }
        $undecided = [];
        foreach ($signatures as $position => $signature) {
            $number = $position + 1;
            $indication = $signature['indication'] ?? null;
            $subIndication = is_string($signature['sub_indication'] ?? null) ? '/'.$signature['sub_indication'] : '';
            if ($indication === 'TOTAL_FAILED') {
                $problems[] = "signature {$number} is TOTAL_FAILED{$subIndication}";
            } elseif ($indication !== 'TOTAL_PASSED') {
                $undecided[] = "signature {$number} is ".(is_string($indication) ? $indication : 'without indication').$subIndication;
            }
            if (($signature['format'] ?? null) !== $expectedFormat) {
                $problems[] = "signature {$number} is ".json_encode($signature['format'] ?? null).", not {$expectedFormat}";
            }
        }

        if ($problems !== []) {
            return new Judgement('invalid', 'DSS rejected the output: '.implode('; ', [...$problems, ...$undecided]), $result->exitCode, $result->output, $details);
        }
        if ($undecided !== []) {
            return new Judgement('inconclusive', 'DSS did not decide every signature: '.implode('; ', $undecided), $result->exitCode, $result->output, $details);
        }

        return new Judgement('valid', 'DSS validated '.count($signatures)." signature(s) as TOTAL_PASSED {$expectedFormat}", $result->exitCode, $result->output, $details);
    }

    private function expectedSignatures(): int
    {
        return is_array($this->signing['signers'] ?? null) ? count($this->signing['signers']) : 1;
    }
}
