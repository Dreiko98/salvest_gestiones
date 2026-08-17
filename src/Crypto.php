<?php
declare(strict_types=1);

namespace Salvest;

final class Crypto
{
    private string $key;

    public function __construct(string $base64Key)
    {
        $decoded = base64_decode($base64Key, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \RuntimeException('La clave de cifrado debe ser exactamente 32 bytes en base64.');
        }
        $this->key = $decoded;
    }

    public static function generateKey(): string { return base64_encode(random_bytes(32)); }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function decrypt(string $encoded): string
    {
        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Secreto cifrado no válido.');
        }
        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plaintext === false) throw new \RuntimeException('No se pudo descifrar el secreto.');
        return $plaintext;
    }
}
