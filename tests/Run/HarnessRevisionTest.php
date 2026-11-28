<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Run\HarnessRevision;
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

    public function test_untracked_files_leave_the_working_tree_clean(): void
    {
        file_put_contents($this->checkout.'/notes.txt', "Untracked\n");

        self::assertSame(['status' => 'clean'], HarnessRevision::describe(new ProcessRunner, $this->checkout)['working_tree']);
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
            ['status' => 'unavailable', 'reason' => "Unable to read the commit of the checkout {$this->checkout}: git rev-parse exited with code 128, because it is not a Git checkout or its Git directory is not in the container"],
            HarnessRevision::describe(new ProcessRunner, $this->checkout),
        );
    }
}
