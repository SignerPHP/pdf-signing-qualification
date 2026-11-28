<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Run;

use RuntimeException;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;

final class HarnessRevision
{
    private const float TIMEOUT_SECONDS = 30.0;

    public static function describe(CommandRunner $runner, string $checkout): array
    {
        try {
            $commit = self::git($runner, $checkout, ['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']);
            if ($commit->exitCode !== 0 || preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})$/', trim($commit->output)) !== 1) {
                return self::unavailable("Unable to read the commit of the checkout {$checkout}: git rev-parse {$commit->describe()}", $commit);
            }
            $status = self::git($runner, $checkout, ['status', '--porcelain=v1', '-z', '--untracked-files=no', '--ignore-submodules=none']);
            if ($status->exitCode !== 0) {
                return self::unavailable("Unable to read the working tree of the checkout {$checkout}: git status {$status->describe()}", $status);
            }
        } catch (RuntimeException) {
            return ['status' => 'unavailable', 'reason' => "Unable to run git in the checkout {$checkout}"];
        }

        $paths = self::changedPaths($status->output);

        return [
            'status' => 'available',
            'revision' => ['kind' => 'commit', 'value' => trim($commit->output)],
            'working_tree' => $paths === [] ? ['status' => 'clean'] : ['status' => 'modified', 'paths' => $paths],
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function git(CommandRunner $runner, string $checkout, array $arguments): CommandResult
    {
        return $runner->run(['git', '-c', "safe.directory={$checkout}", '--no-optional-locks', '-C', $checkout, ...$arguments], self::TIMEOUT_SECONDS);
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
            if (in_array($entry[0], ['R', 'C'], true)) {
                $paths[] = (string) array_shift($entries);
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    private static function unavailable(string $reason, CommandResult $result): array
    {
        if (str_contains($result->output, 'not a git repository')) {
            $reason .= ', because it is not a Git checkout or its Git directory is not in the container';
        }

        return ['status' => 'unavailable', 'reason' => $reason];
    }
}
