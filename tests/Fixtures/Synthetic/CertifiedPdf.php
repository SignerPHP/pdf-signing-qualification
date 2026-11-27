<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic;

use RuntimeException;

final class CertifiedPdf
{
    private const string BYTE_RANGE_PLACEHOLDER = '/ByteRange [0 ********** ********** **********]';

    private const int CONTENTS_HEX_LENGTH = 64;

    public static function certified(int $permission): string
    {
        $document = SyntheticPdf::classic([
            1 => self::catalog(['4 0 R'], '5 0 R'),
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Annots [4 0 R] >>',
            4 => self::field('Certification', '5 0 R'),
            5 => self::signature($permission),
        ], '1.7');

        return self::fillByteRange($document, 0);
    }

    public static function uncertified(): string
    {
        return SyntheticPdf::classic([
            1 => self::catalog([], null),
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] >>',
        ], '1.7');
    }

    /**
     * @param  list<string>  $priorFields
     */
    public static function appendSignature(string $document, array $priorFields, ?string $certificationReference, ?int $certificationPermission = null): string
    {
        $updateStart = strlen($document);
        $fields = [...$priorFields, '6 0 R'];
        $updated = SyntheticPdf::appendUpdate($document, [
            1 => self::catalog($fields, $certificationPermission === null ? $certificationReference : '7 0 R'),
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Annots ['.implode(' ', $fields).'] >>',
            6 => self::field('Added', '7 0 R'),
            7 => self::signature($certificationPermission),
        ]);

        return self::fillByteRange($updated, $updateStart);
    }

    /**
     * @param  list<string>  $fields
     */
    private static function catalog(array $fields, ?string $certificationReference): string
    {
        $form = $fields === [] ? '' : ' /AcroForm << /Fields ['.implode(' ', $fields).'] /SigFlags 3 >>';
        $permissions = $certificationReference === null ? '' : " /Perms << /DocMDP {$certificationReference} >>";

        return "<< /Type /Catalog /Pages 2 0 R{$form}{$permissions} >>";
    }

    private static function field(string $name, string $signatureReference): string
    {
        return "<< /Type /Annot /Subtype /Widget /FT /Sig /T ({$name}) /Rect [0 0 0 0] /F 132 /P 3 0 R /V {$signatureReference} >>";
    }

    private static function signature(?int $certificationPermission): string
    {
        $reference = $certificationPermission === null
            ? ''
            : " /Reference [<< /Type /SigRef /TransformMethod /DocMDP /TransformParams << /Type /TransformParams /P {$certificationPermission} /V /1.2 >> >>]";

        return '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached '.self::BYTE_RANGE_PLACEHOLDER.' /Contents <'.str_repeat('0', self::CONTENTS_HEX_LENGTH).">{$reference} /M (D:20261127120000Z) >>";
    }

    private static function fillByteRange(string $document, int $searchFrom): string
    {
        $placeholder = strpos($document, self::BYTE_RANGE_PLACEHOLDER, $searchFrom);
        $contents = strpos($document, '/Contents <', $searchFrom);
        if ($placeholder === false || $contents === false) {
            throw new RuntimeException('The signature dictionary to fill was not found');
        }

        $gapStart = $contents + strlen('/Contents ');
        $gapEnd = $gapStart + self::CONTENTS_HEX_LENGTH + 2;
        $byteRange = sprintf('/ByteRange [0 %d %d %d]', $gapStart, $gapEnd, strlen($document) - $gapEnd);

        return substr_replace($document, str_pad($byteRange, strlen(self::BYTE_RANGE_PLACEHOLDER)), $placeholder, strlen(self::BYTE_RANGE_PLACEHOLDER));
    }
}
