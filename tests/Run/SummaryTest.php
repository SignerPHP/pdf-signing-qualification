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
            'input' => $outcome === 'not_run' ? ['status' => 'unavailable', 'source_id' => $sourceId, 'reason' => 'missing_upstream'] : ['status' => 'available', 'source_id' => $sourceId],
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

    private static function baseline(string $sourceId, string $analyzerId, string $verdict): array
    {
        return ['source_id' => $sourceId, 'analyzer' => ['id' => $analyzerId], 'verdict' => $verdict];
    }

    /**
     * @param  list<array>  $attempts
     * @param  list<array>  $observations
     */
    private static function markdown(array $attempts, array $observations): string
    {
        $run = self::runRecord();
        $judged = array_map(static fn (array $observation): string => $observation['attempt_id'].' '.$observation['analyzer']['id'], $observations);
        $baselines = [];
        foreach ($attempts as $attempt) {
            foreach (array_column($run['analyzers'], 'id') as $analyzerId) {
                if (! in_array($attempt['id'].' '.$analyzerId, $judged, true)) {
                    $observations[] = self::observation($attempt['input']['source_id'], $analyzerId, 'not_evaluated', 'The attempt produced no output to validate');
                }
                if ($attempt['input']['status'] === 'available') {
                    $baselines[] = self::baseline($attempt['input']['source_id'], $analyzerId, 'valid');
                }
            }
        }

        return Summary::markdown($run, $attempts, $observations, $baselines);
    }

    public function test_summary_shows_the_baseline_next_to_the_output_verdict_without_changing_either(): void
    {
        $attempts = [
            self::attempt('qpdf:unsigned.pdf', 'succeeded'),
            self::attempt('verapdf:signed-before.pdf', 'succeeded'),
            self::attempt('qpdf:damaged.pdf', 'failed', 'pdf-signer exited with code 1: startxref not found'),
            self::attempt('qpdf:gone.pdf', 'not_run'),
        ];
        $observations = [
            self::observation('qpdf:unsigned.pdf', 'pyhanko', 'valid', 'pyHanko judged 1 signature(s) intact and cryptographically valid'),
            self::observation('qpdf:unsigned.pdf', 'qpdf', 'valid', 'qpdf found no problem'),
            self::observation('verapdf:signed-before.pdf', 'pyhanko', 'invalid', 'pyHanko judged the signature(s) Signature1 not intact or not cryptographically valid'),
            self::observation('verapdf:signed-before.pdf', 'qpdf', 'valid', 'qpdf found no problem'),
            self::observation('qpdf:damaged.pdf', 'pyhanko', 'not_evaluated', 'The attempt produced no output to validate'),
            self::observation('qpdf:damaged.pdf', 'qpdf', 'not_evaluated', 'The attempt produced no output to validate'),
            self::observation('qpdf:gone.pdf', 'pyhanko', 'not_evaluated', 'The input was unavailable, so there is no output to validate'),
            self::observation('qpdf:gone.pdf', 'qpdf', 'not_evaluated', 'The input was unavailable, so there is no output to validate'),
        ];
        $baselines = [
            self::baseline('qpdf:unsigned.pdf', 'pyhanko', 'not_applicable'),
            self::baseline('qpdf:unsigned.pdf', 'qpdf', 'valid'),
            self::baseline('verapdf:signed-before.pdf', 'pyhanko', 'invalid'),
            self::baseline('verapdf:signed-before.pdf', 'qpdf', 'valid'),
            self::baseline('qpdf:damaged.pdf', 'pyhanko', 'inconclusive'),
            self::baseline('qpdf:damaged.pdf', 'qpdf', 'invalid'),
        ];

        $markdown = Summary::markdown(self::runRecord(), $attempts, $observations, $baselines);

        $expectedVerdicts = <<<'MARKDOWN'
            ## Verdicts

            | Analyzer | `valid` | `invalid` | `inconclusive` | `not_evaluated` | `not_applicable` |
            | -------- | ------: | --------: | -------------: | --------------: | ---------------: |
            | `pyhanko` | 1 | 1 | 0 | 2 | 0 |
            | `qpdf` | 2 | 0 | 0 | 2 | 0 |

            MARKDOWN;
        self::assertStringContainsString($expectedVerdicts, $markdown);

        $expectedBaseline = <<<'MARKDOWN'
            ## Baseline

            Each analyzer's verdict on the input itself, before signing. It is never combined with the verdict on the output.

            | Analyzer | `valid` | `invalid` | `inconclusive` | `not_evaluated` | `not_applicable` |
            | -------- | ------: | --------: | -------------: | --------------: | ---------------: |
            | `pyhanko` | 0 | 1 | 1 | 0 | 1 |
            | `qpdf` | 2 | 1 | 0 | 0 | 0 |

            Each cell gives the verdict on the input, then the verdict on the output.

            | Input | `pyhanko` | `qpdf` |
            | ----- | --------- | ------ |
            | `qpdf:unsigned.pdf` | `not_applicable` → `valid` | `valid` → `valid` |
            | `verapdf:signed-before.pdf` | `invalid` → `invalid` | `valid` → `valid` |
            | `qpdf:damaged.pdf` | `inconclusive` → `not_evaluated` | `invalid` → `not_evaluated` |

            Inputs without a baseline, because they were unavailable:

            | Input | Reason |
            | ----- | ------ |
            | `qpdf:gone.pdf` | `missing_upstream` |

            MARKDOWN;
        self::assertStringContainsString($expectedBaseline, $markdown);

        self::assertStringContainsString(
            "| Input | Analyzer | Baseline | Verdict | Summary |\n| ----- | -------- | -------- | ------- | ------- |\n| `verapdf:signed-before.pdf` | `pyhanko` | `invalid` | `invalid` | pyHanko judged the signature(s) Signature1 not intact or not cryptographically valid |\n",
            $markdown,
        );
    }

    public function test_analyzer_that_compares_the_output_with_its_input_has_no_baseline_column(): void
    {
        $run = self::runRecord();
        $run['analyzers'][] = ['id' => 'cmp', 'revision' => ['kind' => 'version', 'value' => '3.8']];
        $attempts = [self::attempt('qpdf:leading-junk.pdf', 'succeeded')];
        $observations = [
            self::observation('qpdf:leading-junk.pdf', 'pyhanko', 'valid', 'pyHanko judged 1 signature(s) intact and cryptographically valid'),
            self::observation('qpdf:leading-junk.pdf', 'qpdf', 'valid', 'qpdf found no problem'),
            self::observation('qpdf:leading-junk.pdf', 'cmp', 'invalid', 'The output does not start with the bytes of the input: input output differ: char 1, line 1'),
        ];
        $baselines = [
            self::baseline('qpdf:leading-junk.pdf', 'pyhanko', 'not_applicable'),
            self::baseline('qpdf:leading-junk.pdf', 'qpdf', 'inconclusive'),
        ];

        $markdown = Summary::markdown($run, $attempts, $observations, $baselines);

        $expectedBaseline = <<<'MARKDOWN'
            | `pyhanko` | 0 | 0 | 0 | 0 | 1 |
            | `qpdf` | 0 | 0 | 1 | 0 | 0 |

            `cmp` compares the output with its input, so it has no baseline.

            Each cell gives the verdict on the input, then the verdict on the output.

            | Input | `pyhanko` | `qpdf` |
            | ----- | --------- | ------ |
            | `qpdf:leading-junk.pdf` | `not_applicable` → `valid` | `inconclusive` → `valid` |

            MARKDOWN;
        self::assertStringContainsString($expectedBaseline, $markdown);
        self::assertStringContainsString("| `qpdf:leading-junk.pdf` | `cmp` | none | `invalid` | The output does not start with the bytes of the input: input output differ: char 1, line 1 |\n", $markdown);
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

            ## Baseline

            Each analyzer's verdict on the input itself, before signing. It is never combined with the verdict on the output.

            | Analyzer | `valid` | `invalid` | `inconclusive` | `not_evaluated` | `not_applicable` |
            | -------- | ------: | --------: | -------------: | --------------: | ---------------: |
            | `pyhanko` | 3 | 0 | 0 | 0 | 0 |
            | `qpdf` | 3 | 0 | 0 | 0 | 0 |

            Each cell gives the verdict on the input, then the verdict on the output.

            | Input | `pyhanko` | `qpdf` |
            | ----- | --------- | ------ |
            | `qpdf:good.pdf` | `valid` → `valid` | `valid` → `valid` |
            | `qpdf:hybrid.pdf` | `valid` → `invalid` | `valid` → `inconclusive` |
            | `qpdf:bad1.pdf` | `valid` → `not_evaluated` | `valid` → `not_evaluated` |

            Inputs without a baseline, because they were unavailable:

            | Input | Reason |
            | ----- | ------ |
            | `qpdf:gone.pdf` | `missing_upstream` |

            ## Failures

            ### Executions that did not succeed

            | Input | Capability | Outcome | Error |
            | ----- | ---------- | ------- | ----- |
            | `qpdf:bad1.pdf` | `supported` | `failed` | pdf-signer exited with code 1: Signing failed: not a PDF |
            | `qpdf:gone.pdf` | `not_evaluated` | `not_run` |  |

            ### Outputs not judged valid

            | Input | Analyzer | Baseline | Verdict | Summary |
            | ----- | -------- | -------- | ------- | ------- |
            | `qpdf:hybrid.pdf` | `pyhanko` | `valid` | `invalid` | pyHanko judged the signature(s) Signature1 not intact or not cryptographically valid |
            | `qpdf:hybrid.pdf` | `qpdf` | `valid` | `inconclusive` | qpdf reported warnings only |

            MARKDOWN;

        self::assertSame($expected, self::markdown($attempts, $observations));
    }

    public function test_expected_rejections_are_totaled_and_listed_apart_from_failures(): void
    {
        $attempts = [
            self::attempt('qpdf:good.pdf', 'succeeded'),
            self::attempt('qpdf:bad1.pdf', 'failed', 'pdf-signer exited with code 1: PDF version not found', 'expected_rejection'),
            self::attempt('qpdf:bad35.pdf', 'failed', 'pdf-signer exited with code 1: Object 1 is not an object stream'),
        ];
        $observations = [self::observation('qpdf:good.pdf', 'qpdf', 'valid', 'ok')];

        $markdown = self::markdown($attempts, $observations);

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

        $markdown = self::markdown($attempts, $observations);

        self::assertStringContainsString("## Failures\n\nNo other attempt failed and no output was judged invalid or inconclusive.\n", $markdown);
    }

    public function test_expected_rejection_that_succeeded_is_reported_as_observed(): void
    {
        $attempts = [self::attempt('qpdf:bad1.pdf', 'succeeded', capability: 'expected_rejection')];
        $observations = [self::observation('qpdf:bad1.pdf', 'qpdf', 'valid', 'ok')];

        $markdown = self::markdown($attempts, $observations);

        self::assertStringContainsString("| `succeeded` | 1 |\n", $markdown);
        self::assertStringContainsString("| Rejected as expected | 0 |\n", $markdown);
        self::assertStringContainsString("### Expected rejections that succeeded\n\n| Input |\n| ----- |\n| `qpdf:bad1.pdf` |\n", $markdown);
        self::assertStringNotContainsString('## Expected rejections'."\n", $markdown);
    }

    public function test_expected_rejection_that_timed_out_stays_a_failure(): void
    {
        $attempts = [self::attempt('qpdf:bad1.pdf', 'timed_out', 'pdf-signer did not finish within 120 seconds', 'expected_rejection')];
        $observations = [self::observation('qpdf:bad1.pdf', 'qpdf', 'not_evaluated', 'The attempt produced no output to validate')];

        $markdown = self::markdown($attempts, $observations);

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

        $markdown = self::markdown($attempts, $observations);

        self::assertStringContainsString("| `failed` | 1 |\n", $markdown);
        self::assertStringContainsString("| Rejected as expected | 0 |\n", $markdown);
        self::assertStringContainsString("| `qpdf:bad1.pdf` | `expected_rejection` | `failed` | {$error} |", $markdown);
        self::assertStringNotContainsString('## Expected rejections'."\n", $markdown);
    }

    public function test_table_cells_keep_their_row_when_the_text_has_pipes_or_is_long(): void
    {
        $attempts = [self::attempt('qpdf:bad1.pdf', 'failed', 'a | b '.str_repeat('x', 300))];
        $observations = [self::observation('qpdf:bad1.pdf', 'qpdf', 'not_evaluated', 'The attempt produced no output to validate')];

        $markdown = self::markdown($attempts, $observations);

        self::assertStringContainsString('| a \| b '.str_repeat('x', 194).'… |', $markdown);
    }

    public function test_summary_without_failures_says_so(): void
    {
        $attempts = [self::attempt('qpdf:good.pdf', 'succeeded')];
        $observations = [self::observation('qpdf:good.pdf', 'pyhanko', 'valid', 'ok')];

        $markdown = self::markdown($attempts, $observations);

        self::assertStringContainsString("## Failures\n\nEvery attempt succeeded and every output was judged valid.\n", $markdown);
    }
}
