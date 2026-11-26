<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Pki;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Pki\TestPki;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;

final class TestPkiTest extends TestCase
{
    private static string $directory;

    private static TestPki $pki;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/test-pki-'.bin2hex(random_bytes(6));
        mkdir(self::$directory);
        self::$pki = TestPki::start(new ProcessRunner, self::$directory.'/pki');
    }

    public static function tearDownAfterClass(): void
    {
        self::$pki->stop();
        exec('rm -rf '.escapeshellarg(self::$directory));
    }

    public function test_signers_get_distinct_certificates_issued_by_the_intermediate_with_their_chain(): void
    {
        $credentials = self::$pki->credentials(['valid', 'valid', 'revoked']);

        $subjects = [];
        foreach ($credentials as $credential) {
            self::assertTrue(openssl_pkcs12_read((string) file_get_contents($credential->pkcs12Path), $bundle, $credential->password));
            $subjects[] = openssl_x509_parse($bundle['cert'])['subject']['CN'];
            self::assertCount(2, $bundle['extracerts']);
        }
        self::assertSame(['Signer A', 'Signer B', 'Signer Revoked'], $subjects);
    }

    public function test_more_valid_signers_than_certificates_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::$pki->credentials(['valid', 'valid', 'valid']);
    }

    public function test_time_stamping_authority_answers_on_the_loopback_interface(): void
    {
        $query = self::$directory.'/request.tsq';
        $reply = self::$directory.'/reply.tsr';
        file_put_contents(self::$directory.'/data', 'qualification');
        exec('openssl ts -query -sha256 -cert -data '.escapeshellarg(self::$directory.'/data').' -out '.escapeshellarg($query).' 2>/dev/null', result_code: $queryStatus);
        self::assertSame(0, $queryStatus);

        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/timestamp-query', 'content' => file_get_contents($query)]]);
        $response = file_get_contents(self::$pki->timestampUrl(), false, $context);
        self::assertIsString($response);
        file_put_contents($reply, $response);

        exec('openssl ts -reply -in '.escapeshellarg($reply).' -text 2>&1', $output, $status);
        self::assertSame(0, $status);
        self::assertStringContainsString('Status: Granted.', implode("\n", $output));
        self::assertStringStartsWith('http://127.0.0.1:', self::$pki->timestampUrl());
    }

    public function test_root_certificate_is_described_for_the_policy_of_the_validators(): void
    {
        self::assertSame(hash_file('sha256', self::$pki->rootCertificatePath()), self::$pki->rootCertificate()['sha256']);
    }
}
