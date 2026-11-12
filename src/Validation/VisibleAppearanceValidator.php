<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use Closure;
use DateTimeImmutable;
use RuntimeException;

final class VisibleAppearanceValidator extends ComparisonValidator
{
    public const array DOCUMENT_ARGUMENTS = ['--json=2', '--json-key=qpdf', '--json-key=pages', '--no-warn'];

    public const array RENDER_ARGUMENTS = ['-r', '72'];

    private const float RECTANGLE_TOLERANCE_POINTS = 0.01;

    private const int PIXEL_MARGIN = 1;

    private const int MAXIMUM_PAGE_PIXELS = 40_000_000;

    private const int RENDERED_PIXELS_PER_CALL = 40_000_000;

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
            'description' => 'The signature added by the implementation has one widget, in the /Annots of the declared page, whose /Rect is the declared placement measured from the top-left corner of that page\'s /MediaBox before /Rotate, without the Hidden or NoView flag and with a normal appearance stream; rendered in colour by pdftoppm, its page changes inside the field only and at least one pixel there, and every other page renders as in the input; a page above the pixel limit is not rendered',
            'parameters' => [
                'placement' => $this->placement,
                'rectangle_tolerance_points' => self::RECTANGLE_TOLERANCE_POINTS,
                'document_arguments' => ['qpdf', ...self::DOCUMENT_ARGUMENTS],
                'render_arguments' => ['pdftoppm', ...self::RENDER_ARGUMENTS],
                'pixel_margin' => self::PIXEL_MARGIN,
                'maximum_page_pixels' => self::MAXIMUM_PAGE_PIXELS,
                'rendering_seconds' => $this->timeoutSeconds,
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
        $inputResult = $this->runner->run(['qpdf', ...self::DOCUMENT_ARGUMENTS, $inputPath], $this->timeoutSeconds);
        if ($inputResult->timedOut) {
            return $this->timedOut($inputResult);
        }
        $inputListing = SignatureListing::fromResult($inputResult);
        if ($inputListing->signatures === null) {
            return new Judgement('inconclusive', 'qpdf could not list the signatures of the input, so the added signature cannot be told apart: qpdf '.$inputResult->describe(), $inputResult->exitCode, $inputResult->output);
        }
        $outputResult = $this->runner->run(['qpdf', ...self::DOCUMENT_ARGUMENTS, $outputPath], $this->timeoutSeconds);
        if ($outputResult->timedOut) {
            return $this->timedOut($outputResult);
        }
        $outputListing = SignatureListing::fromResult($outputResult);
        if ($outputListing->signatures === null) {
            return new Judgement('inconclusive', 'qpdf could not list the signatures of the output: qpdf '.$outputResult->describe(), $outputResult->exitCode, $outputResult->output);
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

        $inputDocument = DocumentPages::fromJson($inputResult->output);
        if ($inputDocument === null) {
            return new Judgement('inconclusive', 'qpdf could not read the pages of the input', $inputResult->exitCode, $inputResult->output, $details);
        }
        $outputDocument = DocumentPages::fromJson($outputResult->output);
        if ($outputDocument === null) {
            return new Judgement('inconclusive', 'qpdf could not read the pages of the output', $outputResult->exitCode, $outputResult->output, $details);
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
        $details['need_appearances'] = $outputDocument->needAppearances();

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

        return $this->compareRenderings($inputPath, $outputPath, $inputDocument, $outputDocument, $page, $fieldPixels, $name, $expected, $details);
    }

    /**
     * @param  list<int>  $fieldPixels
     * @param  list<int|float>  $expected
     */
    private function compareRenderings(string $inputPath, string $outputPath, DocumentPages $inputDocument, DocumentPages $outputDocument, int $fieldPage, array $fieldPixels, string $name, array $expected, array $details): Judgement
    {
        $pageCount = $outputDocument->pageCount();
        $pagePixels = [];
        for ($page = 1; $page <= $pageCount; $page++) {
            $pixels = 0;
            foreach (['input' => $inputDocument, 'output' => $outputDocument] as $role => $document) {
                $mediaBox = $document->mediaBox($page);
                if ($mediaBox === null) {
                    return new Judgement('inconclusive', "The /MediaBox of page {$page} of the {$role} cannot be read", 0, '', $details);
                }
                $width = $mediaBox[2] - $mediaBox[0];
                $height = $mediaBox[3] - $mediaBox[1];
                if (ceil($width) * ceil($height) > self::MAXIMUM_PAGE_PIXELS) {
                    return new Judgement('inconclusive', "Page {$page} is ".self::number($width).' by '.self::number($height).' points, and the analyzer renders no page above '.self::MAXIMUM_PAGE_PIXELS.' pixels at 72 dpi', 0, '', $details);
                }
                $pixels = max($pixels, (int) (ceil($width) * ceil($height)));
            }
            $pagePixels[$page] = $pixels;
        }

        $directory = sys_get_temp_dir().'/visible-appearance-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException("Unable to create the rendering directory {$directory}");
        }

        $deadline = hrtime(true) + (int) ($this->timeoutSeconds * 1e9);
        try {
            $changedInside = 0;
            foreach (self::chunks($pagePixels) as [$firstPage, $lastPage]) {
                $images = [];
                foreach (['input' => $inputPath, 'output' => $outputPath] as $role => $documentPath) {
                    $rendered = $this->render($documentPath, $firstPage, $lastPage, "{$directory}/{$role}", $role, $deadline);
                    if ($rendered instanceof Judgement) {
                        return $rendered;
                    }
                    $images[$role] = $rendered;
                }

                for ($page = $firstPage; $page <= $lastPage; $page++) {
                    $inputImage = $images['input'][$page];
                    $outputImage = $images['output'][$page];
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
                exec('rm -f '.escapeshellarg($directory).'/*');
            }
        } finally {
            exec('rm -rf '.escapeshellarg($directory));
        }

        $details += ['compared_pages' => $pageCount, 'changed_pixels_inside' => $changedInside, 'changed_pixels_outside' => 0];
        if ($changedInside === 0) {
            $reason = $details['need_appearances'] === true ? '; the form\'s /NeedAppearances is true, so poppler builds the appearance of the field instead of drawing its /AP' : '';

            return new Judgement('invalid', "The appearance of {$name} changes no pixel of page {$fieldPage} inside ".self::format($expected).$reason, 0, '', $details);
        }

        return new Judgement('valid', "{$name} has a widget on page {$fieldPage} at ".self::format($expected).' whose appearance draws only inside it, and every page renders as in the input elsewhere', 0, '', $details);
    }

    /**
     * @param  array<int, int>  $pagePixels
     * @return list<array{int, int}>
     */
    private static function chunks(array $pagePixels): array
    {
        $chunks = [];
        $first = null;
        $last = null;
        $pixels = 0;
        foreach ($pagePixels as $page => $pagePixelCount) {
            if ($first !== null && $pixels + $pagePixelCount > self::RENDERED_PIXELS_PER_CALL) {
                $chunks[] = [$first, (int) $last];
                $first = null;
                $pixels = 0;
            }
            $first ??= $page;
            $last = $page;
            $pixels += $pagePixelCount;
        }
        if ($first !== null) {
            $chunks[] = [$first, (int) $last];
        }

        return $chunks;
    }

    /**
     * @return array<int, Raster>|Judgement
     */
    private function render(string $documentPath, int $firstPage, int $lastPage, string $prefix, string $role, int $deadline): array|Judgement
    {
        $pages = $firstPage === $lastPage ? "page {$firstPage}" : "pages {$firstPage} to {$lastPage}";
        $remainingSeconds = ($deadline - hrtime(true)) / 1e9;
        $result = $remainingSeconds > 0
            ? $this->runner->run(['pdftoppm', ...self::RENDER_ARGUMENTS, '-f', (string) $firstPage, '-l', (string) $lastPage, $documentPath, $prefix], $remainingSeconds)
            : new CommandResult(null, '', true);
        if ($result->timedOut) {
            return $this->timedOut($result);
        }
        if ($result->exitCode !== 0) {
            return new Judgement('inconclusive', "pdftoppm could not render {$pages} of the {$role}: pdftoppm ".$result->describe(), $result->exitCode, $result->output);
        }

        $paths = [];
        foreach (glob($prefix.'-*.ppm') ?: [] as $path) {
            if (preg_match('/-(\d+)\.ppm$/', $path, $match) === 1) {
                $paths[(int) $match[1]] = $path;
            }
        }
        $images = [];
        for ($page = $firstPage; $page <= $lastPage; $page++) {
            $image = isset($paths[$page]) ? Raster::open($paths[$page]) : null;
            if ($image === null) {
                return new Judgement('inconclusive', "pdftoppm wrote no image of page {$page} of the {$role} that the analyzer can read", $result->exitCode, $result->output);
            }
            if ($image->width * $image->height > self::MAXIMUM_PAGE_PIXELS) {
                return new Judgement('inconclusive', "Page {$page} of the {$role} renders at {$image->width} by {$image->height} pixels, and the analyzer compares no page above ".self::MAXIMUM_PAGE_PIXELS.' pixels', $result->exitCode, $result->output);
            }
            $images[$page] = $image;
        }

        return $images;
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
        return '['.implode(' ', array_map(self::number(...), $values)).']';
    }

    private static function number(int|float $value): string
    {
        return is_float($value) && floor($value) !== $value ? rtrim(rtrim(sprintf('%.2f', $value), '0'), '.') : (string) (int) $value;
    }
}
