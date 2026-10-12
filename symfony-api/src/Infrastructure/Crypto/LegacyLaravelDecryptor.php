<?php

namespace App\Infrastructure\Crypto;

use DomainException;
use JsonException;

final readonly class LegacyLaravelDecryptor
{
    private ?string $key;

    public function __construct(string $encodedKey)
    {
        if ($encodedKey === '') {
            $this->key = null;

            return;
        }
        $key = $this->decodeKey($encodedKey);
        if (strlen($key) !== 32) {
            throw new DomainException('Legacy Laravel key must contain 32 bytes.');
        }
        $this->key = $key;
    }

    public function decrypt(string $payload): string
    {
        if ($this->key === null) {
            throw new DomainException('Legacy Laravel key is not configured.');
        }
        try {
            $decoded = base64_decode($payload, true);
            if ($decoded === false) {
                throw new DomainException('Legacy Laravel payload is not base64.');
            }
            $data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('Legacy Laravel payload is invalid.', previous: $exception);
        }
        if (
            !is_array($data)
            || !is_string($data['iv'] ?? null)
            || !is_string($data['value'] ?? null)
            || !is_string($data['mac'] ?? null)
        ) {
            throw new DomainException('Legacy Laravel payload is invalid.');
        }
        $expected = hash_hmac('sha256', $data['iv'].$data['value'], $this->key);
        if (!hash_equals($expected, $data['mac'])) {
            throw new DomainException('Legacy Laravel payload MAC is invalid.');
        }

        $iv = base64_decode($data['iv'], true);
        if ($iv === false || strlen($iv) !== openssl_cipher_iv_length('aes-256-cbc')) {
            throw new DomainException('Legacy Laravel payload IV is invalid.');
        }
        $plaintext = openssl_decrypt(
            $data['value'],
            'aes-256-cbc',
            $this->key,
            0,
            $iv,
        );
        if ($plaintext === false) {
            throw new DomainException('Legacy Laravel payload could not be decrypted.');
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
            throw new DomainException('Legacy Laravel key is invalid.');
        }

        return $decoded;
    }
}
