<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\SignatureListing;

final class SignatureListingTest extends TestCase
{
    public function test_every_dictionary_with_a_byte_range_is_listed_with_its_holder_and_field(): void
    {
        $listing = (string) json_encode(['qpdf' => [[], [
            'obj:1 0 R' => ['value' => ['/Type' => '/Catalog', '/AcroForm' => ['/Fields' => ['2 0 R']]]],
            'obj:2 0 R' => ['value' => ['/FT' => '/Sig', '/T' => 'u:Parent', '/Kids' => ['3 0 R']]],
            'obj:3 0 R' => ['value' => ['/T' => 'u:Child', '/V' => '4 0 R']],
            'obj:4 0 R' => ['value' => ['/Type' => '/DocTimeStamp', '/ByteRange' => '5 0 R', '/Contents' => 'b:3082ABCD']],
            'obj:5 0 R' => ['value' => [0, 10, 20, 30]],
            'obj:6 0 R' => ['stream' => ['dict' => ['/ByteRange' => [0, 1, 2], '/Contents' => 'u:caf']]],
            'trailer' => ['value' => ['/Root' => '1 0 R', '/Extra' => ['/ByteRange' => [0, 5, 6, 7]]]],
        ]]]);

        $signatures = SignatureListing::read(new FakeCommandRunner(new CommandResult(0, ''), new CommandResult(0, $listing)), 'document.pdf', 30.0)->signatures;

        self::assertSame([
            ['object' => '4 0 R', 'field' => 'Parent.Child', 'type' => '/DocTimeStamp', 'byte_range' => [0, 10, 20, 30], 'contents' => '3082abcd'],
            ['object' => '6 0 R', 'field' => null, 'type' => null, 'byte_range' => null, 'contents' => '636166'],
            ['object' => 'trailer', 'field' => null, 'type' => null, 'byte_range' => [0, 5, 6, 7], 'contents' => null],
        ], $signatures);
    }

    public function test_listing_that_qpdf_could_not_produce_is_unreadable(): void
    {
        $listing = SignatureListing::read(new FakeCommandRunner(new CommandResult(0, ''), new CommandResult(2, "qpdf: can't find startxref\n")), 'document.pdf', 30.0);

        self::assertNull($listing->signatures);
        self::assertFalse($listing->isComplete());
    }
}
