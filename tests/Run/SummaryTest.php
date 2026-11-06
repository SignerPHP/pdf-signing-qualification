<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Run\Summary;

final class SummaryTest extends TestCase
{
    private static function runRecord(): array
    {
        return [
            'id' => 'run-20261031T120000Z',
            'scenario_id' => 'basic-invisible-sha256',
            'implementation' => ['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => str_repeat('f', 40)]],
            'analyzers' => [
                ['id' => 'pyhanko', 'revision' => ['kind' => 'version', 'value' => '0.29.1']],
                ['id' => 'qpdf', 'revision' => ['kind' => 'version', 'value' => '11.3.0']],
            ],
        ];
    }

    private static function attempt(string $sourceId, string $outcome, ?string $error = null, ?string $capability = null, ?int $exitCode = 1): array
    {
        return [
            'id' => "run-20261031T120000Z/basic-invisible-sha256/pdf-signer/{$sourceId}",
            'input' => ['source_id' => $sourceId],
            'capability' => $capability ?? ($outcome === 'not_run' ? 'not_evaluated' : 'supported'),
            'execution' => ['outcome' => $outcome, 'exit_code' => $exitCode, 'error' => $error === null ? null : ['message' => $error]],
        ];
    }

    private static function observation(string $sourceId, string $analyzerId, string $verdict, string $summary): array
    {
        return [
            'attempt_id' => "run-20261031T120000Z/basic-invisible-sha256/pdf-signer/{$sourceId}",
            'analyzer' => ['id' => $analyzerId],
            'verdict' => $verdict,
            'evidence' => ['summary' => $summary],
        ];
    }

    public function test_summary_counts_every_outcome_and_verdict_and_lists_the_failures(): void
    {
        $attempts = [
            self::attempt('qpdf:good.pdf', 'succeeded'),
            self::attempt('qpdf:hybrid.pdf', 'succeeded'),
            self::attempt('qpdf:bad1.pdf', 'failed', "pdf-signer exited with code 1: Signing failed: not a PDF\n#0 trace line"),
            self::attempt('qpdf:gone.pdf', 'not_run'),
        ];
        $observations = [
            self::observation('qpdf:good.pdf', 'pyhanko', 'valid', 'pyHanko judged 1 signature(s) intact and cryptographically valid'),
            self::observation('qpdf:good.pdf', 'qpdf', 'valid', 'qpdf found no problem'),
            self::observation('qpdf:hybrid.pdf', 'pyhanko', 'invalid', 'pyHanko judged the signature(s) Signature1 not intact or not cryptographically valid'),
            self::observation('qpdf:hybrid.pdf', 'qpdf', 'inconclusive', 'qpdf reported warnings only'),
            self::observation('qpdf:bad1.pdf', 'pyhanko', 'not_evaluated', 'The attempt produced no output to validate'),
            self::observation('qpdf:bad1.pdf', 'qpdf', 'not_evaluated', 'The attempt produced no output to validate'),
            self::observation('qpdf:gone.pdf', 'pyhanko', 'not_evaluated', 'The input was unavailable, so there is no output to validate'),
            self::observation('qpdf:gone.pdf', 'qpdf', 'not_evaluated', 'The input was unavailable, so there is no output to validate'),
        ];

        $expected = <<<'MARKDOWN'
            # Run run-20261031T120000Z

            Scenario `basic-invisible-sha256`, implementation `pdf-signer` at commit `ffffffffffffffffffffffffffffffffffffffff`, analyzers `pyhanko` 0.29.1 and `qpdf` 11.3.0.

            ## Executions

            | Outcome | Attempts |
            | ------- | -------: |
            | `succeeded` | 2 |
            | `failed` | 1 |
            | `timed_out` | 0 |
            | `not_run` | 1 |
            | Rejected as expected | 0 |
            | Total | 4 |

            ## Verdicts

            | Analyzer | `valid` | `invalid` | `inconclusive` | `not_evaluated` | `not_applicable` |
            | -------- | ------: | --------: | -------------: | --------------: | ---------------: |
            | `pyhanko` | 1 | 1 | 0 | 2 | 0 |
            | `qpdf` | 1 | 0 | 1 | 2 | 0 |

            ## Failures

            ### Executions that did not succeed

            | Input | Capability | Outcome | Error |
            | ----- | ---------- | ------- | ----- |
            | `qpdf:bad1.pdf` | `supported` | `failed` | pdf-signer exited with code 1: Signing failed: not a PDF |
            | `qpdf:gone.pdf` | `not_evaluated` | `not_run` |  |

            ### Outputs not judged valid

            | Input | Analyzer | Verdict | Summary |
            | ----- | -------- | ------- | ------- |
            | `qpdf:hybrid.pdf` | `pyhanko` | `invalid` | pyHanko judged the signature(s) Signature1 not intact or not cryptographically valid |
            | `qpdf:hybrid.pdf` | `qpdf` | `inconclusive` | qpdf reported warnings only |

            MARKDOWN;

        self::assertSame($expected, Summary::markdown(self::runRecord(), $attempts, $observations));
    }

    public function test_expected_rejections_are_totaled_and_listed_apart_from_failures(): void
    {
        $attempts = [
            self::attempt('qpdf:good.pdf', 'succeeded'),
            self::attempt('qpdf:bad1.pdf', 'failed', 'pdf-signer exited with code 1: PDF version not found', 'expected_rejection'),
            self::attempt('qpdf:bad35.pdf', 'failed', 'pdf-signer exited with code 1: Object 1 is not an object stream'),
        ];
        $observations = [self::observation('qpdf:good.pdf', 'qpdf', 'valid', 'ok')];

        $markdown = Summary::markdown(self::runRecord(), $attempts, $observations);

        self::assertStringContainsString("| `succeeded` | 1 |\n| `failed` | 1 |\n| `timed_out` | 0 |\n| `not_run` | 0 |\n| Rejected as expected | 1 |\n| Total | 3 |\n", $markdown);
        self::assertStringContainsString("## Expected rejections\n\n| Input | Error |\n| ----- | ----- |\n| `qpdf:bad1.pdf` | pdf-signer exited with code 1: PDF version not found |\n", $markdown);
        $failures = substr($markdown, (int) strpos($markdown, '## Failures'));
        self::assertStringContainsString('`qpdf:bad35.pdf`', $failures);
        self::assertStringNotContainsString('`qpdf:bad1.pdf`', $failures);
    }

    public function test_run_whose_only_problem_is_an_expected_rejection_does_not_claim_every_attempt_succeeded(): void
    {
        $attempts = [
            self::attempt('qpdf:good.pdf', 'succeeded'),
            self::attempt('qpdf:bad1.pdf', 'failed', 'pdf-signer exited with code 1: PDF version not found', 'expected_rejection'),
        ];
        $observations = [self::observation('qpdf:good.pdf', 'qpdf', 'valid', 'ok')];

        $markdown = Summary::markdown(self::runRecord(), $attempts, $observations);

        self::assertStringContainsString("## Failures\n\nNo other attempt failed and no output was judged invalid or inconclusive.\n", $markdown);
    }

    public function test_expected_rejection_that_succeeded_is_reported_as_observed(): void
    {
        $attempts = [self::attempt('qpdf:bad1.pdf', 'succeeded', capability: 'expected_rejection')];
        $observations = [self::observation('qpdf:bad1.pdf', 'qpdf', 'valid', 'ok')];

        $markdown = Summary::markdown(self::runRecord(), $attempts, $observations);

        self::assertStringContainsString("| `succeeded` | 1 |\n", $markdown);
        self::assertStringContainsString("| Rejected as expected | 0 |\n", $markdown);
        self::assertStringContainsString("### Expected rejections that succeeded\n\n| Input |\n| ----- |\n| `qpdf:bad1.pdf` |\n", $markdown);
        self::assertStringNotContainsString('## Expected rejections'."\n", $markdown);
    }

    public function test_expected_rejection_that_timed_out_stays_a_failure(): void
    {
        $attempts = [self::attempt('qpdf:bad1.pdf', 'timed_out', 'pdf-signer did not finish within 120 seconds', 'expected_rejection')];
        $observations = [self::observation('qpdf:bad1.pdf', 'qpdf', 'not_evaluated', 'The attempt produced no output to validate')];

        $markdown = Summary::markdown(self::runRecord(), $attempts, $observations);

        self::assertStringContainsString("| `timed_out` | 1 |\n", $markdown);
        self::assertStringContainsString("| Rejected as expected | 0 |\n", $markdown);
        self::assertStringContainsString('| `qpdf:bad1.pdf` | `expected_rejection` | `timed_out` | pdf-signer did not finish within 120 seconds |', $markdown);
        self::assertStringNotContainsString('## Expected rejections'."\n", $markdown);
    }

    /**
     * @return iterable<string, array{?int, string}>
     */
    public static function failuresThatAreNotRejections(): iterable
    {
        yield 'terminated by a signal' => [null, 'pdf-signer was terminated by signal 11'];
        yield 'ended without an exit code' => [null, 'pdf-signer ended without an exit code'];
        yield 'exit 0 without an output' => [0, 'pdf-signer exited with code 0 without writing an output'];
        yield 'output that is not a regular file' => [0, 'pdf-signer did not write its output as a regular file'];
    }

    #[DataProvider('failuresThatAreNotRejections')]
    public function test_declared_attempt_that_did_not_reject_the_input_stays_a_failure(?int $exitCode, string $error): void
    {
        $attempts = [self::attempt('qpdf:bad1.pdf', 'failed', $error, 'expected_rejection', $exitCode)];
        $observations = [self::observation('qpdf:bad1.pdf', 'qpdf', 'not_evaluated', 'The attempt produced no output to validate')];

        $markdown = Summary::markdown(self::runRecord(), $attempts, $observations);

        self::assertStringContainsString("| `failed` | 1 |\n", $markdown);
        self::assertStringContainsString("| Rejected as expected | 0 |\n", $markdown);
        self::assertStringContainsString("| `qpdf:bad1.pdf` | `expected_rejection` | `failed` | {$error} |", $markdown);
        self::assertStringNotContainsString('## Expected rejections'."\n", $markdown);
    }

    public function test_table_cells_keep_their_row_when_the_text_has_pipes_or_is_long(): void
    {
        $attempts = [self::attempt('qpdf:bad1.pdf', 'failed', 'a | b '.str_repeat('x', 300))];
        $observations = [self::observation('qpdf:bad1.pdf', 'qpdf', 'not_evaluated', 'The attempt produced no output to validate')];

        $markdown = Summary::markdown(self::runRecord(), $attempts, $observations);

        self::assertStringContainsString('| a \| b '.str_repeat('x', 194).'… |', $markdown);
    }

    public function test_summary_without_failures_says_so(): void
    {
        $attempts = [self::attempt('qpdf:good.pdf', 'succeeded')];
        $observations = [self::observation('qpdf:good.pdf', 'pyhanko', 'valid', 'ok')];

        $markdown = Summary::markdown(self::runRecord(), $attempts, $observations);

        self::assertStringContainsString("## Failures\n\nEvery attempt succeeded and every output was judged valid.\n", $markdown);
    }
}
