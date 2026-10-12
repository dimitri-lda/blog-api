<?php

namespace App\Infrastructure\Crypto;

use App\Application\Payments\FieldCipher;
use DomainException;

final readonly class SodiumFieldCipher implements FieldCipher
{
    private string $key;

    public function __construct(string $encodedKey)
    {
        $key = $this->decodeKey($encodedKey);
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new DomainException('Payment encryption key must contain 32 bytes.');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext, string $context): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $context,
            $nonce,
            $this->key,
        );

        return 'v1:'.base64_encode($nonce.$ciphertext);
    }

    public function decrypt(string $payload, string $context): string
    {
        if (!str_starts_with($payload, 'v1:')) {
            throw new DomainException('Unsupported payment encryption payload.');
        }
        $decoded = base64_decode(substr($payload, 3), true);
        if (
            $decoded === false
            || strlen($decoded) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
        ) {
            throw new DomainException('Invalid payment encryption payload.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $context,
            $nonce,
            $this->key,
        );
        if ($plaintext === false) {
            throw new DomainException('Payment encryption authentication failed.');
        }

        return $plaintext;
    }

    private function decodeKey(string $encodedKey): string
    {
        if (!str_starts_with($encodedKey, 'base64:')) {
            return $encodedKey;
        }

        $decoded = base64_decode(substr($encodedKey, 7), true);
        if ($decoded === false) {
            throw new DomainException('Payment encryption key is invalid.');
        }

        return $decoded;
    }
}
