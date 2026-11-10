<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Performance;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\SyntheticInput;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Signing\SigningImplementation;
use SignerPHP\PdfSigningQualification\Validation\ToolValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class FixedCostReference
{
    public const string SOURCE_ID = 'reference';

    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly AttemptRunner $attemptRunner,
        private readonly ValidationPanel $panel,
        private readonly string $inputDirectory,
        private readonly Closure $clock,
    ) {}

    /**
     * @param  array{generator: string, sha256: string, byte_size: int, repetitions: int}  $reference
     * @return array{attempts: list<array>, measurements: list<array>, observations: list<array>}
     */
    public function measure(string $referenceId, array $scenario, SigningImplementation $implementation, array $reference): array
    {
        $inputPath = SyntheticInput::generate($reference['generator'], $this->inputDirectory, 'the reference document');
        $declared = ['sha256' => $reference['sha256'], 'byte_size' => $reference['byte_size']];
        $generated = Artifact::describe($inputPath);
        if ($generated !== $declared) {
            throw new RuntimeException("{$reference['generator']} wrote {$generated['byte_size']} bytes with SHA-256 {$generated['sha256']}, not the reference document declared by the protocol");
        }
        $acquisition = SyntheticInput::available(
            SyntheticInput::record(self::SOURCE_ID.':'.basename($inputPath), self::SOURCE_ID, basename($inputPath), $reference['generator']),
            $declared,
            ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        );

        $attempts = [];
        $measurements = [];
        $observations = [];
        for ($repetition = 1; $repetition <= $reference['repetitions']; $repetition++) {
            $result = $this->attemptRunner->run(sprintf('%s-%02d', $referenceId, $repetition), $scenario, $implementation, $acquisition, $inputPath);
            $attempts[] = $result->record;
            if ($result->measurement !== null) {
                $measurements[] = $result->measurement;
            }
            foreach ($this->panel->observe(new ValidationSubject($result->record['id'], $declared, $result->outputPath, $inputPath)) as $observation) {
                (new SchemaValidator)->assertValid($observation, ToolValidator::OBSERVATION_SCHEMA, "observation {$observation['id']}");
                $observations[] = $observation;
            }
        }

        return ['attempts' => $attempts, 'measurements' => $measurements, 'observations' => $observations];
    }
}
