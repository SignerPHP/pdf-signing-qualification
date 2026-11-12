<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Signing;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\Scenarios;

final class ScenariosTest extends TestCase
{
    private const string SCENARIO_DIRECTORY = __DIR__.'/../../config/scenarios';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/scenarios-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    private function write(string $name, array $scenario): void
    {
        file_put_contents($this->directory.'/'.$name.'.json', json_encode($scenario, JSON_THROW_ON_ERROR));
    }

    private static function versioned(string $id): array
    {
        $scenario = json_decode((string) file_get_contents(self::SCENARIO_DIRECTORY.'/'.$id.'.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($scenario);

        return $scenario;
    }

    public function test_every_versioned_scenario_follows_the_schema_it_names(): void
    {
        $ids = Scenarios::ids(self::SCENARIO_DIRECTORY);

        self::assertSame(['basic-invisible-sha256', 'basic-visible-sha256'], $ids);
        foreach ($ids as $id) {
            self::assertSame([], Scenarios::errors(self::versioned($id)), $id);
        }
    }

    public function test_visible_scenario_places_the_field_where_pdf_signer_places_its_default_appearance(): void
    {
        $scenario = Scenarios::load(self::SCENARIO_DIRECTORY, 'basic-visible-sha256');

        self::assertSame(SchemaValidator::SCHEMA_PREFIX.'v2/scenario.schema.json', $scenario['schema']);
        self::assertSame('visible', $scenario['signing']['appearance']);
        self::assertSame(['page' => 1, 'left' => 36, 'top' => 36, 'width' => 240, 'height' => 84], $scenario['signing']['placement']);
        self::assertSame(array_diff_key(self::versioned('basic-invisible-sha256')['signing'], ['appearance' => true]), array_diff_key($scenario['signing'], ['appearance' => true, 'placement' => true]));
    }

    public function test_scenario_of_version_1_is_still_read(): void
    {
        $scenario = Scenarios::load(self::SCENARIO_DIRECTORY, 'basic-invisible-sha256');

        self::assertSame(SchemaValidator::SCHEMA_PREFIX.'v1/scenario.schema.json', $scenario['schema']);
    }

    public function test_scenario_naming_an_unknown_schema_is_refused(): void
    {
        $scenario = self::versioned('basic-invisible-sha256');
        $scenario['schema'] = SchemaValidator::SCHEMA_PREFIX.'v3/scenario.schema.json';

        self::assertNotSame([], Scenarios::errors($scenario));
    }

    public function test_scenario_that_does_not_follow_the_schema_it_names_is_refused(): void
    {
        $scenario = self::versioned('basic-visible-sha256');
        unset($scenario['signing']['placement']);
        $this->write('basic-visible-sha256', $scenario);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($this->directory.'/basic-visible-sha256.json');

        Scenarios::load($this->directory, 'basic-visible-sha256');
    }

    public function test_scenario_whose_id_is_not_its_file_name_is_refused(): void
    {
        $this->write('renamed', self::versioned('basic-invisible-sha256'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has the id basic-invisible-sha256');

        Scenarios::ids($this->directory);
    }

    public function test_undeclared_scenario_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The scenario missing is not declared in '.$this->directory);

        Scenarios::load($this->directory, 'missing');
    }

    public function test_scenario_id_that_is_not_an_identifier_is_refused_before_reading_a_file(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('../basic-invisible-sha256 is not a scenario identifier');

        Scenarios::load(self::SCENARIO_DIRECTORY.'/../scenarios', '../basic-invisible-sha256');
    }
}
