<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

use RuntimeException;

final class TestCredential
{
    private function __construct(
        public readonly string $pkcs12Path,
        public readonly string $password,
    ) {}

    public static function generate(string $directory): self
    {
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($privateKey === false) {
            throw new RuntimeException('Unable to generate the test signing key');
        }

        $request = openssl_csr_new(['commonName' => 'Qualification test signer'], $privateKey, ['digest_alg' => 'sha256']);
        $certificate = $request === false || $request === true ? false : openssl_csr_sign($request, null, $privateKey, 1, ['digest_alg' => 'sha256']);
        if ($certificate === false) {
            throw new RuntimeException('Unable to generate the test signing certificate');
        }

        $credential = new self($directory.'/credential-'.bin2hex(random_bytes(8)).'.p12', bin2hex(random_bytes(16)));
        if (! openssl_pkcs12_export_to_file($certificate, $credential->pkcs12Path, $privateKey, $credential->password) || ! chmod($credential->pkcs12Path, 0600)) {
            throw new RuntimeException('Unable to write the test signing credential: '.(string) openssl_error_string());
        }

        return $credential;
    }

    public function remove(): void
    {
        if (is_file($this->pkcs12Path) && ! unlink($this->pkcs12Path)) {
            throw new RuntimeException("Unable to remove the test signing credential {$this->pkcs12Path}");
        }
    }
}
