<?php

namespace App\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogControllerTest extends WebTestCase
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
    product_specifications,
    product_images,
    product_variants,
    products,
    manufacturers,
    brands,
    categories,
    exchange_rates
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
                'featured' => true,
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
            'stock' => 2,
        ]));
        $this->client->request('GET', '/api/v1/context');
        $this->client->setServerParameter(
            'HTTP_X_CSRF_TOKEN',
            $this->payload($this->client)['data']['csrf_token'],
        );
    }

    public function test_home_catalog_and_detail_return_priced_public_resources(): void
    {
        $client = $this->client;
        $client->request('GET', '/api/v1/store/home');
        self::assertResponseIsSuccessful();
        $home = $this->payload($client)['data'];
        self::assertSame(1, $home['categories'][0]['products_count']);
        self::assertSame(11_900, $home['featured'][0]['display_price']['gross_cents']);

        $client->request('GET', '/api/v1/products?category=running&q=Shoes&sort=price_asc');
        self::assertResponseIsSuccessful();
        $catalog = $this->payload($client);
        self::assertSame(1, $catalog['meta']['total']);
        self::assertSame('shoes', $catalog['data'][0]['slug']);
        self::assertSame('running', $catalog['filters']['category']);

        $client->request('GET', '/api/v1/products/shoes');
        self::assertResponseIsSuccessful();
        $detail = $this->payload($client)['data'];
        self::assertSame('shoes', $detail['product']['slug']);
        self::assertSame(11_900, $detail['product']['variants'][0]['display_price']['gross_cents']);
        self::assertSame(['running'], array_column($detail['breadcrumbs'], 'slug'));
    }

    public function test_saved_market_changes_currency_and_vat_without_changing_catalog_data(): void
    {
        $this->connection->insert('exchange_rates', $this->timestamps([
            'currency' => 'PLN',
            'rate' => '4.00000000',
            'source' => 'test',
            'quoted_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]));
        $client = $this->client;
        $client->jsonRequest('PUT', '/api/v1/preferences', [
            'country' => 'PL',
            'locale' => 'pl',
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/products');
        self::assertResponseIsSuccessful();
        $product = $this->payload($client)['data'][0];
        self::assertSame('PLN', $product['display_price']['currency']);
        self::assertSame(40_000, $product['display_price']['net_cents']);
        self::assertSame(49_200, $product['display_price']['gross_cents']);
        self::assertSame('shoes', $product['slug']);
    }

    public function test_unknown_and_inactive_resources_return_not_found(): void
    {
        $client = $this->client;
        $client->request('GET', '/api/v1/products/missing');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Product not found.', $this->payload($client)['detail']);

        $client->request('GET', '/api/v1/products?category=missing');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Category not found.', $this->payload($client)['detail']);
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
