<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Performance;

use Closure;
use SignerPHP\PdfSigningQualification\Performance\HostControls;
use SignerPHP\PdfSigningQualification\Performance\Protocol;
use SignerPHP\PdfSigningQualification\Tests\Run\RunFixture;

final class StudyFixture
{
    public const array VALID = ['pdfsig' => 'valid', 'qpdf' => 'valid'];

    public const array PROTOCOL = ['warm_up_runs' => 1, 'measured_runs' => 2, 'calibration_runs' => 2, 'reference' => ['generator' => 'tests/Fixtures/Synthetic/MinimalPdf.php', 'sha256' => '39d1a47d379f2b659aee63c13d4bcaac131dec23205e1ca713a6c0817fced4ba', 'byte_size' => 349, 'repetitions' => 2]];

    /**
     * @param  Closure(RunFixture, array{label: string, implementation_id: string, role: string}): void  $attempts
     */
    public static function write(string $directory, string|int $session, Closure $attempts, array $protocolChanges = [], ?Closure $runChanges = null, ?Closure $referenceChanges = null): string
    {
        $protocol = ProtocolTest::protocol(array_replace(self::PROTOCOL, $protocolChanges));
        $timestamp = '20261109T12000'.($session === Protocol::CALIBRATION ? '0' : (string) $session).'Z';
        $studyDirectory = "{$directory}/performance-{$session}-{$timestamp}";
        mkdir($studyDirectory.'/controls', 0777, true);
        $encode = static fn (array $document): string => json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $study = $protocol->study(basename($studyDirectory), $session, '2026-11-09T12:00:00Z');
        file_put_contents($studyDirectory.'/protocol.json', $protocol->contents);
        file_put_contents($studyDirectory.'/study.json', $encode($study));

        foreach ($study['runs'] as $planned) {
            $label = $planned['label'];
            $runId = "run-{$timestamp}-{$label}";
            $run = (new RunFixture($runId))->implementation($planned['implementation_id'], self::revision($planned['implementation_id']));
            $run->run['environment']['cpu_model'] = ['status' => 'available', 'name' => 'AMD Ryzen 7 3700X 8-Core Processor'];
            if ($runChanges !== null) {
                $runChanges($run, $planned);
            }
            $attempts($run, $planned);
            $run->write("{$studyDirectory}/{$runId}");

            file_put_contents("{$studyDirectory}/controls/{$label}.json", $encode([
                'schema' => HostControls::SCHEMA,
                'label' => $label,
                'recorded_at' => '2026-11-09T12:00:00Z',
                'cpuset' => ['status' => 'available', 'cpus' => '4-7'],
                'scaling_governors' => ['4' => 'performance', '5' => 'performance', '6' => 'performance', '7' => 'performance'],
                'boost' => 'disabled',
                'load_average' => [0.4, 0.5, 0.6],
                'violations' => [],
            ]));

            $reference = ['attempts' => [], 'measurements' => [], 'observations' => []];
            $wallTime = $planned['implementation_id'] === 'jsignpdf' ? 0.9 : 0.1;
            $temporary = "{$studyDirectory}/.reference-{$label}";
            for ($repetition = 1; $repetition <= $protocol->document['reference']['repetitions']; $repetition++) {
                $referenceRun = (new RunFixture(sprintf('%s-reference-%02d', $runId, $repetition)))->implementation($run->run['implementation']['id'], $run->run['implementation']['revision']);
                $referenceRun->succeeded('reference:minimal.pdf', self::VALID)->measured('reference:minimal.pdf', $wallTime, $wallTime, 0.01, $planned['implementation_id'] === 'jsignpdf' ? 120_000_000 : 30_000_000);
                $referenceRun->write("{$temporary}-{$repetition}");
                foreach (array_keys($reference) as $name) {
                    array_push($reference[$name], ...json_decode((string) file_get_contents("{$temporary}-{$repetition}/{$name}.json"), true, flags: JSON_THROW_ON_ERROR));
                }
                exec('rm -rf '.escapeshellarg("{$temporary}-{$repetition}"));
            }
            if ($referenceChanges !== null) {
                $reference = $referenceChanges($reference, $planned);
            }
            mkdir("{$studyDirectory}/references/{$label}", 0777, true);
            foreach ($reference as $name => $documents) {
                file_put_contents("{$studyDirectory}/references/{$label}/{$name}.json", $encode($documents));
            }
        }

        return $studyDirectory;
    }

    private static function revision(string $implementationId): array
    {
        return $implementationId === 'jsignpdf' ? ['kind' => 'version', 'value' => '3.2.1'] : ['kind' => 'commit', 'value' => str_repeat('a', 40)];
    }
}
