<?php

namespace App\Tests\Integration\Crypto;

use App\Infrastructure\Crypto\LegacyLaravelDecryptor;
use App\Infrastructure\Crypto\LegacyPaymentReencryptor;
use App\Infrastructure\Crypto\PaymentPayloadCodec;
use App\Infrastructure\Crypto\SodiumFieldCipher;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LegacyPaymentReencryptorTest extends KernelTestCase
{
    private const LEGACY_STRING =
        'eyJpdiI6Im94ZXNicm0wTjlsaDh4MlhnaDJwMVE9PSIsInZhbHVlIjoiaWdzRXlXejRBTWc2aG9VT0ExNkcyQT09IiwibWFjIjoi'
        .'Y2E1MDQ1OGJhMzViOGIzODE2MTJmMDAxOTA1YTY0N2Q1OTdjMmM5MzUyMzllZGY0OTY0MmNhN2Y5NjIxNDQ3ZiIsInRhZyI6IiJ9';

    private const LEGACY_ARRAY =
        'eyJpdiI6IlhXcFhuczlDOWxLK1NnZjZtaXRzbEE9PSIsInZhbHVlIjoiYXlmTjFpK0p1WFdDaFV0L3RhOFI4cHBmMDJsWkxML1FE'
        .'WDBtZm92dytadExOTU1Vd01YeWlqMHdzVUxmWUwzayIsIm1hYyI6ImE0MDNkOTRmYWRmMTAxMTIyMTJhYjczMzk5ZWRhMjdjNzk1'
        .'MTIzYzdiYjE2MmZmM2JlMTEzM2Y1ZTcwN2Q5YjQiLCJ0YWciOiIifQ==';

    private Connection $connection;

    private PaymentPayloadCodec $codec;

    private LegacyPaymentReencryptor $reencryptor;

    protected function setUp(): void
    {
        if (getenv('RUN_DB_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DB_TESTS=1 with an isolated PostgreSQL database.');
        }

        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement(
            'TRUNCATE TABLE payment_attempts, payment_sessions, orders RESTART IDENTITY CASCADE',
        );
        $this->codec = new PaymentPayloadCodec(
            new SodiumFieldCipher(str_repeat('n', 32)),
            new LegacyLaravelDecryptor(str_repeat('k', 32)),
        );
        $this->reencryptor = new LegacyPaymentReencryptor($this->connection, $this->codec);
        $this->seed();
    }

    #[Test]
    public function dry_run_is_read_only_and_apply_is_idempotent_and_preserves_plaintext(): void
    {
        $before = $this->connection->fetchAssociative('SELECT * FROM payment_sessions WHERE id = 1');
        $dry = $this->reencryptor->run(false, 1);

        self::assertSame(4, $dry->readable);
        self::assertSame(0, $dry->rewritten);
        self::assertSame(0, $dry->unreadable);
        self::assertSame($before, $this->connection->fetchAssociative(
            'SELECT * FROM payment_sessions WHERE id = 1',
        ));

        $applied = $this->reencryptor->run(true, 1);
        self::assertSame(4, $applied->readable);
        self::assertSame(4, $applied->rewritten);
        self::assertSame(0, $applied->unreadable);

        $session = $this->connection->fetchAssociative('SELECT * FROM payment_sessions WHERE id = 1');
        $attempt = $this->connection->fetchAssociative('SELECT * FROM payment_attempts WHERE id = 1');
        self::assertStringStartsWith('v1:', $session['session_token']);
        self::assertStringStartsWith('v1:', $attempt['payload']);
        self::assertSame('session-token', $this->codec->decryptString(
            $session['session_token'],
            'payment_sessions.session_token:1',
        ));
        self::assertSame(
            ['paymentMethod' => 'pm-test', 'nested' => ['x' => 1]],
            $this->codec->decryptArray($attempt['payload'], 'payment_attempts.payload:1'),
        );

        $again = $this->reencryptor->run(true, 1);
        self::assertSame(0, $again->readable);
        self::assertSame(0, $again->rewritten);
        self::assertSame(0, $again->unreadable);
    }

    private function seed(): void
    {
        $timestamps = [
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
        $this->connection->insert('orders', [
            'number' => 'SP-CRYPT01',
            'email' => 'buyer@example.test',
            'phone' => '123456789',
            'status' => 'pending_payment',
            'delivery_method' => 'standard',
            'subtotal_cents' => 100,
            'delivery_cents' => 0,
            'total_cents' => 100,
            'currency' => 'EUR',
            'base_currency' => 'EUR',
            ...$timestamps,
        ]);
        $this->connection->insert('payment_sessions', [
            'order_id' => 1,
            'provider' => 'shop_pay',
            'source_identifier' => 'shop-pay:SP-CRYPT01',
            'session_token' => self::LEGACY_STRING,
            'session_token_hash' => hash('sha256', 'session-token'),
            'receipt_token' => self::LEGACY_STRING,
            'status' => 'processing',
            ...$timestamps,
        ]);
        $this->connection->insert('payment_attempts', [
            'payment_session_id' => 1,
            'payment_method_hash' => hash('sha256', 'pm-test'),
            'amount_cents' => 100,
            'currency' => 'EUR',
            'idempotency_key' => 'b02e0ea2-ec78-4aa7-9f83-0f7b763bd080',
            'payload' => self::LEGACY_ARRAY,
            'receipt_token' => self::LEGACY_STRING,
            'status' => 'processing',
            ...$timestamps,
        ]);
    }
}
