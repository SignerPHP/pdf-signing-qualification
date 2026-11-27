<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic\PadesCatalogEntriesPdf;
use SignerPHP\PdfSigningQualification\Validation\CommandAccess;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;
use SignerPHP\PdfSigningQualification\Validation\DocumentTimestampImprintValidator;
use SignerPHP\PdfSigningQualification\Validation\PadesStructureValidator;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\QpdfObjects;
use SignerPHP\PdfSigningQualification\Validation\ToolValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class PadesStructureValidatorsTest extends TestCase
{
    private const string ATTEMPT_ID = 'run-20261123T120000Z/pades-baseline-lta/pdf-signer/synthetic:input.pdf';

    private const array ARTIFACT = ['sha256' => '3b4c1f0a9e6d2b7c8f5e4a3d2c1b0a9f8e7d6c5b4a39281706f5e4d3c2b1a0f9', 'byte_size' => 1_024];

    private const string BEFORE = '%PDF-1.7 signed bytes before ';

    private const string AFTER = ' signed bytes after';

    private static string $output;

    private static string $directory;

    public static function setUpBeforeClass(): void
    {
        self::$output = (string) tempnam(sys_get_temp_dir(), 'output');
        file_put_contents(self::$output, self::BEFORE.'<'.str_repeat('0', 64).'>'.self::AFTER);
        self::$directory = sys_get_temp_dir().'/pades-structure-'.bin2hex(random_bytes(6));
        mkdir(self::$directory);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$output);
        exec('rm -rf '.escapeshellarg(self::$directory));
    }

    /**
     * A listing in the shape of `qpdf --json=2 --json-key=qpdf`: a certification signature in
     * object 5, a document timestamp in object 7, each the value of a field of the form.
     */
    private static function listing(?Closure $mutation = null): array
    {
        $gapStart = strlen(self::BEFORE);
        $gapEnd = $gapStart + 66;
        $covered = self::BEFORE.self::AFTER;
        $objects = [
            'trailer' => ['value' => ['/Root' => '1 0 R']],
            'obj:1 0 R' => ['value' => ['/Type' => '/Catalog', '/AcroForm' => '2 0 R', '/Perms' => ['/DocMDP' => '5 0 R']]],
            'obj:2 0 R' => ['value' => ['/Fields' => ['4 0 R', '6 0 R']]],
            'obj:4 0 R' => ['value' => ['/FT' => '/Sig', '/T' => 'u:Signature1', '/V' => '5 0 R']],
            'obj:5 0 R' => ['value' => ['/Type' => '/Sig', '/SubFilter' => '/ETSI.CAdES.detached', '/ByteRange' => [0, 1, 2, 3], '/Contents' => 'b:00', '/Reference' => [['/Type' => '/SigRef', '/TransformMethod' => '/DocMDP', '/TransformParams' => ['/P' => 2]]]]],
            'obj:6 0 R' => ['value' => ['/FT' => '/Sig', '/T' => 'u:Timestamp1', '/V' => '7 0 R']],
            'obj:7 0 R' => ['value' => ['/Type' => '/DocTimeStamp', '/SubFilter' => '/ETSI.RFC3161', '/ByteRange' => [0, $gapStart, $gapEnd, strlen(self::AFTER)], '/Contents' => 'b:3082'.bin2hex("\x04\x20".hash('sha256', $covered, true)).'0000']],
        ];

        return ['version' => 2, 'qpdf' => [['jsonversion' => 2], $mutation === null ? $objects : $mutation($objects)]];
    }

    public static function structures(): iterable
    {
        yield 'certified with a registered document timestamp' => [null, 2, true, 'valid'];
        yield 'another permission level' => [null, 1, true, 'invalid'];
        yield 'approval scenario with /Perms /DocMDP' => [null, null, true, 'invalid'];
        yield 'certification outside /Perms' => [static function (array $objects): array {
            unset($objects['obj:1 0 R']['value']['/Perms']);
            $objects['obj:1 0 R']['value']['/DocMDP'] = '5 0 R';

            return $objects;
        }, 2, true, 'invalid'];
        yield 'document timestamp of type /Sig' => [static function (array $objects): array {
            $objects['obj:7 0 R']['value']['/Type'] = '/Sig';

            return $objects;
        }, 2, true, 'invalid'];
        yield 'document timestamp outside the form' => [static function (array $objects): array {
            $objects['obj:2 0 R']['value']['/Fields'] = ['4 0 R'];

            return $objects;
        }, 2, true, 'invalid'];
        yield 'document timestamp the scenario does not add' => [null, 2, false, 'invalid'];
        yield 'catalog entry for the document timestamp' => [static function (array $objects): array {
            $objects['obj:1 0 R']['value']['/DocTimeStamp'] = '7 0 R';

            return $objects;
        }, 2, true, 'invalid'];
    }

    #[DataProvider('structures')]
    public function test_structure_gives_the_verdict(?Closure $mutation, ?int $level, bool $documentTimestamp, string $verdict): void
    {
        $runner = new FakeCommandRunner(new CommandResult(0, "qpdf version 11.3.0\n"), new CommandResult(0, json_encode(self::listing($mutation), JSON_THROW_ON_ERROR)));
        $observation = self::observe(new PadesStructureValidator($runner, self::clock(), $level, $documentTimestamp));

        self::assertSame($verdict, $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame(QpdfObjects::command(self::$output), $runner->commands[0]);
    }

    public function test_certification_and_document_timestamp_of_the_input_are_not_the_implementation_s(): void
    {
        $listing = json_encode(self::listing(), JSON_THROW_ON_ERROR);
        $runner = new FakeCommandRunner(new CommandResult(0, "qpdf version 11.3.0\n"), new CommandResult(0, $listing));
        $subject = new ValidationSubject(self::ATTEMPT_ID, self::ARTIFACT, self::$output, self::$output);

        $approval = (new PadesStructureValidator($runner, self::clock(), null, false))->observe($subject);
        $certification = (new PadesStructureValidator($runner, self::clock(), 2, false))->observe($subject);

        self::assertSame('valid', $approval['verdict'], $approval['evidence']['summary']);
        self::assertSame('invalid', $certification['verdict']);
        self::assertStringContainsString('certification signature of the input', $certification['evidence']['summary']);
    }

    public static function catalogEntries(): iterable
    {
        foreach (PadesCatalogEntriesPdf::ENTRIES as $entry => $catalogEntries) {
            yield $entry => [$entry, $catalogEntries];
        }
    }

    #[DataProvider('catalogEntries')]
    public function test_entry_the_input_already_holds_is_not_the_implementation_s(string $entry, string $catalogEntries): void
    {
        $name = md5($entry);
        $input = PadesCatalogEntriesPdf::input(self::$directory, "{$name}-input", $catalogEntries);
        $output = PadesCatalogEntriesPdf::update(self::$directory, "{$name}-kept", $input, $catalogEntries);

        $observation = self::observeDocument($output, $input);

        self::assertSame('valid', $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame([$entry], $observation['evidence']['details']['inherited_entries']);
    }

    #[DataProvider('catalogEntries')]
    public function test_entry_the_output_adds_is_invalid(string $entry, string $catalogEntries): void
    {
        $name = md5($entry);
        $input = PadesCatalogEntriesPdf::input(self::$directory, "{$name}-without", '');
        $output = PadesCatalogEntriesPdf::update(self::$directory, "{$name}-added", $input, $catalogEntries);

        $observation = self::observeDocument($output, $input);

        self::assertSame('invalid', $observation['verdict'], $observation['evidence']['summary']);
        self::assertStringContainsString((string) preg_replace('# (/\w+)$#', ' has a $1 entry', $entry), $observation['evidence']['summary']);
        self::assertSame([], $observation['evidence']['details']['inherited_entries']);
    }

    public static function changedEntries(): iterable
    {
        foreach (PadesCatalogEntriesPdf::ENTRIES as $entry => $catalogEntries) {
            yield "{$entry}, object it points to" => [$entry, $catalogEntries, 4];
            yield "{$entry}, object nested in its value" => [$entry, $catalogEntries, 6];
        }
        yield 'direct value with a reference to a changed object' => ['the catalog /DocMDP', '/DocMDP << /Signature 4 0 R >>', 4];
    }

    #[DataProvider('changedEntries')]
    public function test_entry_whose_value_changed_is_invalid(string $entry, string $catalogEntries, int $changedObject): void
    {
        $name = md5($entry.$catalogEntries.$changedObject);
        $input = PadesCatalogEntriesPdf::input(self::$directory, "{$name}-original", $catalogEntries);
        $output = PadesCatalogEntriesPdf::update(self::$directory, "{$name}-changed", $input, $catalogEntries, $changedObject);

        $observation = self::observeDocument($output, $input);

        self::assertSame('invalid', $observation['verdict'], $observation['evidence']['summary']);
        self::assertStringContainsString("{$entry} entry differs from the input's", $observation['evidence']['summary']);
    }

    public function test_input_that_qpdf_has_to_recover_is_inconclusive(): void
    {
        $listing = json_encode(self::listing(), JSON_THROW_ON_ERROR);
        $runner = new class($listing) implements CommandRunner
        {
            public function __construct(private readonly string $listing) {}

            public function run(array $command, float $timeoutSeconds, ?CommandAccess $access = null): CommandResult
            {
                return match (true) {
                    in_array('--version', $command, true) => new CommandResult(0, "qpdf version 11.3.0\n"),
                    end($command) === '/input.pdf' => new CommandResult(3, $this->listing),
                    default => new CommandResult(0, $this->listing),
                };
            }
        };
        $subject = new ValidationSubject(self::ATTEMPT_ID, self::ARTIFACT, self::$output, '/input.pdf');

        self::assertSame('inconclusive', (new PadesStructureValidator($runner, self::clock(), 2, true))->observe($subject)['verdict']);
        self::assertSame('inconclusive', (new DocumentTimestampImprintValidator($runner, self::clock()))->observe($subject)['verdict']);
    }

    public function test_recovered_listing_without_a_problem_is_inconclusive(): void
    {
        $runner = new FakeCommandRunner(new CommandResult(0, "qpdf version 11.3.0\n"), new CommandResult(3, json_encode(self::listing(), JSON_THROW_ON_ERROR)));

        self::assertSame('inconclusive', self::observe(new PadesStructureValidator($runner, self::clock(), 2, true))['verdict']);
    }

    public static function imprints(): iterable
    {
        yield 'digest of the covered bytes' => [null, 'valid'];
        yield 'digest of other bytes' => [static function (array $objects): array {
            $objects['obj:7 0 R']['value']['/Contents'] = 'b:3082'.bin2hex("\x04\x20".hash('sha256', 'other bytes', true));

            return $objects;
        }, 'invalid'];
        yield 'no document timestamp' => [static function (array $objects): array {
            unset($objects['obj:7 0 R']);

            return $objects;
        }, 'invalid'];
        yield 'range past the end of the file' => [static function (array $objects): array {
            $objects['obj:7 0 R']['value']['/ByteRange'][3] = 10_000;

            return $objects;
        }, 'inconclusive'];
    }

    #[DataProvider('imprints')]
    public function test_imprint_gives_the_verdict(?Closure $mutation, string $verdict): void
    {
        $runner = new FakeCommandRunner(new CommandResult(0, "qpdf version 11.3.0\n"), new CommandResult(0, json_encode(self::listing($mutation), JSON_THROW_ON_ERROR)));
        $observation = self::observe(new DocumentTimestampImprintValidator($runner, self::clock()));

        self::assertSame($verdict, $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame('timestamp', $observation['property']);
    }

    private static function observe(ToolValidator $validator): array
    {
        $observation = $validator->observe(new ValidationSubject(self::ATTEMPT_ID, self::ARTIFACT, self::$output));
        (new SchemaValidator)->assertValid($observation, ToolValidator::OBSERVATION_SCHEMA, 'observation');

        return $observation;
    }

    private static function observeDocument(string $output, string $input): array
    {
        $validator = new PadesStructureValidator(new ProcessRunner, self::clock(), null, false);
        $observation = $validator->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($output), $output, $input));
        (new SchemaValidator)->assertValid($observation, ToolValidator::OBSERVATION_SCHEMA, 'observation');

        return $observation;
    }

    private static function clock(): Closure
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-23T12:00:00Z');
    }
}
