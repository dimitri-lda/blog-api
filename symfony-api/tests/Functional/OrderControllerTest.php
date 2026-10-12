<?php

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderControllerTest extends WebTestCase
{
    private Connection $connection;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        if (getenv('RUN_DB_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DB_TESTS=1 with an isolated PostgreSQL database.');
        }

        $this->client = self::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement(
            <<<'SQL'
TRUNCATE TABLE
    order_items,
    order_addresses,
    orders,
    cart_items,
    carts,
    product_variants,
    products,
    categories
RESTART IDENTITY CASCADE
SQL,
        );
        $this->seedCatalog();
        $this->enableCsrf();
    }

    public function test_guest_checkout_reserves_stock_clears_cart_and_exposes_last_order(): void
    {
        $this->client->request('GET', '/api/v1/products/shoes');
        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->payload()['data']['product']['variants'][0]['stock']);

        $this->addToCart(2);
        $this->client->jsonRequest('POST', '/api/v1/orders', $this->checkoutData());

        self::assertResponseStatusCodeSame(201);
        $number = $this->payload()['data']['number'];
        self::assertMatchesRegularExpression('/^SP-[A-F0-9]{8}$/', $number);
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT stock FROM product_variants WHERE id = 1',
        ));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cart_items'));
        self::assertSame(23_800, (int) $this->connection->fetchOne(
            'SELECT total_cents FROM orders WHERE number = :number',
            ['number' => $number],
        ));
        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT quantity FROM order_items LIMIT 1',
        ));

        $this->client->request('GET', '/api/v1/products/shoes');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['data']['product']['variants'][0]['stock']);

        $this->client->request('GET', "/api/v1/orders/{$number}");
        self::assertResponseIsSuccessful();
        $order = $this->payload()['data'];
        self::assertSame($number, $order['number']);
        self::assertSame('pending_payment', $order['status']);
        self::assertSame('Berlin', $order['address']['city']);
        self::assertSame(2, $order['items'][0]['quantity']);
    }

    public function test_empty_cart_and_unavailable_inventory_are_rejected_without_an_order(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/orders', $this->checkoutData());
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['An order cannot be empty.'], $this->payload()['errors']['cart']);

        $this->addToCart(1);
        $this->connection->update('product_variants', ['stock' => 0], ['id' => 1]);
        $this->client->jsonRequest('POST', '/api/v1/orders', $this->checkoutData());
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['Product variant 1 is unavailable.'],
            $this->payload()['errors']['cart'],
        );
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM orders'));
    }

    public function test_order_is_hidden_without_owner_or_the_creating_guest_session(): void
    {
        $this->connection->insert('orders', [
            'number' => 'SP-HIDDEN01',
            'email' => 'buyer@example.test',
            'phone' => '123456789',
            'status' => 'pending_payment',
            'delivery_method' => 'standard',
            'subtotal_cents' => 100,
            'delivery_cents' => 0,
            'total_cents' => 100,
            'currency' => 'EUR',
            'base_currency' => 'EUR',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->client->request('GET', '/api/v1/orders/SP-HIDDEN01');

        self::assertResponseStatusCodeSame(404);
    }

    private function addToCart(int $quantity): void
    {
        $this->client->jsonRequest('POST', '/api/v1/cart/items', [
            'variant_id' => 1,
            'quantity' => $quantity,
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    private function checkoutData(): array
    {
        return [
            'email' => 'buyer@example.test',
            'phone' => '123456789',
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'line1' => 'Test 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
            'delivery_method' => 'standard',
        ];
    }

    private function seedCatalog(): void
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
        $categoryId = (int) $this->connection->lastInsertId();
        $this->connection->insert('products', [
            'category_id' => $categoryId,
            'name' => 'Shoes',
            'slug' => 'shoes',
            'description' => 'Description',
            'price_cents_net' => 10_000,
            'image_url' => '/shoe.jpg',
            'featured' => false,
            'active' => true,
            ...$timestamps,
        ], [
            'featured' => ParameterType::BOOLEAN,
            'active' => ParameterType::BOOLEAN,
        ]);
        $productId = (int) $this->connection->lastInsertId();
        $this->connection->insert('product_variants', [
            'id' => 1,
            'product_id' => $productId,
            'name' => '42',
            'sku' => 'SHOE-42',
            'stock' => 3,
            ...$timestamps,
        ]);
    }

    private function enableCsrf(): void
    {
        $this->client->request('GET', '/api/v1/context');
        $this->client->setServerParameter(
            'HTTP_X_CSRF_TOKEN',
            $this->payload()['data']['csrf_token'],
        );
    }

    private function payload(): array
    {
        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
