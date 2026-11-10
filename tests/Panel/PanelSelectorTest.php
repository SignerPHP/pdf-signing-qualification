<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Panel;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\SourceConfiguration;
use SignerPHP\PdfSigningQualification\Panel\PanelSelector;
use SignerPHP\PdfSigningQualification\Panel\SelectionPlan;
use SignerPHP\PdfSigningQualification\Panel\StrataClassifier;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Tests\Acquisition\InMemoryFetcher;
use SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic\SyntheticPdf;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;

final class PanelSelectorTest extends TestCase
{
    private const string REVISION = '0123456789abcdef0123456789abcdef01234567';

    private const string LISTING_URL = 'https://example.test/tree/'.self::REVISION;

    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir().'/panel-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDirectory)) {
            exec('rm -rf '.escapeshellarg($this->cacheDirectory));
        }
    }

    private static function plan(array $changes = []): array
    {
        return array_replace_recursive([
            'schema' => SelectionPlan::SCHEMA,
            'seed' => 'test',
            'quota_per_stratum' => 1,
            'large' => ['byte_size' => 10_000_000, 'page_count' => 1000],
            'sources' => [[
                'id' => 'fixture',
                'url' => 'https://example.test/fixture',
                'revision' => ['kind' => 'commit', 'value' => self::REVISION],
                'file_url' => 'https://example.test/raw/{revision}/{path}',
                'license' => ['expression' => 'MIT', 'evidence_url' => 'https://example.test/LICENSE'],
                'listing_url' => 'https://example.test/tree/{revision}',
                'document_license' => ['expression' => 'MIT', 'evidence_url' => 'https://example.test/README'],
                'include' => ['tests/*.pdf'],
                'exclude' => [['pattern' => '*/reported-*', 'reason' => 'Attached to a bug report']],
            ]],
            'rejected_sources' => [['url' => 'https://example.test/other', 'reason' => 'No license']],
        ], $changes);
    }

    /**
     * @return array<string, string>
     */
    private static function files(): array
    {
        return [
            'tests/classic-a.pdf' => SyntheticPdf::classic(SyntheticPdf::onePage()),
            'tests/classic-b.pdf' => SyntheticPdf::classic(SyntheticPdf::onePage(), '1.5'),
            'tests/legacy.pdf' => SyntheticPdf::classic(SyntheticPdf::onePage(), '1.1'),
            'tests/reported-1.pdf' => SyntheticPdf::classic(SyntheticPdf::onePage(), '1.2'),
            'tests/notes.txt' => 'not a candidate',
            'tests/enc-R2,V1.pdf' => SyntheticPdf::classic(SyntheticPdf::onePage(), '1.0'),
            'tests/link.pdf' => 'classic-a.pdf',
            'tests/crashes-qpdf.pdf' => SyntheticPdf::classic(SyntheticPdf::onePage('/Crash true')),
            'tests/without-strata.pdf' => SyntheticPdf::classic(SyntheticPdf::onePage() + [4 => '<< /Type /XRef >>']),
            'docs/manual.pdf' => SyntheticPdf::classic(SyntheticPdf::onePage()),
        ];
    }

    /**
     * @param  array<string, string>  $files
     */
    private static function fetcher(array $files, array $listingChanges = [], array $corruptedPaths = []): InMemoryFetcher
    {
        $tree = [['path' => 'tests', 'type' => 'tree', 'sha' => str_repeat('0', 40)]];
        $responses = [];
        foreach ($files as $path => $content) {
            $mode = $path === 'tests/link.pdf' ? '120000' : '100644';
            $tree[] = ['path' => $path, 'mode' => $mode, 'type' => 'blob', 'sha' => sha1('blob '.strlen($content)."\0".$content), 'size' => strlen($content)];
            $responses['https://example.test/raw/'.self::REVISION.'/'.$path] = in_array($path, $corruptedPaths, true) ? $content.' ' : $content;
        }
        $responses[self::LISTING_URL] = (string) json_encode(['tree' => $tree, 'truncated' => false, ...$listingChanges]);

        return new InMemoryFetcher($responses);
    }

    private function select(InMemoryFetcher $fetcher, array $plan): array
    {
        $runner = new class implements CommandRunner
        {
            public function run(array $command, float $timeoutSeconds): CommandResult
            {
                if (str_contains((string) file_get_contents((string) end($command)), '/Crash true')) {
                    return new CommandResult(null, '', terminationSignal: 11);
                }

                return (new ProcessRunner)->run($command, $timeoutSeconds);
            }
        };
        $selector = new PanelSelector($fetcher, new StrataClassifier($runner, 10_000_000, 1000), $this->cacheDirectory, static function (string $message): void {});

        return $selector->select(SelectionPlan::fromJson((string) json_encode($plan)), '11.3.0');
    }

    public function test_selection_draws_the_quota_from_included_candidates_and_records_the_pool(): void
    {
        $selection = $this->select(self::fetcher(self::files()), self::plan());

        $inputs = SourceConfiguration::fromArray($selection['inputs']);
        self::assertSame('stratified', $selection['inputs']['selection']);
        self::assertContains('fixture:tests/legacy.pdf', array_map(static fn ($input): string => $input->id(), $inputs));
        self::assertGreaterThanOrEqual(1, count($inputs));
        self::assertLessThanOrEqual(2, count($inputs));
        foreach ($inputs as $input) {
            self::assertSame(['expression' => 'MIT', 'evidence_url' => 'https://example.test/README'], $input->license);
            self::assertSame(hash('sha256', self::files()[$input->path]), $input->sha256);
            self::assertSame(strlen(self::files()[$input->path]), $input->byteSize);
        }

        $pool = $selection['pool'];
        self::assertSame([], (new SchemaValidator)->errors($pool, PanelSelector::POOL_SCHEMA));
        self::assertSame(3, $pool['candidates']);
        self::assertSame(count($inputs), $pool['selected']);
        self::assertSame([['id' => 'fixture', 'candidates' => 3, 'selected' => count($inputs), 'excluded' => [['pattern' => '*/reported-*', 'reason' => 'Attached to a bug report', 'files' => 1]], 'unaddressable' => 1, 'unclassified' => 1, 'analyzer_failures' => [['path' => 'tests/crashes-qpdf.pdf', 'reason' => 'qpdf was terminated by signal 11']]]], $pool['sources']);
        self::assertContains(['stratum' => 'classic', 'candidates' => 3, 'selected' => count($inputs)], $pool['strata']);
        self::assertContains(['stratum' => 'legacy', 'candidates' => 1, 'selected' => 1], $pool['strata']);
        self::assertContains(['stratum' => 'forms', 'candidates' => 0, 'selected' => 0], $pool['strata']);
        self::assertSame(hash('sha256', (string) json_encode(self::plan())), $pool['plan_sha256']);
    }

    public function test_selection_is_reproduced_from_the_cache(): void
    {
        $first = $this->select(self::fetcher(self::files()), self::plan());
        $fetcher = self::fetcher(self::files());

        self::assertSame($first, $this->select($fetcher, self::plan()));
        self::assertSame([self::LISTING_URL], $fetcher->requestedUrls);
    }

    public function test_symbolic_link_is_not_a_candidate(): void
    {
        $fetcher = self::fetcher(self::files());

        $this->select($fetcher, self::plan());

        self::assertNotContains('https://example.test/raw/'.self::REVISION.'/tests/link.pdf', $fetcher->requestedUrls);
    }

    public function test_file_that_does_not_match_its_listed_object_stops_the_selection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tests/legacy.pdf does not match the object');

        $this->select(self::fetcher(self::files(), corruptedPaths: ['tests/legacy.pdf']), self::plan());
    }

    public function test_truncated_listing_stops_the_selection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is truncated');

        $this->select(self::fetcher(self::files(), ['truncated' => true]), self::plan());
    }

    public function test_plan_that_does_not_follow_its_schema_is_refused(): void
    {
        $plan = self::plan();
        unset($plan['sources'][0]['document_license']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not follow its schema');

        SelectionPlan::fromJson((string) json_encode($plan));
    }

    public function test_plan_that_declares_a_source_twice_is_refused(): void
    {
        $plan = self::plan();
        $plan['sources'][] = $plan['sources'][0];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source fixture is declared more than once');

        SelectionPlan::fromJson((string) json_encode($plan));
    }
}
