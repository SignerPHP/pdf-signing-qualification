<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

use InvalidArgumentException;

/**
 * One command that runs several commands one after the other and stops at the first that
 * fails, with its exit code, so that an attempt with several signatures is still one process
 * tree for the runner, its limits and its measurement.
 */
final class SequentialCommand
{
    /**
     * @param  list<list<string>>  $commands
     * @return list<string>
     */
    public static function of(array $commands): array
    {
        if ($commands === []) {
            throw new InvalidArgumentException('A sequence needs at least one command');
        }
        if (count($commands) === 1) {
            return $commands[0];
        }

        $script = implode(' && ', array_map(
            static fn (array $command): string => implode(' ', array_map(escapeshellarg(...), $command)),
            $commands,
        ));

        return ['sh', '-ec', $script];
    }
}
