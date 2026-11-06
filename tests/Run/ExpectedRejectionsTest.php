<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Acquisition\Input;
use SignerPHP\PdfSigningQualification\Acquisition\Source;
use SignerPHP\PdfSigningQualification\Acquisition\SourceConfiguration;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Run\ExpectedRejections;

final class ExpectedRejectionsTest extends TestCase
{
    private const string VERSIONED_PATH = __DIR__.'/../../config/expected-rejections.json';

    private const string VERSIONED_INPUTS = __DIR__.'/../../config/inputs.json';

    private const string SCENARIO_DIRECTORY = __DIR__.'/../../config/scenarios';

    private const string SCENARIO_ID = 'basic-invisible-sha256';

    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'expected-rejections-');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    /**
     * @return list<Input>
     */
    private static function inputs(): array
    {
        $license = ['expression' => 'MIT', 'evidence_url' => 'https://example.org/license'];
        $source = new Source('local', 'https://example.org/repository', ['kind' => 'commit', 'value' => str_repeat('a', 40)], 'https://example.org/{revision}/{path}', $license);

        return [new Input($source, 'not-a-pdf.pdf', str_repeat('b', 64), 5, $license), new Input($source, 'good.pdf', str_repeat('c', 64), 5, $license)];
    }

    /**
     * @param  list<array{source_id: string, scenario_id: string, reason?: string}>  $rejections
     */
    private function load(array $rejections): ExpectedRejections
    {
        file_put_contents($this->path, json_encode([
            'schema' => ExpectedRejections::SCHEMA,
            'rejections' => array_map(static fn (array $rejection): array => $rejection + ['reason' => 'Not a PDF'], $rejections),
        ], JSON_THROW_ON_ERROR));

        return ExpectedRejections::fromFile($this->path, self::inputs(), self::SCENARIO_DIRECTORY);
    }

    public function test_only_the_declared_input_and_scenario_are_expected_rejections(): void
    {
        $expectedRejections = $this->load([['source_id' => 'local:not-a-pdf.pdf', 'scenario_id' => self::SCENARIO_ID]]);

        self::assertTrue($expectedRejections->declares('local:not-a-pdf.pdf', self::SCENARIO_ID));
        self::assertFalse($expectedRejections->declares('local:good.pdf', self::SCENARIO_ID));
        self::assertFalse($expectedRejections->declares('local:not-a-pdf.pdf', 'another-scenario'));
    }

    public function test_it_describes_the_file_it_read(): void
    {
        $expectedRejections = $this->load([]);

        self::assertSame(Artifact::describe($this->path), $expectedRejections->artifact);
    }

    /**
     * @return iterable<string, array{list<array{source_id: string, scenario_id: string}>, string}>
     */
    public static function invalidDeclarations(): iterable
    {
        yield 'undeclared input' => [[['source_id' => 'local:gone.pdf', 'scenario_id' => self::SCENARIO_ID]], 'refers to the undeclared input local:gone.pdf'];
        yield 'undeclared scenario' => [[['source_id' => 'local:not-a-pdf.pdf', 'scenario_id' => 'unknown-scenario']], 'undeclared scenario unknown-scenario'];
        yield 'repeated declaration' => [[
            ['source_id' => 'local:not-a-pdf.pdf', 'scenario_id' => self::SCENARIO_ID],
            ['source_id' => 'local:not-a-pdf.pdf', 'scenario_id' => self::SCENARIO_ID],
        ], 'more than once'];
    }

    /**
     * @param  list<array{source_id: string, scenario_id: string}>  $rejections
     */
    #[DataProvider('invalidDeclarations')]
    public function test_invalid_declaration_is_refused(array $rejections, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->load($rejections);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentsOutsideTheSchema(): iterable
    {
        yield 'without rejections' => [json_encode(['schema' => ExpectedRejections::SCHEMA], JSON_THROW_ON_ERROR)];
        yield 'rejections as an object' => ['{"schema": "'.ExpectedRejections::SCHEMA.'", "rejections": {}}'];
        yield 'a list' => ['[]'];
        yield 'a string' => ['"text"'];
        yield 'null' => ['null'];
    }

    #[DataProvider('documentsOutsideTheSchema')]
    public function test_file_that_does_not_follow_its_schema_is_refused(string $document): void
    {
        file_put_contents($this->path, $document);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('do not follow their schema');

        ExpectedRejections::fromFile($this->path, self::inputs(), self::SCENARIO_DIRECTORY);
    }

    public function test_file_that_is_not_json_is_refused_by_its_path(): void
    {
        file_put_contents($this->path, '{');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($this->path);

        ExpectedRejections::fromFile($this->path, self::inputs(), self::SCENARIO_DIRECTORY);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidScenarios(): iterable
    {
        yield 'truncated' => ['{"id": "tru'];
        yield 'without the fields of a scenario' => ['{"id": "basic-invisible-sha256"}'];
    }

    #[DataProvider('invalidScenarios')]
    public function test_invalid_scenario_is_refused_by_its_path(string $scenario): void
    {
        $scenarioDirectory = $this->path.'-scenarios';
        mkdir($scenarioDirectory);
        file_put_contents($scenarioDirectory.'/broken.json', $scenario);
        file_put_contents($this->path, json_encode(['schema' => ExpectedRejections::SCHEMA, 'rejections' => []], JSON_THROW_ON_ERROR));

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage($scenarioDirectory.'/broken.json');

            ExpectedRejections::fromFile($this->path, self::inputs(), $scenarioDirectory);
        } finally {
            unlink($scenarioDirectory.'/broken.json');
            rmdir($scenarioDirectory);
        }
    }

    public function test_versioned_configuration_declares_only_the_file_that_is_not_a_pdf(): void
    {
        $expectedRejections = ExpectedRejections::fromFile(self::VERSIONED_PATH, SourceConfiguration::fromFile(self::VERSIONED_INPUTS), self::SCENARIO_DIRECTORY);
        $declaredSourceIds = array_values(array_filter(
            array_map(static fn (Input $input): string => $input->id(), SourceConfiguration::fromFile(self::VERSIONED_INPUTS)),
            static fn (string $sourceId): bool => $expectedRejections->declares($sourceId, self::SCENARIO_ID),
        ));

        self::assertSame(['qpdf:qpdf/qtest/qpdf/bad1.pdf'], $declaredSourceIds);
    }
}
