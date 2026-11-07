<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Run\ComparisonSummary;
use SignerPHP\PdfSigningQualification\Run\RunComparison;

final class ComparisonSummaryTest extends TestCase
{
    private const array VALID = ['pdfsig' => 'valid', 'qpdf' => 'valid'];

    public function test_summary_lists_each_change_and_every_input_that_was_not_compared(): void
    {
        $reference = (new RunFixture('run-20261107T120000Z'))
            ->succeeded('qpdf:introduced.pdf', self::VALID)
            ->failed('qpdf:fixed.pdf')
            ->succeeded('qpdf:persistent.pdf', ['pdfsig' => 'invalid', 'qpdf' => 'valid'])
            ->succeeded('qpdf:changed.pdf', self::VALID)
            ->failed('qpdf:only-in-reference.pdf')
            ->unavailable('qpdf:unavailable.pdf');
        $candidate = (new RunFixture('run-20261107T130000Z', ['pdfsig' => '24.02.0', 'qpdf' => '11.3.0']))
            ->implementationCommit(str_repeat('b', 40))
            ->succeeded('qpdf:introduced.pdf', ['pdfsig' => 'invalid', 'qpdf' => 'valid'])
            ->succeeded('qpdf:fixed.pdf', self::VALID)
            ->succeeded('qpdf:persistent.pdf', ['pdfsig' => 'invalid', 'qpdf' => 'valid'])
            ->succeeded('qpdf:changed.pdf', ['pdfsig' => 'valid', 'qpdf' => 'not_applicable'])
            ->succeeded('qpdf:unavailable.pdf', self::VALID);
        $candidate->run['source_configuration']['sha256'] = str_repeat('f', 64);
        $candidate->run['environment']['resource_limits']['timeout_seconds'] = 60;
        $inputSha256 = hash('sha256', 'input');
        $a = str_repeat('a', 40);
        $b = str_repeat('b', 40);
        $d = str_repeat('d', 64);
        $f = str_repeat('f', 64);

        $markdown = ComparisonSummary::markdown(RunComparison::compare($reference->records(), $candidate->records()));

        self::assertSame(<<<MARKDOWN
            # Comparison of run-20261107T120000Z with run-20261107T130000Z

            Reference run `run-20261107T120000Z`: scenario `basic-invisible-sha256`, implementation `pdf-signer` at commit `{$a}`.
            Candidate run `run-20261107T130000Z`: scenario `basic-invisible-sha256`, implementation `pdf-signer` at commit `{$b}`.

            Attempts are paired by input and scenario. A failure is what the summary of a run lists under its failures: an execution that did not succeed, unless it was a rejection that was expected, an expected rejection that succeeded, and a verdict of `invalid` or `inconclusive`. An item is `introduced` when the candidate run fails it and the reference run did not, `fixed` when the reference run failed it and the candidate run succeeded without an expected rejection or judged it `valid`, `persistent` when both runs fail it, and `changed` for any other difference. Output hashes are not compared.

            ## Differences between the runs

            | Subject | Analyzer | Reference | Candidate |
            | ------- | -------- | --------- | --------- |
            | `implementation` |  | `pdf-signer` at commit `{$a}` | `pdf-signer` at commit `{$b}` |
            | `analyzer` | `pdfsig` | 22.12.0 | 24.02.0 |
            | `source_configuration` |  | SHA-256 `{$d}`, 13321 bytes | SHA-256 `{$f}`, 13321 bytes |
            | `resource_limits` |  | `{"timeout_seconds":120,"address_space_bytes":1073741824,"file_size_bytes":268435456,"storage_bytes":536870912,"open_files":256}` | `{"timeout_seconds":60,"address_space_bytes":1073741824,"file_size_bytes":268435456,"storage_bytes":536870912,"open_files":256}` |

            ## Totals

            | Input status | Inputs |
            | ------------ | -----: |
            | `paired` | 4 |
            | `only_in_reference` | 1 |
            | `only_in_candidate` | 0 |
            | `unavailable` | 1 |
            | `input_changed` | 0 |

            | Change | Items |
            | ------ | ----: |
            | `introduced` | 1 |
            | `fixed` | 1 |
            | `persistent` | 1 |
            | `changed` | 3 |
            | `unchanged` | 10 |

            ## New failures

            | Input | Item | Reference | Candidate |
            | ----- | ---- | --------- | --------- |
            | `qpdf:introduced.pdf` | verdict of `pdfsig` | `valid` | `invalid` |

            ## Fixed

            | Input | Item | Reference | Candidate |
            | ----- | ---- | --------- | --------- |
            | `qpdf:fixed.pdf` | execution | `failed` | `succeeded` |

            ## Persistent failures

            | Input | Item | Reference | Candidate |
            | ----- | ---- | --------- | --------- |
            | `qpdf:persistent.pdf` | verdict of `pdfsig` | `invalid` | `invalid` |

            ## Other changes

            | Input | Item | Reference | Candidate |
            | ----- | ---- | --------- | --------- |
            | `qpdf:fixed.pdf` | verdict of `pdfsig` | `not_evaluated` | `valid` |
            | `qpdf:fixed.pdf` | verdict of `qpdf` | `not_evaluated` | `valid` |
            | `qpdf:changed.pdf` | verdict of `qpdf` | `valid` | `not_applicable` |

            ## Inputs not compared

            These inputs are not paired, so nothing in them is counted as fixed, persistent or introduced.

            | Input | Scenario | Status | Reference | Candidate |
            | ----- | -------- | ------ | --------- | --------- |
            | `qpdf:only-in-reference.pdf` | `basic-invisible-sha256` | `only_in_reference` | SHA-256 `{$inputSha256}` | absent |
            | `qpdf:unavailable.pdf` | `basic-invisible-sha256` | `unavailable` | unavailable (`missing_upstream`) | SHA-256 `{$inputSha256}` |

            The candidate run introduced 1 failure(s).

            MARKDOWN, $markdown);
    }

    public function test_summary_of_runs_without_any_difference_says_so(): void
    {
        $markdown = ComparisonSummary::markdown(RunComparison::compare(
            (new RunFixture('run-20261107T120000Z'))->succeeded('qpdf:good.pdf', self::VALID, 'first')->records(),
            (new RunFixture('run-20261107T130000Z'))->succeeded('qpdf:good.pdf', self::VALID, 'second')->records(),
        ));

        self::assertStringContainsString("## Differences between the runs\n\nThe two runs have the same scenario, implementation, implementation runtime and compatibility, analyzer revisions and policies, source configuration, expected rejections, container image and resource limits.\n", $markdown);
        self::assertStringNotContainsString('## Regression cases', $markdown);
        self::assertStringContainsString("## New failures\n\nThe candidate run has no failure that the reference run did not have.\n", $markdown);
        self::assertStringNotContainsString('## Fixed', $markdown);
        self::assertStringNotContainsString('## Inputs not compared', $markdown);
        self::assertStringEndsWith("The candidate run introduced no failure.\n", $markdown);
    }

    public function test_summary_gives_the_condition_of_each_regression_case_in_both_runs(): void
    {
        $reference = (new RunFixture('run-20261107T120000Z'))
            ->succeeded('qpdf:good.pdf', self::VALID)
            ->regression('hybrid-xref', 'absent', self::VALID)
            ->regression('removed-case', 'present');
        $candidate = (new RunFixture('run-20261107T130000Z'))
            ->compatibility(['status' => 'incompatible', 'reason' => 'pdf-signer does not list --no-default-timestamp in its help'])
            ->succeeded('qpdf:good.pdf', self::VALID)
            ->regression('hybrid-xref', 'present', ['pdfsig' => 'inconclusive', 'qpdf' => 'valid']);

        $markdown = ComparisonSummary::markdown(RunComparison::compare($reference->records(), $candidate->records()));

        self::assertStringContainsString('| `implementation_compatibility` |  | absent | `{"status":"incompatible","reason":"pdf-signer does not list --no-default-timestamp in its help"}` |', $markdown);
        self::assertStringContainsString("## New failures\n\nNo input has a failure that the reference run did not have; the regression cases below do.\n", $markdown);
        self::assertStringContainsString(<<<'MARKDOWN'
            | Case | Status | Reference | Candidate | Change |
            | ---- | ------ | --------- | --------- | ------ |
            | `hybrid-xref` | `paired` | `absent`: The condition of hybrid-xref is absent | `present`: The condition of hybrid-xref is present | `introduced` |
            | `removed-case` | `only_in_reference` | `present`: The condition of removed-case is present | absent from the run |  |

            Other items of the regression cases that are not unchanged:

            | Case | Item | Reference | Candidate | Change |
            | ---- | ---- | --------- | --------- | ------ |
            | `hybrid-xref` | verdict of `pdfsig` | `valid` | `inconclusive` | `introduced` |

            The candidate run introduced 2 failure(s).

            MARKDOWN, $markdown);
    }
}
