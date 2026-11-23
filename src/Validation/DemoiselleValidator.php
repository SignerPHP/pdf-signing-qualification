<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use Closure;
use DateTimeImmutable;

/**
 * Demoiselle Signer (SERPRO) checking each signature against the ICP-Brasil signature policies.
 * The test PKI does not chain to the ICP-Brasil root, so Demoiselle always reports the signer's
 * chain as untrusted; that one message is tolerated and named in the policy. Everything else it
 * reports counts: the policy identifier, the mandatory signed attributes and the signature itself.
 */
final class DemoiselleValidator extends ToolValidator
{
    public const string JAR = '/opt/validators/icp-validator.jar';

    public const array JAVA_OPTIONS = ['-Xmx1g', '-XX:+UseSerialGC', '-XX:-UsePerfData'];

    /** The ICP-Brasil PAdES AD-RT arc; the version is the last arc. */
    public const string AD_RT_ARC = '2.16.76.1.7.1.12.1.';

    /** Demoiselle's message for a chain that does not reach a trusted ICP-Brasil root. */
    public const string UNTRUSTED_CHAIN_PATTERN = '/não pertence a nenhuma cadeia confiável/u';

    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        CommandRunner $runner,
        Closure $clock,
        private readonly int $expectedSignatures,
        float $timeoutSeconds = 300.0,
        private readonly string $javaBinary = '/opt/java/bin/java',
    ) {
        parent::__construct($runner, $clock, $timeoutSeconds);
    }

    public function analyzerId(): string
    {
        return 'demoiselle-signer';
    }

    protected function property(): string
    {
        return 'profile';
    }

    protected function policy(): array
    {
        return [
            'id' => 'demoiselle-icp-brasil-ad-rt-test-pki',
            'description' => 'Demoiselle Signer\'s PAdES check of the signature of every signature field other than a document timestamp, located with PDFBox. Only the signatures the implementation added are judged: one whose field holds a signature in the input, as qpdf lists it, is prior and only recorded. Valid when there is one added signature per signer and each is checked without an exception, is not reported invalid, commits to a policy identifier of the ICP-Brasil PAdES AD-RT arc and has no error other than the untrusted chain that a certificate of the test PKI always gives; anything else is invalid; a signature Demoiselle could not check, with nothing invalid, and a report the analyzer cannot read are inconclusive. Warnings are recorded and do not change the verdict',
            'parameters' => [
                'policy_arc' => self::AD_RT_ARC,
                'tolerated_errors' => [self::UNTRUSTED_CHAIN_PATTERN],
                'expected_signatures' => $this->expectedSignatures,
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
        return '/^demoiselle-signer (\S+)$/m';
    }

    protected function judgeOutput(ValidationSubject $subject, string $outputPath): Judgement
    {
        $result = $this->runner->run([$this->javaBinary, ...self::JAVA_OPTIONS, '-jar', self::JAR, $outputPath], $this->timeoutSeconds);
        if ($result->timedOut) {
            return $this->timedOut($result);
        }
        $report = AddedSignatures::report($result);
        if ($report === null) {
            return new Judgement('inconclusive', 'Demoiselle '.$result->describe().' without a report the analyzer can read', $result->exitCode, $result->output);
        }
        $inputFields = AddedSignatures::inputFields($this->runner, $subject->inputPath, $this->timeoutSeconds);

        $signatures = [];
        $prior = [];
        foreach (array_filter($report['signatures'], is_array(...)) as $signature) {
            if ($inputFields !== null && is_string($signature['field'] ?? null) && AddedSignatures::isPrior([$signature['field']], $inputFields)) {
                $prior[] = $signature;
            } else {
                $signatures[] = $signature;
            }
        }
        if ($inputFields === null && count($signatures) !== $this->expectedSignatures) {
            return new Judgement('inconclusive', 'qpdf cannot list the signatures of the input, so the '.count($signatures).' signature(s) Demoiselle reports cannot be told apart from those of the input', $result->exitCode, $result->output, ['signatures' => $signatures]);
        }
        $problems = [];
        $unchecked = [];
        if (count($signatures) !== $this->expectedSignatures) {
            $problems[] = 'Demoiselle found '.count($signatures).' signature(s) not in the input, the scenario applies '.$this->expectedSignatures;
        }
        foreach ($signatures as $position => $signature) {
            $name = 'signature '.($position + 1);
            if (is_string($signature['error'] ?? null)) {
                $unchecked[] = "{$name} could not be checked: {$signature['error']}";

                continue;
            }
            $checks = is_array($signature['checks'] ?? null) ? array_values(array_filter($signature['checks'], is_array(...))) : [];
            if ($checks === []) {
                $problems[] = "{$name} has no check";
            }
            foreach ($checks as $check) {
                array_push($problems, ...self::checkProblems($name, $check));
            }
        }

        $details = ['signatures' => $signatures, 'prior_signatures' => $prior, 'problems' => [...$problems, ...$unchecked]];
        if ($problems !== []) {
            return new Judgement('invalid', 'Demoiselle rejected the output under ICP-Brasil AD-RT: '.implode('; ', [...$problems, ...$unchecked]), $result->exitCode, $result->output, $details);
        }
        if ($unchecked !== []) {
            return new Judgement('inconclusive', 'Demoiselle did not check every signature: '.implode('; ', $unchecked), $result->exitCode, $result->output, $details);
        }

        return new Judgement('valid', 'Demoiselle found '.count($signatures).' signature(s) committing to ICP-Brasil AD-RT, with no error other than the test PKI\'s untrusted chain', $result->exitCode, $result->output, $details);
    }

    /**
     * @return list<string>
     */
    private static function checkProblems(string $name, array $check): array
    {
        $problems = [];
        if (($check['invalid_signature'] ?? null) !== false) {
            $problems[] = "{$name} is reported invalid";
        }
        $policy = $check['policy'] ?? null;
        if (! is_string($policy) || ! str_starts_with($policy, self::AD_RT_ARC)) {
            $problems[] = "{$name} commits to the policy ".json_encode($policy).', not to ICP-Brasil AD-RT';
        }
        foreach (is_array($check['errors'] ?? null) ? $check['errors'] : [] as $error) {
            if (! is_string($error) || preg_match(self::UNTRUSTED_CHAIN_PATTERN, $error) !== 1) {
                $problems[] = "{$name}: ".(is_string($error) ? $error : json_encode($error));
            }
        }

        return $problems;
    }
}
