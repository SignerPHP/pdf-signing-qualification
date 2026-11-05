<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

use RuntimeException;

final class NetworkIsolation
{
    public function __construct(
        private readonly string $interfacesDirectory = '/sys/class/net',
    ) {}

    public function assertIsolated(): void
    {
        $entries = scandir($this->interfacesDirectory);
        if ($entries === false) {
            throw new RuntimeException("Unable to list the network interfaces in {$this->interfacesDirectory}");
        }

        $interfaces = array_values(array_diff($entries, ['.', '..', 'lo']));
        if ($interfaces !== []) {
            throw new RuntimeException('Attempts must run without network access, but these interfaces are present: '.implode(', ', $interfaces).'. Run them in the sandbox service.');
        }
    }
}
