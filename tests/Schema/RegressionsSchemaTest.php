<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class RegressionsSchemaTest extends SchemaTestCase
{
    private const string ATTEMPT_ID = 'run-20261107T120000Z/basic-invisible-sha256/pdf-signer/regression:hybrid-xref.pdf';

    protected static function schemaPath(): string
    {
        return 'v1/regressions.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'run_id' => 'run-20261107T120000Z',
            'attempts' => [[
                'schema' => self::SCHEMA_PREFIX.'v1/attempt.schema.json',
                'id' => self::ATTEMPT_ID,
                'run_id' => 'run-20261107T120000Z',
                'scenario_id' => 'basic-invisible-sha256',
                'implementation' => ['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => str_repeat('f', 40)]],
                'input' => ['status' => 'available', 'source_id' => 'regression:hybrid-xref.pdf', 'sha256' => str_repeat('d', 64), 'byte_size' => 721],
                'capability' => 'supported',
                'execution' => ['outcome' => 'succeeded', 'exit_code' => 0, 'started_at' => '2026-11-07T12:00:01Z', 'finished_at' => '2026-11-07T12:00:02Z', 'error' => null],
                'output' => ['sha256' => str_repeat('e', 64), 'byte_size' => 9_000],
            ]],
            'observations' => [],
            'cases' => [[
                'case_id' => 'hybrid-xref',
                'scenario_id' => 'basic-invisible-sha256',
                'definition' => ['sha256' => str_repeat('a', 64), 'byte_size' => 900],
                'attempt_id' => self::ATTEMPT_ID,
                'condition' => ['status' => 'present', 'evidence' => 'The final trailer has /XRefStm 445, inside the 721 bytes of the input'],
            ]],
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function conditionStatuses(): iterable
    {
        yield 'present' => ['present'];
        yield 'absent' => ['absent'];
        yield 'undetermined' => ['undetermined'];
        yield 'not evaluated' => ['not_evaluated'];
    }

    #[DataProvider('conditionStatuses')]
    public function test_each_condition_status_is_accepted_for_a_replayed_case(string $status): void
    {
        self::assertValid(self::mutate(function (array $document) use ($status): array {
            $document['cases'][0]['condition']['status'] = $status;

            return $document;
        }));
    }

    public function test_case_without_an_attempt_can_only_be_not_evaluated(): void
    {
        $notReplayed = self::mutate(function (array $document): array {
            $document['cases'][0]['attempt_id'] = null;
            $document['cases'][0]['condition'] = ['status' => 'not_evaluated', 'evidence' => 'The case runs under scenario other-scenario'];

            return $document;
        });
        self::assertValid($notReplayed);

        $notReplayed['cases'][0]['condition']['status'] = 'absent';
        self::assertInvalid($notReplayed);
    }

    public function test_condition_needs_its_evidence(): void
    {
        self::assertInvalid(self::mutate(function (array $document): array {
            unset($document['cases'][0]['condition']['evidence']);

            return $document;
        }));
    }

    public function test_attempt_must_follow_the_attempt_schema(): void
    {
        self::assertInvalid(self::mutate(function (array $document): array {
            unset($document['attempts'][0]['capability']);

            return $document;
        }));
    }
}
