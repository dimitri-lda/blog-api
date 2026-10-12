<?php

namespace App\Infrastructure\Crypto;

use App\Application\Payments\FieldCipher;

final readonly class PaymentPayloadCodec
{
    public function __construct(
        private FieldCipher $current,
        private LegacyLaravelDecryptor $legacy,
    ) {
    }

    public function encryptString(string $value, string $context): string
    {
        return $this->current->encrypt($value, $context);
    }

    public function decryptString(string $value, string $context): string
    {
        return str_starts_with($value, 'v1:')
            ? $this->current->decrypt($value, $context)
            : $this->legacy->decrypt($value);
    }

    public function encryptArray(array $value, string $context): string
    {
        return $this->encryptString(
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $context,
        );
    }

    public function decryptArray(string $value, string $context): array
    {
        $decoded = json_decode(
            $this->decryptString($value, $context),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return is_array($decoded) ? $decoded : [];
    }
}
