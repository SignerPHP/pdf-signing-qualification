<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Performance;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\Acquirer;
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

    private const string GENERATOR_NAMESPACE = 'SignerPHP\\PdfSigningQualification\\Tests\\Fixtures\\Synthetic\\';

    private const string REPOSITORY_URL = 'https://github.com/SignerPHP/pdf-signing-qualification';

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
        $inputPath = $this->generate($reference['generator']);
        $declared = ['sha256' => $reference['sha256'], 'byte_size' => $reference['byte_size']];
        $generated = Artifact::describe($inputPath);
        if ($generated !== $declared) {
            throw new RuntimeException("{$reference['generator']} wrote {$generated['byte_size']} bytes with SHA-256 {$generated['sha256']}, not the reference document declared by the protocol");
        }
        $sourceId = self::SOURCE_ID.':'.basename($inputPath);
        $license = ['expression' => 'MIT', 'evidence_url' => self::REPOSITORY_URL.'/blob/main/LICENSE'];
        $acquisition = [
            'schema' => Acquirer::RECORD_SCHEMA,
            'source_id' => $sourceId,
            'source' => ['id' => self::SOURCE_ID, 'url' => self::REPOSITORY_URL, 'revision' => ['kind' => 'version', 'value' => '1'], 'license' => $license],
            'path' => basename($inputPath),
            'file_url' => self::REPOSITORY_URL.'/blob/main/'.$reference['generator'],
            'status' => 'available',
            'retrieved_at' => ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'sha256' => $declared['sha256'],
            'byte_size' => $declared['byte_size'],
            'license' => $license,
        ];

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

    private function generate(string $generator): string
    {
        $class = self::GENERATOR_NAMESPACE.basename($generator, '.php');
        if (! class_exists($class) || ! method_exists($class, 'create')) {
            throw new RuntimeException("The generator {$generator} of the reference document does not define {$class}::create()");
        }
        if (! is_dir($this->inputDirectory) && ! mkdir($this->inputDirectory, 0700, true)) {
            throw new RuntimeException("Unable to create {$this->inputDirectory}");
        }
        $path = $class::create($this->inputDirectory);
        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException("The generator {$generator} of the reference document did not write a file");
        }

        return $path;
    }
}
