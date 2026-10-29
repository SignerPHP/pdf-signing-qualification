<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;

final class ProcessRunnerTest extends TestCase
{
    public function test_output_and_errors_are_captured_in_order_with_the_exit_code(): void
    {
        $result = (new ProcessRunner)->run(['sh', '-c', 'echo out; echo err >&2; exit 3'], 10.0);

        self::assertSame(3, $result->exitCode);
        self::assertSame("out\nerr\n", $result->output);
        self::assertFalse($result->timedOut);
    }

    public function test_arguments_are_not_interpreted_by_a_shell(): void
    {
        $result = (new ProcessRunner)->run(['echo', '$HOME; exit 9'], 10.0);

        self::assertSame(0, $result->exitCode);
        self::assertSame("\$HOME; exit 9\n", $result->output);
    }

    public function test_process_killed_by_a_signal_has_no_exit_code(): void
    {
        $result = (new ProcessRunner)->run(['sh', '-c', 'kill -SEGV $$'], 10.0);

        self::assertNull($result->exitCode);
        self::assertSame(11, $result->terminationSignal);
        self::assertSame('was terminated by signal 11', $result->describe());
    }

    public function test_process_is_stopped_when_it_exceeds_the_timeout(): void
    {
        $start = hrtime(true);
        $result = (new ProcessRunner)->run(['sh', '-c', 'echo started; exec sleep 30'], 0.5);

        self::assertTrue($result->timedOut);
        self::assertNull($result->exitCode);
        self::assertSame("started\n", $result->output);
        self::assertLessThan(5.0, (hrtime(true) - $start) / 1e9);
    }

    public function test_process_that_closes_its_output_is_still_stopped_at_the_timeout(): void
    {
        $start = hrtime(true);
        $result = (new ProcessRunner)->run(['sh', '-c', 'echo started; exec >&- 2>&-; exec sleep 30'], 0.5);

        self::assertTrue($result->timedOut);
        self::assertNull($result->exitCode);
        self::assertSame("started\n", $result->output);
        self::assertLessThan(5.0, (hrtime(true) - $start) / 1e9);
    }

    public function test_processes_left_in_the_group_of_a_process_group_leader_are_stopped(): void
    {
        $result = (new ProcessRunner)->run(['setsid', 'sh', '-c', 'sleep 30 > /dev/null 2>&1 & echo $!'], 10.0);

        self::assertSame(0, $result->exitCode);
        self::assertTrue(ProcessStatus::endsWithin((int) $result->output, 2.0), 'The background process is still running');
    }
}
