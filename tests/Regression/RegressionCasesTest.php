<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Regression;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Regression\RegressionCases;
use SignerPHP\PdfSigningQualification\Regression\RegressionConditions;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;

final class RegressionCasesTest extends TestCase
{
    private const string CASE_DIRECTORY = __DIR__.'/../../config/regressions';

    private const string SCENARIO_DIRECTORY = __DIR__.'/../../config/scenarios';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/regression-cases-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    private function writeCase(string $fileName, array $changes): void
    {
        $case = json_decode((string) file_get_contents(self::CASE_DIRECTORY.'/hybrid-xref.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($case);
        file_put_contents($this->directory.'/'.$fileName, json_encode(array_merge($case, $changes), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function test_every_versioned_case_is_loaded_and_has_a_condition_check(): void
    {
        $cases = RegressionCases::fromDirectory(self::CASE_DIRECTORY, self::SCENARIO_DIRECTORY);

        self::assertSame(['hybrid-xref'], array_map(static fn ($case): string => $case->id, $cases));
        self::assertSame(['sha256' => hash_file('sha256', self::CASE_DIRECTORY.'/hybrid-xref.json'), 'byte_size' => filesize(self::CASE_DIRECTORY.'/hybrid-xref.json')], $cases[0]->definition);
        foreach ($cases as $case) {
            (new RegressionConditions(new ProcessRunner))->for($case->id);
        }
    }

    public function test_case_whose_file_is_not_named_after_its_id_is_refused(): void
    {
        $this->writeCase('other-name.json', []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has the id hybrid-xref; name its file hybrid-xref.json');

        RegressionCases::fromDirectory($this->directory, self::SCENARIO_DIRECTORY);
    }

    public function test_case_of_an_undeclared_scenario_is_refused(): void
    {
        $this->writeCase('hybrid-xref.json', ['scenario_id' => 'unknown-scenario']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The regression case hybrid-xref refers to the undeclared scenario unknown-scenario');

        RegressionCases::fromDirectory($this->directory, self::SCENARIO_DIRECTORY);
    }

    public function test_case_that_does_not_follow_its_schema_is_refused(): void
    {
        $this->writeCase('hybrid-xref.json', ['condition' => '']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not follow its schema');

        RegressionCases::fromDirectory($this->directory, self::SCENARIO_DIRECTORY);
    }
}
