<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use Closure;
use DateTimeImmutable;
use RuntimeException;

final class VisibleAppearanceValidator extends ComparisonValidator
{
    public const array DOCUMENT_ARGUMENTS = ['--json=2', '--json-key=qpdf', '--json-key=pages', '--no-warn'];

    public const array RENDER_ARGUMENTS = ['-r', '72', '-gray', '-singlefile'];

    private const float RECTANGLE_TOLERANCE_POINTS = 0.01;

    private const int PIXEL_MARGIN = 1;

    private const int HIDDEN_FLAGS = 2 | 32;

    /**
     * @param  Closure(): DateTimeImmutable  $clock
     * @param  array{page: int, left: int|float, top: int|float, width: int|float, height: int|float}  $placement
     */
    public function __construct(
        CommandRunner $runner,
        Closure $clock,
        private readonly array $placement,
        float $timeoutSeconds = 120.0,
    ) {
        parent::__construct($runner, $clock, $timeoutSeconds);
    }

    public function analyzerId(): string
    {
        return 'pdftoppm-appearance';
    }

    protected function property(): string
    {
        return 'visual';
    }

    protected function policy(): array
    {
        return [
            'id' => 'pdftoppm-visible-appearance',
            'description' => 'The signature added by the implementation has one widget, in the /Annots of the declared page, whose /Rect is the declared placement measured from the top-left corner of that page\'s /MediaBox before /Rotate, without the Hidden or NoView flag and with a normal appearance stream; rendered by pdftoppm, its page changes inside the field only and at least one pixel there, and every other page renders as in the input',
            'parameters' => [
                'placement' => $this->placement,
                'rectangle_tolerance_points' => self::RECTANGLE_TOLERANCE_POINTS,
                'document_arguments' => ['qpdf', ...self::DOCUMENT_ARGUMENTS],
                'render_arguments' => ['pdftoppm', ...self::RENDER_ARGUMENTS],
                'pixel_margin' => self::PIXEL_MARGIN,
            ],
        ];
    }

    protected function versionCommand(): array
    {
        return ['pdftoppm', '-v'];
    }

    protected function versionPattern(): string
    {
        return '/^pdftoppm version (\S+)$/m';
    }

    protected function compare(string $inputPath, string $outputPath): Judgement
    {
        $inputListing = SignatureListing::read($this->runner, $inputPath, $this->timeoutSeconds);
        if ($inputListing->result->timedOut) {
            return $this->timedOut($inputListing->result);
        }
        if ($inputListing->signatures === null) {
            return new Judgement('inconclusive', 'qpdf could not list the signatures of the input, so the added signature cannot be told apart: qpdf '.$inputListing->result->describe(), $inputListing->result->exitCode, $inputListing->result->output);
        }
        $outputListing = SignatureListing::read($this->runner, $outputPath, $this->timeoutSeconds);
        if ($outputListing->result->timedOut) {
            return $this->timedOut($outputListing->result);
        }
        if ($outputListing->signatures === null) {
            return new Judgement('inconclusive', 'qpdf could not list the signatures of the output: qpdf '.$outputListing->result->describe(), $outputListing->result->exitCode, $outputListing->result->output);
        }

        $inputKeys = $inputListing->keys();
        $added = array_values(array_filter($outputListing->signatures, static fn (array $signature): bool => ! isset($inputKeys[SignatureListing::key($signature)])));
        if ($added === []) {
            return $outputListing->isComplete()
                ? new Judgement('invalid', 'The output holds no signature that is not already in the input', $outputListing->result->exitCode, '')
                : new Judgement('inconclusive', 'No signature that is not already in the input was found in an output qpdf had to recover', $outputListing->result->exitCode, '');
        }
        if (count($added) > 1) {
            return new Judgement('invalid', 'The output adds '.count($added).' signatures, and the scenario adds one', $outputListing->result->exitCode, '', ['signatures' => array_map(self::signatureDetails(...), $added)]);
        }
        $signature = $added[0];
        $name = $signature['field'] ?? $signature['object'];
        $details = ['signatures' => [self::signatureDetails($signature)]];

        $inputDocument = $this->readDocument($inputPath);
        if ($inputDocument instanceof Judgement) {
            return $inputDocument;
        }
        $outputDocument = $this->readDocument($outputPath);
        if ($outputDocument instanceof Judgement) {
            return $outputDocument;
        }
        if ($inputDocument->pageCount() !== $outputDocument->pageCount()) {
            return new Judgement('invalid', "The output has {$outputDocument->pageCount()} pages and the input {$inputDocument->pageCount()}", 0, '', $details);
        }
        $page = $this->placement['page'];
        if ($page > $outputDocument->pageCount()) {
            return new Judgement('invalid', "The output has {$outputDocument->pageCount()} pages, so it has no page {$page}", 0, '', $details);
        }

        $widgets = $outputDocument->widgetsOf($signature['object']);
        if ($widgets === []) {
            $summary = $outputDocument->hasWidgetOutsidePages($signature['object'])
                ? "The widget of {$name} is not in the /Annots of any page"
                : "{$name} has no widget annotation";

            return new Judgement('invalid', $summary, 0, '', $details);
        }
        if (count($widgets) > 1) {
            return new Judgement('invalid', "{$name} has ".count($widgets).' widget annotations, and the scenario places one', 0, '', $details + ['widgets' => $widgets]);
        }
        $widget = $widgets[0];
        $details['widget'] = $widget;

        $mediaBox = $outputDocument->mediaBox($page);
        if ($mediaBox === null) {
            return new Judgement('inconclusive', "The /MediaBox of page {$page} of the output cannot be read", 0, '', $details);
        }
        $expected = [
            $mediaBox[0] + $this->placement['left'],
            $mediaBox[3] - $this->placement['top'] - $this->placement['height'],
            $mediaBox[0] + $this->placement['left'] + $this->placement['width'],
            $mediaBox[3] - $this->placement['top'],
        ];
        $details['expected_rectangle'] = $expected;

        $problem = match (true) {
            $widget['page'] !== $page => "The widget of {$name} is on page {$widget['page']}, not on page {$page}",
            $widget['rectangle'] === null => "The widget of {$name} has no /Rect of four numbers",
            ! self::sameRectangle($widget['rectangle'], $expected) => "The widget of {$name} is at ".self::format($widget['rectangle']).', not at '.self::format($expected),
            ($widget['flags'] & self::HIDDEN_FLAGS) !== 0 => "The widget of {$name} has the Hidden or NoView flag (/F {$widget['flags']})",
            $widget['appearance'] === null => "{$name} has no normal appearance stream (/AP /N)",
            default => null,
        };
        if ($problem !== null) {
            return new Judgement('invalid', $problem, 0, '', $details);
        }

        $fieldPixels = self::pixels($expected, $mediaBox, $outputDocument->rotation($page));
        $details['field_pixels'] = $fieldPixels;

        return $this->compareRenderings($inputPath, $outputPath, $outputDocument->pageCount(), $page, $fieldPixels, $name, $expected, $details);
    }

    private function readDocument(string $path): DocumentPages|Judgement
    {
        $result = $this->runner->run(['qpdf', ...self::DOCUMENT_ARGUMENTS, $path], $this->timeoutSeconds);
        if ($result->timedOut) {
            return $this->timedOut($result);
        }
        $document = in_array($result->exitCode, [0, 3], true) ? DocumentPages::fromJson($result->output) : null;

        return $document ?? new Judgement('inconclusive', 'qpdf could not read the pages of '.basename($path).': qpdf '.$result->describe(), $result->exitCode, $result->output);
    }

    /**
     * @param  list<int>  $fieldPixels
     * @param  list<int|float>  $expected
     */
    private function compareRenderings(string $inputPath, string $outputPath, int $pageCount, int $fieldPage, array $fieldPixels, string $name, array $expected, array $details): Judgement
    {
        $directory = sys_get_temp_dir().'/visible-appearance-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException("Unable to create the rendering directory {$directory}");
        }

        try {
            $changedInside = 0;
            for ($page = 1; $page <= $pageCount; $page++) {
                $inputImage = $this->render($inputPath, $page, $directory.'/input', 'input');
                if ($inputImage instanceof Judgement) {
                    return $inputImage;
                }
                $outputImage = $this->render($outputPath, $page, $directory.'/output', 'output');
                if ($outputImage instanceof Judgement) {
                    return $outputImage;
                }
                if ($inputImage->width !== $outputImage->width || $inputImage->height !== $outputImage->height) {
                    return new Judgement('invalid', "Page {$page} renders at {$outputImage->width} by {$outputImage->height} pixels, and at {$inputImage->width} by {$inputImage->height} in the input", 0, '', $details);
                }

                $field = $page === $fieldPage ? $fieldPixels : null;
                [$inside, $outside, $outsideBounds] = $inputImage->differences($outputImage, $field, self::PIXEL_MARGIN);
                if ($outside > 0) {
                    $where = $page === $fieldPage ? ' outside the field' : '';

                    return new Judgement('invalid', "Page {$page} renders differently from the input{$where}: {$outside} pixels changed within ".self::format($outsideBounds).' in pixels', 0, '', $details + ['changed_pixels_outside' => $outside, 'changed_page' => $page]);
                }
                $changedInside += $inside;
            }
        } finally {
            exec('rm -rf '.escapeshellarg($directory));
        }

        $details += ['compared_pages' => $pageCount, 'changed_pixels_inside' => $changedInside, 'changed_pixels_outside' => 0];
        if ($changedInside === 0) {
            return new Judgement('invalid', "The appearance of {$name} changes no pixel of page {$fieldPage} inside ".self::format($expected), 0, '', $details);
        }

        return new Judgement('valid', "{$name} has a widget on page {$fieldPage} at ".self::format($expected).' whose appearance draws only inside it, and every page renders as in the input elsewhere', 0, '', $details);
    }

    private function render(string $documentPath, int $page, string $prefix, string $role): Raster|Judgement
    {
        @unlink($prefix.'.pgm');
        $result = $this->runner->run(['pdftoppm', ...self::RENDER_ARGUMENTS, '-f', (string) $page, '-l', (string) $page, $documentPath, $prefix], $this->timeoutSeconds);
        if ($result->timedOut) {
            return $this->timedOut($result);
        }
        $raster = $result->exitCode === 0 ? Raster::fromPortableGraymap($prefix.'.pgm') : null;

        return $raster ?? new Judgement('inconclusive', "pdftoppm could not render page {$page} of the {$role}: pdftoppm ".$result->describe(), $result->exitCode, $result->output);
    }

    /**
     * @param  array{object: string, field: ?string, type: ?string, byte_range: ?list<int>, contents: ?string}  $signature
     * @return array{object: string, field: ?string}
     */
    private static function signatureDetails(array $signature): array
    {
        return ['object' => $signature['object'], 'field' => $signature['field']];
    }

    /**
     * @param  list<int|float>  $actual
     * @param  list<int|float>  $expected
     */
    private static function sameRectangle(array $actual, array $expected): bool
    {
        foreach ($expected as $index => $coordinate) {
            if (abs($actual[$index] - $coordinate) > self::RECTANGLE_TOLERANCE_POINTS) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<int|float>  $rectangle
     * @param  list<int|float>  $mediaBox
     * @return list<int>
     */
    private static function pixels(array $rectangle, array $mediaBox, int $rotation): array
    {
        $width = $mediaBox[2] - $mediaBox[0];
        $height = $mediaBox[3] - $mediaBox[1];
        $corners = [];
        foreach ([[$rectangle[0], $rectangle[1]], [$rectangle[2], $rectangle[3]]] as [$x, $y]) {
            $column = $x - $mediaBox[0];
            $row = $mediaBox[3] - $y;
            $corners[] = match ($rotation) {
                90 => [$height - $row, $column],
                180 => [$width - $column, $height - $row],
                270 => [$row, $width - $column],
                default => [$column, $row],
            };
        }

        return [
            (int) floor(min($corners[0][0], $corners[1][0])),
            (int) floor(min($corners[0][1], $corners[1][1])),
            (int) ceil(max($corners[0][0], $corners[1][0])),
            (int) ceil(max($corners[0][1], $corners[1][1])),
        ];
    }

    /**
     * @param  list<int|float>  $values
     */
    private static function format(array $values): string
    {
        return '['.implode(' ', array_map(static fn (int|float $value): string => is_float($value) && floor($value) !== $value ? rtrim(rtrim(sprintf('%.2f', $value), '0'), '.') : (string) (int) $value, $values)).']';
    }
}
