<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

use InvalidArgumentException;

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
