<?php

namespace App\Tests\Integration\Catalog;

use App\Infrastructure\Persistence\Doctrine\Catalog\DbalStorefrontCatalog;
use App\Infrastructure\Localization\TranslationCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DbalStorefrontCatalogTest extends KernelTestCase
{
    private Connection $connection;

    private DbalStorefrontCatalog $catalog;

    protected function setUp(): void
    {
        if (getenv('RUN_DB_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DB_TESTS=1 with an isolated PostgreSQL database.');
        }

        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->catalog = new DbalStorefrontCatalog(
            $this->connection,
            new TranslationCatalog(
                new ArrayAdapter(),
                dirname(__DIR__, 3).'/translations/store.yaml',
            ),
        );
        $this->connection->executeStatement(
            <<<'SQL'
TRUNCATE TABLE
    payment_attempts,
    payment_sessions,
    shopify_webhook_deliveries,
    saved_addresses,
    order_items,
    order_addresses,
    orders,
    cart_items,
    carts,
    product_specifications,
    product_images,
    product_variants,
    products,
    manufacturers,
    brands,
    categories,
    exchange_rates,
    password_reset_tokens,
    users
RESTART IDENTITY CASCADE
SQL,
        );
    }

    #[Test]
    public function category_counts_and_filters_include_descendants_and_exclude_inactive_products(): void
    {
        $this->category(1, 'Running', 'running');
        $this->category(2, 'Shoes', 'shoes', 1);
        $this->category(3, 'Trail', 'trail', 2);
        $this->category(4, 'Clothing', 'clothing', 1);
        $this->product(1, 1, 'Root', 'root');
        $this->product(2, 2, 'Road', 'road');
        $this->product(3, 3, 'Trail', 'trail-product');
        $this->product(4, 4, 'Top', 'top');
        $this->product(5, 3, 'Hidden', 'hidden', active: false);

        $categories = $this->indexBy($this->catalog->categories('en'), 'slug');
        self::assertSame(4, $categories['running']['products_count']);
        self::assertSame(2, $categories['shoes']['products_count']);
        self::assertSame(1, $categories['trail']['products_count']);
        self::assertSame(1, $categories['clothing']['products_count']);

        $page = $this->catalog->page(['category' => 'shoes'], 1, 12, 'en');
        self::assertSame(2, $page['total']);
        self::assertSame([3, 2], array_column($page['data'], 'id'));
    }

    #[Test]
    public function search_escapes_like_wildcards_and_sorting_and_pagination_are_stable(): void
    {
        $this->category(1, 'Running', 'running');
        $this->product(1, 1, '100% Runner', 'literal-percent', price: 100);
        $this->product(2, 1, '100X Runner', 'wildcard-lookalike', price: 200);
        $this->product(3, 1, 'Other', 'other', price: 300);

        $search = $this->catalog->page(['q' => '100%'], 1, 12, 'en');
        self::assertSame(1, $search['total']);
        self::assertSame('literal-percent', $search['data'][0]['slug']);

        $sorted = $this->catalog->page(['sort' => 'price_asc'], 2, 2, 'en');
        self::assertSame(3, $sorted['total']);
        self::assertSame(2, $sorted['last_page']);
        self::assertSame([3], array_column($sorted['data'], 'id'));
    }

    #[Test]
    public function detail_contains_localized_relations_gallery_specs_breadcrumbs_and_related_products(): void
    {
        $this->category(1, 'Sport', 'running');
        $this->category(2, 'Shoes', 'shoes', 1);
        $this->connection->insert('brands', $this->timestamps([
            'id' => 1,
            'name' => 'Brand',
            'slug' => 'brand',
        ]));
        $this->connection->insert('manufacturers', $this->timestamps([
            'id' => 1,
            'name' => 'Factory',
            'slug' => 'factory',
        ]));
        $this->product(
            1,
            2,
            'Cloudswift',
            'cloudswift-4',
            brandId: 1,
            manufacturerId: 1,
        );
        $this->product(2, 2, 'Related', 'related');
        $this->product(3, 2, 'Hidden', 'hidden', active: false);
        $this->variant(1, 1, '42', 2, ['ru' => 'Размер 42']);
        $this->connection->insert('product_images', $this->timestamps([
            'id' => 2,
            'product_id' => 1,
            'url' => '/second.jpg',
            'alt' => 'Second',
            'position' => 2,
        ]));
        $this->connection->insert('product_images', $this->timestamps([
            'id' => 1,
            'product_id' => 1,
            'url' => '/first.jpg',
            'alt' => null,
            'position' => 1,
        ]));
        $this->connection->insert('product_specifications', $this->timestamps([
            'id' => 1,
            'product_id' => 1,
            'name' => 'Weight',
            'value' => '300',
            'unit' => 'g',
            'position' => 0,
        ]));

        $detail = $this->catalog->detail('cloudswift-4', 'ru');

        self::assertNotNull($detail);
        self::assertSame('Cloudswift 4', $detail['product']['localized_name']);
        self::assertSame('Размер 42', $detail['product']['variants'][0]['localized_name']);
        self::assertTrue($detail['product']['available']);
        self::assertSame('Brand', $detail['product']['brand_record']['name']);
        self::assertSame('Factory', $detail['product']['manufacturer']['name']);
        self::assertSame(['/first.jpg', '/second.jpg'], array_column($detail['product']['images'], 'url'));
        self::assertSame('Weight', $detail['product']['specifications'][0]['name']);
        self::assertSame(['running', 'shoes'], array_column($detail['breadcrumbs'], 'slug'));
        self::assertSame([2], array_column($detail['related'], 'id'));
    }

    #[Test]
    public function inactive_and_unknown_products_are_not_exposed(): void
    {
        $this->category(1, 'Running', 'running');
        $this->product(1, 1, 'Hidden', 'hidden', active: false);

        self::assertNull($this->catalog->detail('hidden', 'en'));
        self::assertNull($this->catalog->detail('missing', 'en'));
        self::assertSame([], $this->catalog->featured('en'));
    }

    private function category(int $id, string $name, string $slug, ?int $parentId = null): void
    {
        $this->connection->insert('categories', $this->timestamps([
            'id' => $id,
            'parent_id' => $parentId,
            'name' => $name,
            'name_translations' => null,
            'slug' => $slug,
            'image_url' => null,
        ]));
    }

    private function product(
        int $id,
        int $categoryId,
        string $name,
        string $slug,
        bool $active = true,
        int $price = 1000,
        ?int $brandId = null,
        ?int $manufacturerId = null,
    ): void {
        $this->connection->insert(
            'products',
            $this->timestamps([
                'id' => $id,
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'manufacturer_id' => $manufacturerId,
                'name' => $name,
                'name_translations' => null,
                'slug' => $slug,
                'description' => 'Description',
                'description_translations' => null,
                'price_cents_net' => $price,
                'compare_at_price_cents_net' => null,
                'brand' => null,
                'image_url' => '/product.jpg',
                'featured' => false,
                'active' => $active,
            ]),
            [
                'featured' => ParameterType::BOOLEAN,
                'active' => ParameterType::BOOLEAN,
            ],
        );
    }

    private function variant(
        int $id,
        int $productId,
        string $name,
        int $stock,
        ?array $translations = null,
    ): void {
        $this->connection->insert('product_variants', $this->timestamps([
            'id' => $id,
            'product_id' => $productId,
            'name' => $name,
            'name_translations' => $translations === null ? null : json_encode($translations),
            'sku' => 'SKU-'.$id,
            'price_cents_net' => null,
            'stock' => $stock,
        ]));
    }

    private function timestamps(array $data): array
    {
        return [
            ...$data,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
    }

    private function indexBy(array $rows, string $key): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row[$key]] = $row;
        }

        return $indexed;
    }
}
