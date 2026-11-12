<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\ToolValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;
use SignerPHP\PdfSigningQualification\Validation\VisibleAppearanceValidator;

final class VisibleAppearanceValidatorTest extends TestCase
{
    private const string ATTEMPT_ID = 'run-20261112T120000Z/basic-visible-sha256/synthetic-signer/synthetic:visible-input.pdf';

    private const array PLACEMENT = ['page' => 1, 'left' => 36, 'top' => 36, 'width' => 240, 'height' => 84];

    private static string $directory;

    private static string $inputPath;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/visible-appearance-'.bin2hex(random_bytes(6));
        mkdir(self::$directory);
        self::$inputPath = SyntheticVisibleSignature::input(self::$directory);
    }

    public static function tearDownAfterClass(): void
    {
        exec('rm -rf '.escapeshellarg(self::$directory));
    }

    private static function validator(?CommandRunner $runner = null): VisibleAppearanceValidator
    {
        return new VisibleAppearanceValidator($runner ?? new ProcessRunner, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-12T12:00:03Z'), self::PLACEMENT);
    }

    private static function observe(string $outputPath, ?string $inputPath = null, ?CommandRunner $runner = null): array
    {
        $inputPath ??= self::$inputPath;
        $observation = self::validator($runner)->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($inputPath), $outputPath, $inputPath));
        self::assertSame([], (new SchemaValidator)->errors($observation, ToolValidator::OBSERVATION_SCHEMA));

        return $observation;
    }

    public function test_visible_signature_at_the_declared_place_is_valid(): void
    {
        $observation = self::observe(SyntheticVisibleSignature::sign(self::$inputPath, self::$directory, 'visible'));

        self::assertSame('valid', $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame(self::ATTEMPT_ID.'/pdftoppm-appearance', $observation['id']);
        self::assertSame('visual', $observation['property']);
        self::assertSame(['id' => 'pdftoppm-appearance', 'revision' => ['kind' => 'version', 'value' => '22.12.0']], $observation['analyzer']);
        self::assertSame('pdftoppm-visible-appearance', $observation['policy']['id']);
        self::assertSame(self::PLACEMENT, $observation['policy']['parameters']['placement']);
        self::assertSame('Signature1 has a widget on page 1 at [36 672 276 756] whose appearance draws only inside it, and every page renders as in the input elsewhere', $observation['evidence']['summary']);

        $details = $observation['evidence']['details'];
        self::assertSame([['object' => '21 0 R', 'field' => 'Signature1']], $details['signatures']);
        self::assertSame(['object' => '20 0 R', 'page' => 1, 'rectangle' => [36, 672, 276, 756], 'flags' => 132, 'appearance' => '22 0 R'], $details['widget']);
        self::assertSame([36, 672, 276, 756], $details['expected_rectangle']);
        self::assertSame(2, $details['compared_pages']);
        self::assertGreaterThan(0, $details['changed_pixels_inside']);
        self::assertSame(0, $details['changed_pixels_outside']);
        self::assertFalse($details['need_appearances']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function defectiveAppearances(): iterable
    {
        yield 'appearance missing' => [['appearance' => 'missing'], 'Signature1 has no normal appearance stream (/AP /N)'];
        yield 'appearance states instead of a stream' => [['appearance' => 'states'], 'Signature1 has no normal appearance stream (/AP /N)'];
        yield 'appearance that draws nothing' => [['appearance' => 'empty'], 'The appearance of Signature1 changes no pixel of page 1 inside [36 672 276 756]'];
        yield 'widget on another page' => [['page' => 2], 'The widget of Signature1 is on page 2, not on page 1'];
        yield 'widget at another place' => [['rectangle' => [36, 36, 276, 120]], 'The widget of Signature1 is at [36 36 276 120], not at [36 672 276 756]'];
        yield 'hidden widget' => [['flags' => 134], 'The widget of Signature1 has the Hidden or NoView flag (/F 134)'];
        yield 'widget in no page' => [['annotated' => false], 'The widget of Signature1 is not in the /Annots of any page'];
        yield 'another page changed' => [['changed_page' => 2], 'Page 2 renders differently from the input: 10000 pixels changed within [400 592 499 691] in pixels'];
        yield 'page changed outside the field' => [['changed_page' => 1], 'Page 1 renders differently from the input outside the field: 10000 pixels changed within [400 592 499 691] in pixels'];
        yield 'colour changed to a gray of the same luminance' => [['recoloured' => true], 'Page 2 renders differently from the input: 10000 pixels changed within [72 192 171 291] in pixels'];
        yield 'appearance built by the reader' => [['appearance' => 'empty', 'need_appearances' => true], 'The appearance of Signature1 changes no pixel of page 1 inside [36 672 276 756]; the form\'s /NeedAppearances is true, so poppler builds the appearance of the field instead of drawing its /AP'];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('defectiveAppearances')]
    public function test_defective_appearance_is_invalid(array $options, string $summary): void
    {
        $observation = self::observe(SyntheticVisibleSignature::sign(self::$inputPath, self::$directory, 'defective-'.md5(serialize($options)), $options));

        self::assertSame('invalid', $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame($summary, $observation['evidence']['summary']);
    }

    public function test_output_without_an_added_signature_is_invalid(): void
    {
        $observation = self::observe(self::$inputPath);

        self::assertSame('invalid', $observation['verdict']);
        self::assertSame('The output holds no signature that is not already in the input', $observation['evidence']['summary']);
    }

    public function test_field_is_placed_from_the_top_left_corner_of_the_media_box(): void
    {
        $entries = '/MediaBox [100 100 712 892]';
        $inputPath = SyntheticVisibleSignature::input(self::$directory, 'offset-input', $entries);

        $atOrigin = self::observe(SyntheticVisibleSignature::sign($inputPath, self::$directory, 'offset-at-origin', ['first_page_entries' => $entries]), $inputPath);
        $atMediaBox = self::observe(SyntheticVisibleSignature::sign($inputPath, self::$directory, 'offset-at-media-box', ['first_page_entries' => $entries, 'rectangle' => [136, 772, 376, 856]]), $inputPath);

        self::assertSame('The widget of Signature1 is at [36 672 276 756], not at [136 772 376 856]', $atOrigin['evidence']['summary']);
        self::assertSame('valid', $atMediaBox['verdict'], $atMediaBox['evidence']['summary']);
    }

    public function test_rotated_page_is_compared_where_the_rotation_renders_the_field(): void
    {
        $entries = '/MediaBox [0 0 612 792] /Rotate 90';
        $inputPath = SyntheticVisibleSignature::input(self::$directory, 'rotated-input', $entries);

        $observation = self::observe(SyntheticVisibleSignature::sign($inputPath, self::$directory, 'rotated', ['first_page_entries' => $entries]), $inputPath);

        self::assertSame('valid', $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame([672, 36, 756, 276], $observation['evidence']['details']['field_pixels']);
    }

    public function test_rectangle_given_from_its_upper_corner_is_normalized(): void
    {
        $observation = self::observe(SyntheticVisibleSignature::sign(self::$inputPath, self::$directory, 'upper-corner-first', ['rectangle' => [276, 756, 36, 672]]));

        self::assertSame('valid', $observation['verdict'], $observation['evidence']['summary']);
    }

    public function test_output_qpdf_cannot_read_is_inconclusive(): void
    {
        $outputPath = self::$directory.'/not-a-pdf.pdf';
        file_put_contents($outputPath, 'not a PDF');

        $observation = self::observe($outputPath);

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertStringStartsWith('qpdf could not list the signatures of the output', $observation['evidence']['summary']);
    }

    public function test_page_pdftoppm_cannot_render_is_inconclusive(): void
    {
        $outputPath = SyntheticVisibleSignature::sign(self::$inputPath, self::$directory, 'render-failure');
        $runner = new class implements CommandRunner
        {
            public function run(array $command, float $timeoutSeconds): CommandResult
            {
                return $command[0] === 'pdftoppm' && ! in_array('-v', $command, true)
                    ? new CommandResult(99, 'Syntax Error: broken page')
                    : (new ProcessRunner)->run($command, $timeoutSeconds);
            }
        };

        $observation = self::observe($outputPath, runner: $runner);

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('pdftoppm could not render pages 1 to 2 of the input: pdftoppm exited with code 99', $observation['evidence']['summary']);
    }

    public function test_rendering_that_does_not_finish_is_inconclusive(): void
    {
        $outputPath = SyntheticVisibleSignature::sign(self::$inputPath, self::$directory, 'render-timeout');
        $runner = new class implements CommandRunner
        {
            public function run(array $command, float $timeoutSeconds): CommandResult
            {
                return $command[0] === 'pdftoppm' && ! in_array('-v', $command, true)
                    ? new CommandResult(null, '', true)
                    : (new ProcessRunner)->run($command, $timeoutSeconds);
            }
        };

        $observation = self::observe($outputPath, runner: $runner);

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('pdftoppm-appearance did not finish within 120 seconds', $observation['evidence']['summary']);
    }

    public function test_each_document_is_read_once_by_qpdf_and_rendered_in_one_call(): void
    {
        $outputPath = SyntheticVisibleSignature::sign(self::$inputPath, self::$directory, 'counted');
        $runner = new class implements CommandRunner
        {
            /** @var list<array{string, float}> */
            public array $commands = [];

            public function run(array $command, float $timeoutSeconds): CommandResult
            {
                if (! in_array('-v', $command, true)) {
                    $this->commands[] = [$command[0].' '.basename((string) end($command)), $timeoutSeconds];
                }

                return (new ProcessRunner)->run($command, $timeoutSeconds);
            }
        };

        $observation = self::observe($outputPath, runner: $runner);

        self::assertSame('valid', $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame(['qpdf visible-input.pdf', 'qpdf counted.pdf', 'pdftoppm input', 'pdftoppm output'], array_column($runner->commands, 0));
        self::assertLessThan(120.0, $runner->commands[3][1]);
    }

    public function test_page_too_large_to_render_is_inconclusive_without_rendering_it(): void
    {
        $entries = '/MediaBox [0 0 12000 12000]';
        $inputPath = SyntheticVisibleSignature::input(self::$directory, 'poster-input', $entries);
        $outputPath = SyntheticVisibleSignature::sign($inputPath, self::$directory, 'poster', ['first_page_entries' => $entries, 'rectangle' => [36, 11880, 276, 11964]]);

        $observation = self::observe($outputPath, $inputPath);

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('Page 1 is 12000 by 12000 points, and the analyzer renders no page above 40000000 pixels at 72 dpi', $observation['evidence']['summary']);
    }

    public function test_attempt_without_output_is_not_evaluated(): void
    {
        $observation = self::validator()->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe(self::$inputPath), null, self::$inputPath));

        self::assertSame('not_evaluated', $observation['verdict']);
    }
}
