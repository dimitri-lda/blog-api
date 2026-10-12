<?php

namespace App\Tests\Unit\Infrastructure\Crypto;

use App\Infrastructure\Crypto\LegacyLaravelDecryptor;
use App\Infrastructure\Crypto\PaymentPayloadCodec;
use App\Infrastructure\Crypto\SodiumFieldCipher;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentPayloadCodecTest extends TestCase
{
    private const LEGACY_STRING =
        'eyJpdiI6Im94ZXNicm0wTjlsaDh4MlhnaDJwMVE9PSIsInZhbHVlIjoiaWdzRXlXejRBTWc2aG9VT0ExNkcyQT09IiwibWFjIjoi'
        .'Y2E1MDQ1OGJhMzViOGIzODE2MTJmMDAxOTA1YTY0N2Q1OTdjMmM5MzUyMzllZGY0OTY0MmNhN2Y5NjIxNDQ3ZiIsInRhZyI6IiJ9';

    private const LEGACY_ARRAY =
        'eyJpdiI6IlhXcFhuczlDOWxLK1NnZjZtaXRzbEE9PSIsInZhbHVlIjoiYXlmTjFpK0p1WFdDaFV0L3RhOFI4cHBmMDJsWkxML1FE'
        .'WDBtZm92dytadExOTU1Vd01YeWlqMHdzVUxmWUwzayIsIm1hYyI6ImE0MDNkOTRmYWRmMTAxMTIyMTJhYjczMzk5ZWRhMjdjNzk1'
        .'MTIzYzdiYjE2MmZmM2JlMTEzM2Y1ZTcwN2Q5YjQiLCJ0YWciOiIifQ==';

    #[Test]
    public function legacy_laravel_strings_and_arrays_are_decrypted_exactly(): void
    {
        $legacy = new LegacyLaravelDecryptor(str_repeat('k', 32));
        $codec = new PaymentPayloadCodec($this->cipher(), $legacy);

        self::assertSame('session-token', $legacy->decrypt(self::LEGACY_STRING));
        self::assertSame(
            ['paymentMethod' => 'pm-test', 'nested' => ['x' => 1]],
            $codec->decryptArray(self::LEGACY_ARRAY, 'ignored-for-legacy'),
        );
    }

    #[Test]
    public function sodium_payloads_round_trip_and_are_bound_to_the_field_context(): void
    {
        $cipher = $this->cipher();
        $payload = $cipher->encrypt('secret', 'payment_sessions.session_token:7');

        self::assertStringStartsWith('v1:', $payload);
        self::assertSame(
            'secret',
            $cipher->decrypt($payload, 'payment_sessions.session_token:7'),
        );

        $this->expectException(DomainException::class);
        $cipher->decrypt($payload, 'payment_sessions.session_token:8');
    }

    #[Test]
    public function codec_encrypts_and_decrypts_array_payloads_with_the_current_cipher(): void
    {
        $codec = new PaymentPayloadCodec(
            $this->cipher(),
            new LegacyLaravelDecryptor(str_repeat('k', 32)),
        );
        $value = ['paymentMethod' => 'pm-new', 'nested' => ['x' => 2]];
        $payload = $codec->encryptArray($value, 'payment_attempts.payload:11');

        self::assertSame($value, $codec->decryptArray(
            $payload,
            'payment_attempts.payload:11',
        ));
    }

    #[Test]
    public function tampered_legacy_payload_is_rejected_before_decryption(): void
    {
        $decoded = json_decode(
            base64_decode(self::LEGACY_STRING, true),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $decoded['mac'][0] = $decoded['mac'][0] === 'a' ? 'b' : 'a';
        $tampered = base64_encode(json_encode($decoded, JSON_THROW_ON_ERROR));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('MAC');
        (new LegacyLaravelDecryptor(str_repeat('k', 32)))->decrypt($tampered);
    }

    private function cipher(): SodiumFieldCipher
    {
        return new SodiumFieldCipher(str_repeat('n', 32));
    }
}
