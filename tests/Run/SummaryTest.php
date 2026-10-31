<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

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

    private static function attempt(string $sourceId, string $outcome, ?string $error = null): array
    {
        return [
            'id' => "run-20261031T120000Z/basic-invisible-sha256/pdf-signer/{$sourceId}",
            'input' => ['source_id' => $sourceId],
            'capability' => $outcome === 'not_run' ? 'not_evaluated' : 'supported',
            'execution' => ['outcome' => $outcome, 'error' => $error === null ? null : ['message' => $error]],
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
