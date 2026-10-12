<?php

namespace App\Application\Payments;

interface FieldCipher
{
    public function encrypt(string $plaintext, string $context): string;

    public function decrypt(string $payload, string $context): string;
}
