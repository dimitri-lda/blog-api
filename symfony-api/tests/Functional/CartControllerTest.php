<?php

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CartControllerTest extends WebTestCase
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
    cart_items,
    carts,
    product_variants,
    products,
    categories
RESTART IDENTITY CASCADE
SQL,
        );
        $this->connection->insert('categories', $this->timestamps([
            'id' => 1,
            'parent_id' => null,
            'name' => 'Running',
            'name_translations' => null,
            'slug' => 'running',
            'image_url' => null,
        ]));
        $this->connection->insert(
            'products',
            $this->timestamps([
                'id' => 1,
                'category_id' => 1,
                'brand_id' => null,
                'manufacturer_id' => null,
                'name' => 'Shoes',
                'name_translations' => null,
                'slug' => 'shoes',
                'description' => 'Description',
                'description_translations' => null,
                'price_cents_net' => 10_000,
                'compare_at_price_cents_net' => null,
                'brand' => null,
                'image_url' => '/shoe.jpg',
                'featured' => false,
                'active' => true,
            ]),
            [
                'featured' => ParameterType::BOOLEAN,
                'active' => ParameterType::BOOLEAN,
            ],
        );
        $this->connection->insert('product_variants', $this->timestamps([
            'id' => 1,
            'product_id' => 1,
            'name' => '42',
            'name_translations' => null,
            'sku' => 'SHOE-42',
            'price_cents_net' => null,
            'stock' => 3,
        ]));
        $this->client->request('GET', '/api/v1/context');
        $this->client->setServerParameter(
            'HTTP_X_CSRF_TOKEN',
            $this->payload($this->client)['data']['csrf_token'],
        );
    }

    public function test_guest_can_add_update_and_remove_an_item_without_reserving_stock(): void
    {
        $this->client->request('GET', '/api/v1/cart');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->payload($this->client)['data']['count']);

        $this->client->jsonRequest('POST', '/api/v1/cart/items', [
            'variant_id' => 1,
            'quantity' => 2,
        ]);
        self::assertResponseStatusCodeSame(201);
        $cart = $this->payload($this->client)['data'];
        self::assertSame(2, $cart['count']);
        self::assertSame(11_900, $cart['items'][0]['unit_price_cents']);
        self::assertSame(23_800, $cart['items'][0]['line_total_cents']);
        self::assertSame('EUR', $cart['currency']);
        self::assertSame(3, (int) $this->connection->fetchOne(
            'SELECT stock FROM product_variants WHERE id = 1',
        ));

        $this->client->jsonRequest('POST', '/api/v1/cart/items', [
            'variant_id' => 1,
            'quantity' => 2,
        ]);
        self::assertResponseStatusCodeSame(201);
        $cart = $this->payload($this->client)['data'];
        self::assertSame(3, $cart['count']);

        $itemId = $cart['items'][0]['id'];
        $this->client->jsonRequest('PATCH', "/api/v1/cart/items/{$itemId}", ['quantity' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload($this->client)['data']['count']);

        $this->client->request('DELETE', "/api/v1/cart/items/{$itemId}");
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->payload($this->client)['data']['count']);
    }

    public function test_out_of_stock_variant_is_rejected_and_not_added(): void
    {
        $this->connection->update('product_variants', ['stock' => 0], ['id' => 1]);

        $this->client->jsonRequest('POST', '/api/v1/cart/items', [
            'variant_id' => 1,
            'quantity' => 1,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('This product is out of stock.', $this->payload($this->client)['detail']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cart_items'));
    }

    public function test_a_guest_cannot_update_an_item_from_another_guest_cart(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/cart/items', [
            'variant_id' => 1,
            'quantity' => 1,
        ]);
        $otherCartId = (int) $this->connection->fetchOne(
            <<<'SQL'
INSERT INTO carts (token, created_at, updated_at)
VALUES ('61ddafcc-1d35-4fc6-b15f-5c2fac536771', NOW(), NOW())
RETURNING id
SQL,
        );
        $otherItemId = (int) $this->connection->fetchOne(
            <<<'SQL'
INSERT INTO cart_items (cart_id, product_variant_id, quantity, created_at, updated_at)
VALUES (:cart_id, 1, 1, NOW(), NOW())
RETURNING id
SQL,
            ['cart_id' => $otherCartId],
        );

        $this->client->jsonRequest('PATCH', "/api/v1/cart/items/{$otherItemId}", ['quantity' => 2]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Cart item not found.', $this->payload($this->client)['detail']);
    }

    private function timestamps(array $data): array
    {
        return [
            ...$data,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
    }

    private function payload(KernelBrowser $client): array
    {
        return json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
