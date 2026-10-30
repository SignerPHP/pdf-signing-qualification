<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class AttemptSchemaTest extends SchemaTestCase
{
    private const string INPUT_SHA256 = '3b4c1f0a9e6d2b7c8f5e4a3d2c1b0a9f8e7d6c5b4a39281706f5e4d3c2b1a0f9';

    private const string OUTPUT_SHA256 = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected static function schemaPath(): string
    {
        return 'v1/attempt.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'id' => 'run-20261028T120000Z/pdfjs-issue17069/basic-invisible-sha256/pdf-signer/1',
            'run_id' => 'run-20261028T120000Z',
            'scenario_id' => 'basic-invisible-sha256',
            'implementation' => [
                'id' => 'pdf-signer',
                'revision' => ['kind' => 'commit', 'value' => '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c'],
            ],
            'input' => [
                'status' => 'available',
                'source_id' => 'pdfjs:test/pdfs/issue17069.pdf',
                'sha256' => self::INPUT_SHA256,
                'byte_size' => 18_423,
            ],
            'capability' => 'supported',
            'execution' => [
                'outcome' => 'succeeded',
                'exit_code' => 0,
                'started_at' => '2026-10-28T12:00:01Z',
                'finished_at' => '2026-10-28T12:00:02Z',
                'error' => null,
            ],
            'output' => [
                'sha256' => self::OUTPUT_SHA256,
                'byte_size' => 27_911,
            ],
        ];
    }

    private static function failedAttempt(): array
    {
        return self::mutate(function (array $attempt): array {
            $attempt['execution']['outcome'] = 'failed';
            $attempt['execution']['exit_code'] = 1;
            $attempt['execution']['error'] = ['message' => 'Unable to locate the cross-reference table'];
            $attempt['output'] = null;

            return $attempt;
        });
    }

    private static function unavailableInputAttempt(string $reason): array
    {
        return self::mutate(function (array $attempt) use ($reason): array {
            $attempt['input'] = [
                'status' => 'unavailable',
                'source_id' => 'pdfjs:test/pdfs/issue17069.pdf',
                'reason' => $reason,
                'expected_sha256' => self::INPUT_SHA256,
                'message' => 'Upstream file could not be acquired as declared',
            ];
            $attempt['capability'] = 'not_evaluated';
            $attempt['execution'] = [
                'outcome' => 'not_run',
                'exit_code' => null,
                'started_at' => null,
                'finished_at' => null,
                'error' => null,
            ];
            $attempt['output'] = null;

            return $attempt;
        });
    }

    public static function validAttempts(): iterable
    {
        yield 'failed execution with its error message' => [self::failedAttempt()];
        yield 'timed out execution' => [self::mutate(function (array $attempt): array {
            $attempt['execution']['outcome'] = 'timed_out';
            $attempt['execution']['exit_code'] = null;
            $attempt['execution']['error'] = ['message' => 'Terminated after 60 seconds'];
            $attempt['output'] = null;

            return $attempt;
        })];
        yield 'missing upstream input' => [self::unavailableInputAttempt('missing_upstream')];
        yield 'hash mismatched input' => [self::mutate(function (array $attempt): array {
            $attempt['input']['observed_sha256'] = self::OUTPUT_SHA256;

            return $attempt;
        }, self::unavailableInputAttempt('hash_mismatch'))];
        yield 'unsupported scenario that was not run' => [self::mutate(function (array $attempt): array {
            $attempt['capability'] = 'unsupported';
            $attempt['execution'] = [
                'outcome' => 'not_run',
                'exit_code' => null,
                'started_at' => null,
                'finished_at' => null,
                'error' => null,
            ];
            $attempt['output'] = null;

            return $attempt;
        })];
        yield 'expected rejection kept apart from the execution outcome' => [self::mutate(function (array $attempt): array {
            $attempt['capability'] = 'expected_rejection';

            return $attempt;
        }, self::failedAttempt())];
        yield 'expected rejection that succeeded kept as an observed result' => [self::mutate(function (array $attempt): array {
            $attempt['capability'] = 'expected_rejection';

            return $attempt;
        })];
        yield 'released version as revision' => [self::mutate(function (array $attempt): array {
            $attempt['implementation']['revision'] = ['kind' => 'version', 'value' => '1.4.0'];

            return $attempt;
        })];
        yield 'input identifier with spaces inside its segments' => [self::mutate(function (array $attempt): array {
            $attempt['id'] = 'run-20261028T120000Z/basic-invisible-sha256/pdf-signer/verapdf-corpus:PDF_UA-1/7.16 Security/7.16-t01-fail-a.pdf';
            $attempt['input']['source_id'] = 'verapdf-corpus:PDF_UA-1/7.16 Security/7.16-t01-fail-a.pdf';

            return $attempt;
        })];
    }

    #[DataProvider('validAttempts')]
    public function test_valid_attempt_is_accepted(array $attempt): void
    {
        self::assertValid($attempt);
    }

    public static function invalidAttempts(): iterable
    {
        yield 'branch name as commit revision' => [self::mutate(function (array $attempt): array {
            $attempt['implementation']['revision']['value'] = 'main';

            return $attempt;
        })];
        yield 'branch name as version revision' => [self::mutate(function (array $attempt): array {
            $attempt['implementation']['revision'] = ['kind' => 'version', 'value' => 'dev-main'];

            return $attempt;
        })];
        yield 'abbreviated commit revision' => [self::mutate(function (array $attempt): array {
            $attempt['implementation']['revision']['value'] = '0f1e2d3';

            return $attempt;
        })];
        yield 'missing revision' => [self::mutate(function (array $attempt): array {
            unset($attempt['implementation']['revision']);

            return $attempt;
        })];
        yield 'failed execution without error message' => [self::mutate(function (array $attempt): array {
            $attempt['execution']['error'] = null;

            return $attempt;
        }, self::failedAttempt())];
        yield 'failed execution with empty error message' => [self::mutate(function (array $attempt): array {
            $attempt['execution']['error'] = ['message' => ''];

            return $attempt;
        }, self::failedAttempt())];
        yield 'successful execution without output' => [self::mutate(function (array $attempt): array {
            $attempt['output'] = null;

            return $attempt;
        })];
        yield 'unavailable input counted as failed execution' => [self::mutate(function (array $attempt): array {
            $attempt['execution'] = [
                'outcome' => 'failed',
                'exit_code' => 1,
                'started_at' => '2026-10-28T12:00:01Z',
                'finished_at' => '2026-10-28T12:00:02Z',
                'error' => ['message' => 'Input not found'],
            ];

            return $attempt;
        }, self::unavailableInputAttempt('missing_upstream'))];
        yield 'unavailable input with an evaluated capability' => [self::mutate(function (array $attempt): array {
            $attempt['capability'] = 'unsupported';

            return $attempt;
        }, self::unavailableInputAttempt('missing_upstream'))];
        yield 'hash mismatch without the observed hash' => [self::unavailableInputAttempt('hash_mismatch')];
        yield 'unavailable input with unknown reason' => [self::unavailableInputAttempt('network_error')];
        yield 'capability merged into execution outcome' => [self::mutate(function (array $attempt): array {
            $attempt['execution']['outcome'] = 'unsupported';

            return $attempt;
        })];
        yield 'unknown capability' => [self::mutate(function (array $attempt): array {
            $attempt['capability'] = 'failed';

            return $attempt;
        })];
        yield 'uppercase input hash' => [self::mutate(function (array $attempt): array {
            $attempt['input']['sha256'] = strtoupper(self::INPUT_SHA256);

            return $attempt;
        })];
        yield 'start time without UTC designator' => [self::mutate(function (array $attempt): array {
            $attempt['execution']['started_at'] = '2026-10-28T12:00:01-03:00';

            return $attempt;
        })];
        yield 'successful execution with a nonzero exit code' => [self::mutate(function (array $attempt): array {
            $attempt['execution']['exit_code'] = 139;

            return $attempt;
        })];
        yield 'successful execution with an error' => [self::mutate(function (array $attempt): array {
            $attempt['execution']['error'] = ['message' => 'Unexpected warning'];

            return $attempt;
        })];
        yield 'failed execution without finish time' => [self::mutate(function (array $attempt): array {
            $attempt['execution']['finished_at'] = null;

            return $attempt;
        }, self::failedAttempt())];
        yield 'supported attempt that was not run' => [self::mutate(function (array $attempt): array {
            $attempt['execution'] = [
                'outcome' => 'not_run',
                'exit_code' => null,
                'started_at' => null,
                'finished_at' => null,
                'error' => null,
            ];
            $attempt['output'] = null;

            return $attempt;
        })];
        yield 'attempt that was not run but has an output' => [self::mutate(function (array $attempt): array {
            $attempt['capability'] = 'unsupported';
            $attempt['execution'] = [
                'outcome' => 'not_run',
                'exit_code' => null,
                'started_at' => null,
                'finished_at' => null,
                'error' => null,
            ];

            return $attempt;
        })];
        yield 'attempt that was not run but has an exit code' => [self::mutate(function (array $attempt): array {
            $attempt['capability'] = 'unsupported';
            $attempt['execution'] = [
                'outcome' => 'not_run',
                'exit_code' => null,
                'started_at' => null,
                'finished_at' => null,
                'error' => null,
            ];
            $attempt['execution']['exit_code'] = 139;
            $attempt['output'] = null;

            return $attempt;
        })];
        yield 'attempt that was not run but has timestamps' => [self::mutate(function (array $attempt): array {
            $attempt['capability'] = 'unsupported';
            $attempt['execution'] = [
                'outcome' => 'not_run',
                'exit_code' => null,
                'started_at' => null,
                'finished_at' => null,
                'error' => null,
            ];
            $attempt['execution']['started_at'] = '2026-10-28T12:00:01Z';
            $attempt['execution']['finished_at'] = '2026-10-28T12:00:02Z';
            $attempt['output'] = null;

            return $attempt;
        })];
        yield 'attempt that was not run but has an error' => [self::mutate(function (array $attempt): array {
            $attempt['capability'] = 'unsupported';
            $attempt['execution'] = [
                'outcome' => 'not_run',
                'exit_code' => null,
                'started_at' => null,
                'finished_at' => null,
                'error' => null,
            ];
            $attempt['execution']['error'] = ['message' => 'Segmentation fault'];
            $attempt['output'] = null;

            return $attempt;
        })];
        yield 'capability not evaluated for an available input' => [self::mutate(function (array $attempt): array {
            $attempt['capability'] = 'not_evaluated';

            return $attempt;
        })];
        yield 'identifier run-1/../../etc/x' => [self::mutate(function (array $attempt): array {
            $attempt['id'] = 'run-1/../../etc/x';

            return $attempt;
        })];
        yield 'identifier run-1/./x' => [self::mutate(function (array $attempt): array {
            $attempt['id'] = 'run-1/./x';

            return $attempt;
        })];
        yield 'identifier run-1//x' => [self::mutate(function (array $attempt): array {
            $attempt['id'] = 'run-1//x';

            return $attempt;
        })];
        yield 'identifier run-1/' => [self::mutate(function (array $attempt): array {
            $attempt['id'] = 'run-1/';

            return $attempt;
        })];
        yield 'identifier run-1/ x' => [self::mutate(function (array $attempt): array {
            $attempt['id'] = 'run-1/ x';

            return $attempt;
        })];
        yield 'identifier run-1/x ' => [self::mutate(function (array $attempt): array {
            $attempt['id'] = 'run-1/x ';

            return $attempt;
        })];
    }

    #[DataProvider('invalidAttempts')]
    public function test_invalid_attempt_is_rejected(array $attempt): void
    {
        self::assertInvalid($attempt);
    }
}
