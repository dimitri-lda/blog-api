<?php

namespace App\Tests\Integration\Payments;

use App\Application\Payments\ShopPayService;
use App\Infrastructure\Crypto\LegacyLaravelDecryptor;
use App\Infrastructure\Crypto\PaymentPayloadCodec;
use App\Infrastructure\Crypto\SodiumFieldCipher;
use App\Infrastructure\Payments\ShopifyAdminClient;
use App\Infrastructure\Payments\ShopPayConfiguration;
use App\Infrastructure\Payments\ShopPayPaymentRequestFactory;
use App\Infrastructure\Payments\ShopPayStorefrontClient;
use App\Infrastructure\Persistence\Doctrine\Orders\DbalOrderReader;
use Doctrine\DBAL\Connection;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use App\Presentation\Http\Api\V1\ShopifyWebhookController;

final class ShopPayServiceTest extends KernelTestCase
{
    private Connection $connection;

    private DbalOrderReader $orders;

    protected function setUp(): void
    {
        if (getenv('RUN_DB_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DB_TESTS=1 with an isolated PostgreSQL database.');
        }

        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement(
            <<<'SQL'
TRUNCATE TABLE
    payment_attempts,
    payment_sessions,
    order_items,
    order_addresses,
    orders,
    product_variants,
    products,
    categories
RESTART IDENTITY CASCADE
SQL,
        );
        $this->seedOrder();
        $this->orders = new DbalOrderReader($this->connection);
    }

    #[Test]
    public function create_submit_and_exact_reconcile_are_idempotent_and_keep_secrets_encrypted(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode($this->sessionResponse(), JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode($this->submitResponse(), JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode($this->receiptResponse(), JSON_THROW_ON_ERROR)),
        ]);
        $service = $this->service($http);
        $order = $this->order();

        $created = $service->createSession($order, '127.0.0.1');
        self::assertSame('session-token-1', $created['token']);
        self::assertSame('session-token-1', $service->createSession($order, '127.0.0.1')['token']);
        $session = $this->connection->fetchAssociative('SELECT * FROM payment_sessions');
        self::assertStringStartsWith('v1:', $session['session_token']);
        self::assertNotSame('session-token-1', $session['session_token']);

        $submitted = $service->submit($order, 'session-token-1', 'pm-test', '127.0.0.1');
        self::assertSame('PROCESSING', $submitted['status']);
        $attempt = $this->connection->fetchAssociative('SELECT * FROM payment_attempts');
        self::assertStringStartsWith('v1:', $attempt['payload']);
        self::assertStringStartsWith('v1:', $attempt['receipt_token']);

        self::assertSame(
            ['status' => 'processing'],
            $service->submit($order, 'session-token-1', 'pm-test', '127.0.0.1'),
        );
        self::assertTrue($service->reconcile($order));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM orders'));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM payment_sessions'));
        self::assertSame('completed', $this->connection->fetchOne('SELECT status FROM payment_attempts'));
    }

    #[Test]
    public function quote_updates_address_and_delivery_but_rejects_a_country_change(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode($this->sessionResponse(), JSON_THROW_ON_ERROR)),
        ]);
        $service = $this->service($http);
        $order = $this->order();
        $service->createSession($order, '127.0.0.1');

        $quote = $service->updateQuote(
            $order,
            'session-token-1',
            [
                'first_name' => 'Changed',
                'last_name' => 'Buyer',
                'line1' => 'Other 9',
                'city' => 'Hamburg',
                'postal_code' => '20095',
                'country' => 'DE',
            ],
            'standard',
        );
        self::assertSame('Changed', $quote['paymentRequest']['shippingAddress']['firstName']);
        self::assertSame('standard', $this->connection->fetchOne(
            'SELECT delivery_method FROM orders',
        ));
        self::assertSame('Hamburg', $this->connection->fetchOne('SELECT city FROM order_addresses'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('original country');
        $service->updateQuote(
            $this->order(),
            'session-token-1',
            ['country' => 'PL'],
            'standard',
        );
    }

    #[Test]
    public function mismatched_receipt_never_marks_the_order_paid(): void
    {
        $receipt = $this->receiptResponse();
        $receipt['data']['shopPayPaymentRequestReceipts']['edges'][0]['node']
            ['paymentRequest']['total']['amount'] = '1.00';
        $http = new MockHttpClient([
            new MockResponse(json_encode($this->sessionResponse(), JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode($this->submitResponse(), JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode($receipt, JSON_THROW_ON_ERROR)),
        ]);
        $service = $this->service($http);
        $order = $this->order();
        $service->createSession($order, '127.0.0.1');
        $service->submit($order, 'session-token-1', 'pm-test', '127.0.0.1');

        self::assertFalse($service->reconcile($order));
        self::assertSame('pending_payment', $this->connection->fetchOne('SELECT status FROM orders'));
        self::assertSame(
            'Receipt does not match the local order.',
            $this->connection->fetchOne('SELECT last_error FROM payment_sessions'),
        );
    }

    #[Test]
    public function webhook_verifies_hmac_and_deduplicates_deliveries(): void
    {
        $configuration = $this->configuration();
        $http = new MockHttpClient([
            new MockResponse(json_encode($this->sessionResponse(), JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode($this->submitResponse(), JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode($this->receiptResponse(), JSON_THROW_ON_ERROR)),
        ]);
        $service = $this->service($http, $configuration);
        $order = $this->order();
        $service->createSession($order, '127.0.0.1');
        $service->submit($order, 'session-token-1', 'pm-test', '127.0.0.1');
        $controller = new ShopifyWebhookController(
            $this->connection,
            $configuration,
            $service,
            $this->orders,
        );
        $payload = json_encode(
            ['source_identifier' => 'shop-pay:SP-SHOPPAY'],
            JSON_THROW_ON_ERROR,
        );
        $deliveryId = '79dc983f-5b84-40ad-b65d-1969e688d19c';
        $headers = [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac(
                'sha256',
                $payload,
                'app-secret',
                true,
            )),
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $deliveryId,
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
        ];

        $invalid = Request::create(
            '/api/v1/webhooks/shopify/orders',
            'POST',
            server: [...$headers, 'HTTP_X_SHOPIFY_HMAC_SHA256' => 'invalid'],
            content: $payload,
        );
        self::assertSame(401, $controller($invalid)->getStatusCode());

        $request = Request::create(
            '/api/v1/webhooks/shopify/orders',
            'POST',
            server: $headers,
            content: $payload,
        );
        self::assertSame(200, $controller($request)->getStatusCode());
        self::assertSame(200, $controller($request)->getStatusCode());
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM shopify_webhook_deliveries',
        ));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM orders'));
    }

    private function service(
        MockHttpClient $http,
        ?ShopPayConfiguration $configuration = null,
    ): ShopPayService
    {
        $configuration ??= $this->configuration();
        $codec = new PaymentPayloadCodec(
            new SodiumFieldCipher(str_repeat('n', 32)),
            new LegacyLaravelDecryptor(str_repeat('k', 32)),
        );

        return new ShopPayService(
            $this->connection,
            $configuration,
            new ShopPayStorefrontClient($http, $configuration),
            new ShopifyAdminClient($http, $configuration),
            new ShopPayPaymentRequestFactory(590, 1290, 5000),
            $codec,
            $this->orders,
            new MockClock('2026-01-01 12:00:00 UTC'),
        );
    }

    private function configuration(): ShopPayConfiguration
    {
        return new ShopPayConfiguration(
            true,
            'shop.test',
            '2026-07',
            '123',
            'client',
            'storefront-secret',
            'admin-secret',
            'app-secret',
        );
    }

    private function order(): array
    {
        return $this->orders->byNumber('SP-SHOPPAY')
            ?? throw new \RuntimeException('Order fixture missing.');
    }

    private function sessionResponse(): array
    {
        return ['data' => ['shopPayPaymentRequestSessionCreate' => [
            'shopPayPaymentRequestSession' => [
                'token' => 'session-token-1',
                'checkoutUrl' => 'https://shop.test/checkout',
                'sourceIdentifier' => 'shop-pay:SP-SHOPPAY',
            ],
            'userErrors' => [],
        ]]];
    }

    private function submitResponse(): array
    {
        return ['data' => ['shopPayPaymentRequestSessionSubmit' => [
            'paymentRequestReceipt' => [
                'token' => 'receipt-token-1',
                'processingStatusType' => 'PROCESSING',
            ],
            'userErrors' => [],
        ]]];
    }

    private function receiptResponse(): array
    {
        return ['data' => ['shopPayPaymentRequestReceipts' => ['edges' => [[
            'node' => [
                'token' => 'receipt-token-1',
                'sourceIdentifier' => 'shop-pay:SP-SHOPPAY',
                'processingStatus' => ['state' => 'COMPLETED'],
                'order' => ['id' => 'gid://shopify/Order/777'],
                'paymentRequest' => [
                    'total' => ['amount' => '134.36', 'currencyCode' => 'EUR'],
                ],
            ],
        ]]]]];
    }

    private function seedOrder(): void
    {
        $timestamps = [
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
        $this->connection->insert('categories', [
            'name' => 'Running',
            'slug' => 'running',
            ...$timestamps,
        ]);
        $this->connection->insert('products', [
            'category_id' => 1,
            'name' => 'Shoes',
            'slug' => 'shoes',
            'description' => 'Description',
            'price_cents_net' => 10000,
            'image_url' => '/shoe.jpg',
            ...$timestamps,
        ]);
        $this->connection->insert('product_variants', [
            'id' => 1,
            'product_id' => 1,
            'name' => '42',
            'sku' => 'SKU-42',
            'stock' => 1,
            ...$timestamps,
        ]);
        $this->connection->insert('orders', [
            'number' => 'SP-SHOPPAY',
            'email' => 'buyer@example.test',
            'phone' => '123456789',
            'status' => 'pending_payment',
            'delivery_method' => 'express',
            'subtotal_cents' => 11900,
            'delivery_cents' => 1536,
            'total_cents' => 13436,
            'currency' => 'EUR',
            'market' => 'eu_global',
            'locale' => 'en',
            'base_currency' => 'EUR',
            'exchange_rate' => '1.00000000',
            'vat_rate_basis_points' => 1900,
            'net_subtotal_cents' => 10000,
            'tax_cents' => 1900,
            'delivery_net_cents' => 1290,
            'delivery_tax_cents' => 246,
            ...$timestamps,
        ]);
        $this->connection->insert('order_addresses', [
            'order_id' => 1,
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'line1' => 'Street 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
            ...$timestamps,
        ]);
        $this->connection->insert('order_items', [
            'order_id' => 1,
            'product_variant_id' => 1,
            'name' => 'Shoes',
            'variant_name' => '42',
            'unit_price_cents' => 11900,
            'net_unit_price_cents' => 10000,
            'quantity' => 1,
            'line_total_cents' => 11900,
            'tax_cents' => 1900,
            ...$timestamps,
        ]);
    }
}
