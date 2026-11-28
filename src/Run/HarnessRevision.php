<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Run;

use RuntimeException;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;

final class HarnessRevision
{
    private const float TIMEOUT_SECONDS = 30.0;

    private const string PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    private const array REVISION_ARGUMENTS = ['rev-parse', '--verify', '--quiet', 'HEAD^{commit}'];

    public static function describe(CommandRunner $runner, string $checkout): array
    {
        try {
            $revision = self::git($runner, $checkout, self::REVISION_ARGUMENTS);
            $commit = self::commit($revision);
            if ($commit === null) {
                return self::unavailable($checkout, 'commit', 'rev-parse', $revision);
            }
            $status = self::git($runner, $checkout, ['status', '--porcelain=v1', '-z', '--untracked-files=all', '--ignore-submodules=none']);
            if ($status->exitCode !== 0) {
                return self::unavailable($checkout, 'working tree', 'status', $status);
            }
            $commitAfterStatus = self::commit(self::git($runner, $checkout, self::REVISION_ARGUMENTS));
        } catch (RuntimeException) {
            return ['status' => 'unavailable', 'reason' => "Unable to run git in the checkout {$checkout}"];
        }
        if ($commitAfterStatus !== $commit) {
            return ['status' => 'unavailable', 'reason' => "The commit of the checkout {$checkout} changed from {$commit} to ".($commitAfterStatus ?? 'an unreadable commit').' while its working tree was read'];
        }

        $paths = self::changedPaths($status->output);

        return [
            'status' => 'available',
            'revision' => ['kind' => 'commit', 'value' => $commit],
            'working_tree' => $paths === [] ? ['status' => 'clean'] : ['status' => 'modified', 'paths' => $paths],
        ];
    }

    private static function commit(CommandResult $result): ?string
    {
        $commit = trim($result->output);

        return $result->exitCode === 0 && preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})$/', $commit) === 1 ? $commit : null;
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function git(CommandRunner $runner, string $checkout, array $arguments): CommandResult
    {
        return $runner->run([
            'env', '-i', 'PATH='.self::PATH, 'HOME=/nonexistent',
            'sh', '-c', 'exec "$@" 2>/dev/null', 'sh',
            'git', '-c', "safe.directory={$checkout}", '-c', 'core.fsmonitor=false', '--no-optional-locks', '-C', $checkout, ...$arguments,
        ], self::TIMEOUT_SECONDS);
    }

    /**
     * @return list<string>
     */
    private static function changedPaths(string $status): array
    {
        $entries = explode("\0", rtrim($status, "\0"));
        $paths = [];
        while ($entries !== [] && $entries[0] !== '') {
            $entry = array_shift($entries);
            $paths[] = substr($entry, 3);
            if (in_array($entry[0], ['R', 'C'], true) || in_array($entry[1], ['R', 'C'], true)) {
                $paths[] = (string) array_shift($entries);
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    private static function unavailable(string $checkout, string $subject, string $command, CommandResult $result): array
    {
        return ['status' => 'unavailable', 'reason' => "Unable to read the {$subject} of the checkout {$checkout}: git {$command} {$result->describe()}"];
    }
}
