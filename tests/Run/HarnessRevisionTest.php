<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Run\HarnessRevision;
use SignerPHP\PdfSigningQualification\Validation\CommandAccess;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;

final class HarnessRevisionTest extends TestCase
{
    private string $checkout;

    protected function setUp(): void
    {
        $this->checkout = sys_get_temp_dir().'/harness-revision-'.bin2hex(random_bytes(6));
        mkdir($this->checkout.'/src', recursive: true);
        file_put_contents($this->checkout.'/src/Runner.php', "<?php\n");
        file_put_contents($this->checkout.'/README.md', "Harness\n");
        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git('-c', 'user.name=Harness', '-c', 'user.email=harness@example.org', 'commit', '-q', '-m', 'Harness');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->checkout));
    }

    private function git(string ...$arguments): string
    {
        exec(implode(' ', array_map(escapeshellarg(...), ['git', '-C', $this->checkout, ...$arguments])).' 2>&1', $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));

        return implode("\n", $output);
    }

    public function test_clean_checkout_records_its_commit_and_a_clean_working_tree(): void
    {
        self::assertSame(
            ['status' => 'available', 'revision' => ['kind' => 'commit', 'value' => $this->git('rev-parse', 'HEAD')], 'working_tree' => ['status' => 'clean']],
            HarnessRevision::describe(new ProcessRunner, $this->checkout),
        );
    }

    public function test_untracked_files_that_are_not_ignored_mark_the_working_tree_modified(): void
    {
        mkdir($this->checkout.'/config/regressions', recursive: true);
        file_put_contents($this->checkout.'/config/regressions/new-case.json', "{}\n");

        self::assertSame(['status' => 'modified', 'paths' => ['config/regressions/new-case.json']], HarnessRevision::describe(new ProcessRunner, $this->checkout)['working_tree']);
    }

    public function test_ignored_files_leave_the_working_tree_clean(): void
    {
        file_put_contents($this->checkout.'/.gitignore', "/var/\n");
        $this->git('add', '.gitignore');
        $this->git('-c', 'user.name=Harness', '-c', 'user.email=harness@example.org', 'commit', '-q', '-m', 'Ignore var');
        mkdir($this->checkout.'/var/runs', recursive: true);
        file_put_contents($this->checkout.'/var/runs/run.json', "{}\n");
        mkdir($this->checkout.'/.claude/worktrees/agent', recursive: true);
        file_put_contents($this->checkout.'/.claude/worktrees/agent/notes.txt', "Local\n");
        file_put_contents($this->checkout.'/.git/info/exclude', "/.claude/worktrees/\n", FILE_APPEND);

        self::assertSame(['status' => 'clean'], HarnessRevision::describe(new ProcessRunner, $this->checkout)['working_tree']);
    }

    public function test_rename_in_the_working_tree_records_both_paths(): void
    {
        rename($this->checkout.'/README.md', $this->checkout.'/NOTES.md');
        $this->git('add', '-N', 'NOTES.md');

        self::assertSame(['status' => 'modified', 'paths' => ['NOTES.md', 'README.md']], HarnessRevision::describe(new ProcessRunner, $this->checkout)['working_tree']);
    }

    public function test_messages_git_writes_beside_the_status_are_not_read_as_paths(): void
    {
        $this->git('config', 'core.fsmonitor', '/nonexistent/fsmonitor-hook');

        self::assertSame(['status' => 'clean'], HarnessRevision::describe(new ProcessRunner, $this->checkout)['working_tree']);
    }

    public function test_commit_that_moves_head_while_the_tree_is_read_makes_the_revision_unavailable(): void
    {
        $runner = new class implements CommandRunner
        {
            /** @var list<string> */
            private array $outputs = ["1111111111111111111111111111111111111111\n", '', "2222222222222222222222222222222222222222\n"];

            public function run(array $command, float $timeoutSeconds, ?CommandAccess $access = null): CommandResult
            {
                return new CommandResult(0, (string) array_shift($this->outputs));
            }
        };

        self::assertSame(
            ['status' => 'unavailable', 'reason' => "The commit of the checkout {$this->checkout} changed from 1111111111111111111111111111111111111111 to 2222222222222222222222222222222222222222 while its working tree was read"],
            HarnessRevision::describe($runner, $this->checkout),
        );
    }

    public function test_changed_tracked_files_are_recorded_whether_staged_or_not(): void
    {
        file_put_contents($this->checkout.'/src/Runner.php', "<?php\n\nexit(1);\n");
        file_put_contents($this->checkout.'/README.md', "Changed harness\n");
        $this->git('add', 'README.md');

        self::assertSame(
            ['status' => 'available', 'revision' => ['kind' => 'commit', 'value' => $this->git('rev-parse', 'HEAD')], 'working_tree' => ['status' => 'modified', 'paths' => ['README.md', 'src/Runner.php']]],
            HarnessRevision::describe(new ProcessRunner, $this->checkout),
        );
    }

    public function test_deleted_and_renamed_tracked_files_are_recorded(): void
    {
        unlink($this->checkout.'/README.md');
        $this->git('mv', 'src/Runner.php', 'src/Attempts.php');

        self::assertSame(['status' => 'modified', 'paths' => ['README.md', 'src/Attempts.php', 'src/Runner.php']], HarnessRevision::describe(new ProcessRunner, $this->checkout)['working_tree']);
    }

    public function test_directory_that_is_not_a_checkout_is_unavailable_with_its_reason(): void
    {
        exec('rm -rf '.escapeshellarg($this->checkout.'/.git'));

        self::assertSame(
            ['status' => 'unavailable', 'reason' => "Unable to read the commit of the checkout {$this->checkout}: git rev-parse exited with code 128"],
            HarnessRevision::describe(new ProcessRunner, $this->checkout),
        );
    }
}
