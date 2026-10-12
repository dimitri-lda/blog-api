<?php

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminOrderControllerTest extends WebTestCase
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
    product_variants,
    products,
    categories,
    users
RESTART IDENTITY CASCADE
SQL,
        );
        $this->seed();
    }

    public function test_regular_user_is_forbidden_from_admin_orders(): void
    {
        $this->login('user@example.test');

        $this->client->request('GET', '/api/v1/admin/orders');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_admin_can_list_filter_and_view_order_details(): void
    {
        $this->login('admin@example.test');

        $this->client->request('GET', '/api/v1/admin/orders?q=SP-ADMIN&status=pending_payment');
        self::assertResponseIsSuccessful();
        $page = $this->payload();
        self::assertSame(1, $page['meta']['total']);
        self::assertSame('SP-ADMIN01', $page['data'][0]['number']);
        self::assertSame(1, $page['data'][0]['items_count']);

        $orderId = $page['data'][0]['id'];
        $this->client->request('GET', "/api/v1/admin/orders/{$orderId}");
        self::assertResponseIsSuccessful();
        $detail = $this->payload()['data'];
        self::assertSame('SP-ADMIN01', $detail['order']['number']);
        self::assertSame(['paid', 'cancelled'], $detail['next_statuses']);
    }

    public function test_cancelling_releases_reserved_stock_once_and_invalid_transition_is_rejected(): void
    {
        $this->login('admin@example.test');
        $this->client->request('GET', '/api/v1/products/shoes');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['data']['product']['variants'][0]['stock']);
        $orderId = (int) $this->connection->fetchOne(
            'SELECT id FROM orders WHERE number = :number',
            ['number' => 'SP-ADMIN01'],
        );

        $this->client->jsonRequest('PATCH', "/api/v1/admin/orders/{$orderId}/status", [
            'status' => 'cancelled',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('cancelled', $this->payload()['data']['status']);
        self::assertSame(3, (int) $this->connection->fetchOne(
            'SELECT stock FROM product_variants WHERE id = 1',
        ));
        $this->client->request('GET', '/api/v1/products/shoes');
        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->payload()['data']['product']['variants'][0]['stock']);
        self::assertNotNull($this->connection->fetchOne(
            'SELECT inventory_released_at FROM orders WHERE id = :id',
            ['id' => $orderId],
        ));

        $this->client->jsonRequest('PATCH', "/api/v1/admin/orders/{$orderId}/status", [
            'status' => 'cancelled',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(3, (int) $this->connection->fetchOne(
            'SELECT stock FROM product_variants WHERE id = 1',
        ));
    }

    private function login(string $email): void
    {
        $this->client->request('GET', '/api/v1/context');
        $this->client->setServerParameter(
            'HTTP_X_CSRF_TOKEN',
            $this->payload()['data']['csrf_token'],
        );
        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'password',
        ]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/context');
        $this->client->setServerParameter(
            'HTTP_X_CSRF_TOKEN',
            $this->payload()['data']['csrf_token'],
        );
    }

    private function seed(): void
    {
        $timestamps = [
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
        foreach (['user' => 'user@example.test', 'admin' => 'admin@example.test'] as $role => $email) {
            $this->connection->insert('users', [
                'name' => ucfirst($role),
                'email' => $email,
                'password' => password_hash('password', PASSWORD_BCRYPT),
                'accepted_terms' => true,
                'terms_accepted_at' => '2026-01-01 00:00:00',
                'role' => $role,
                ...$timestamps,
            ], ['accepted_terms' => ParameterType::BOOLEAN]);
        }
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
            'price_cents_net' => 1000,
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
            'stock' => 1,
            ...$timestamps,
        ]);
        $this->connection->insert('orders', [
            'number' => 'SP-ADMIN01',
            'email' => 'buyer@example.test',
            'phone' => '123456789',
            'status' => 'pending_payment',
            'delivery_method' => 'standard',
            'subtotal_cents' => 2000,
            'delivery_cents' => 0,
            'total_cents' => 2000,
            'currency' => 'EUR',
            'base_currency' => 'EUR',
            'inventory_reserved_at' => '2026-01-01 00:00:00',
            ...$timestamps,
        ]);
        $orderId = (int) $this->connection->lastInsertId();
        $this->connection->insert('order_items', [
            'order_id' => $orderId,
            'product_variant_id' => 1,
            'name' => 'Shoes',
            'variant_name' => '42',
            'unit_price_cents' => 1000,
            'quantity' => 2,
            'line_total_cents' => 2000,
            ...$timestamps,
        ]);
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
