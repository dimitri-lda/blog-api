<?php

namespace App\Infrastructure\Persistence\Doctrine\Catalog;

use App\Application\Catalog\CategoryNotFound;
use App\Application\Catalog\StorefrontCatalog;
use App\Infrastructure\Localization\TranslationCatalog;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DbalStorefrontCatalog implements StorefrontCatalog
{
    public function __construct(
        private Connection $connection,
        private TranslationCatalog $translations,
    ) {
    }

    public function categories(string $locale): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT c.*, COUNT(p.id) FILTER (WHERE p.active = TRUE) AS direct_products_count
FROM categories c
LEFT JOIN products p ON p.category_id = c.id
GROUP BY c.id
ORDER BY c.name
SQL,
        );
        $categories = [];
        $directCounts = [];
        $children = [];

        foreach ($rows as $row) {
            $category = $this->category($row, $locale);
            $id = $category['id'];
            $categories[$id] = $category;
            $directCounts[$id] = (int) $row['direct_products_count'];
            $parentId = $category['parent_id'];
            if ($parentId !== null) {
                $children[$parentId][] = $id;
            }
        }

        foreach ($categories as $id => &$category) {
            $category['products_count'] = $this->categoryCount($id, $children, $directCounts);
        }
        unset($category);

        return array_values($categories);
    }

    public function featured(string $locale, int $limit = 8): array
    {
        return $this->cards($this->featuredIds($limit), $locale);
    }

    public function featuredIds(int $limit = 8): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM products WHERE featured = TRUE AND active = TRUE LIMIT :limit',
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        ));
    }

    public function page(array $filters, int $page, int $perPage, string $locale): array
    {
        $index = $this->pageIndex($filters, $page, $perPage);

        return [
            ...$index,
            'data' => $this->cards($index['ids'], $locale),
        ];
    }

    public function pageIndex(array $filters, int $page, int $perPage): array
    {
        $query = $this->productListQuery($filters);
        $count = clone $query;
        $total = (int) $count
            ->select('COUNT(DISTINCT p.id)')
            ->resetOrderBy()
            ->fetchOne();

        if (in_array($filters['sort'] ?? null, ['price_asc', 'price_desc'], true)) {
            $query
                ->orderBy('p.price_cents_net', $filters['sort'] === 'price_asc' ? 'ASC' : 'DESC')
                ->addOrderBy('p.id', 'ASC');
        } else {
            $query
                ->orderBy('p.created_at', 'DESC')
                ->addOrderBy('p.id', 'DESC');
        }

        $ids = $query
            ->select('p.id')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->fetchFirstColumn();

        return [
            'ids' => array_map('intval', $ids),
            'total' => $total,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function detail(string $slug, string $locale): ?array
    {
        $detail = $this->detailIndex($slug, $locale);
        if ($detail === null) {
            return null;
        }

        return [
            'product' => $detail['product'],
            'breadcrumbs' => $detail['breadcrumbs'],
            'related' => $this->cards($detail['related_ids'], $locale),
        ];
    }

    public function detailIndex(string $slug, string $locale): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, category_id FROM products WHERE slug = :slug AND active = TRUE',
            ['slug' => $slug],
        );
        if ($row === false) {
            return null;
        }

        $productId = (int) $row['id'];
        $products = $this->cards([$productId], $locale, true);
        if ($products === []) {
            return null;
        }

        $relatedIds = $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT id
FROM products
WHERE active = TRUE AND category_id = :category_id AND id <> :id
ORDER BY id
LIMIT 4
SQL,
            ['category_id' => (int) $row['category_id'], 'id' => $productId],
        );

        return [
            'product' => $products[0],
            'breadcrumbs' => $this->breadcrumbs((int) $row['category_id'], $locale),
            'related_ids' => array_map('intval', $relatedIds),
        ];
    }

    private function productListQuery(array $filters): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()
            ->from('products', 'p')
            ->leftJoin('p', 'brands', 'b', 'b.id = p.brand_id')
            ->where('p.active = TRUE');

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query
                ->andWhere(
                    "(p.name LIKE :search ESCAPE '\\' "
                    ."OR p.brand LIKE :search ESCAPE '\\' "
                    ."OR b.name LIKE :search ESCAPE '\\')",
                )
                ->setParameter('search', $this->likePattern($search));
        }

        $categorySlug = trim((string) ($filters['category'] ?? ''));
        if ($categorySlug !== '') {
            $categoryIds = $this->categoryIds($categorySlug);
            $query
                ->andWhere('p.category_id IN (:category_ids)')
                ->setParameter('category_ids', $categoryIds, ArrayParameterType::INTEGER);
        }

        return $query;
    }

    private function categoryIds(string $slug): array
    {
        $ids = $this->connection->fetchFirstColumn(
            <<<'SQL'
WITH RECURSIVE descendants AS (
    SELECT id
    FROM categories
    WHERE slug = :slug
    UNION
    SELECT child.id
    FROM categories child
    INNER JOIN descendants parent ON child.parent_id = parent.id
)
SELECT id FROM descendants
SQL,
            ['slug' => $slug],
        );
        if ($ids === []) {
            throw new CategoryNotFound($slug);
        }

        return array_map('intval', $ids);
    }

    public function cards(array $ids, string $locale, bool $details = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM products WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $categoryIds = [];
        $brandIds = [];
        $manufacturerIds = [];

        foreach ($rows as $row) {
            $categoryIds[] = (int) $row['category_id'];
            if ($row['brand_id'] !== null) {
                $brandIds[] = (int) $row['brand_id'];
            }
            if ($row['manufacturer_id'] !== null) {
                $manufacturerIds[] = (int) $row['manufacturer_id'];
            }
        }

        $categories = $this->recordsById('categories', $categoryIds);
        $brands = $this->recordsById('brands', $brandIds);
        $manufacturers = $this->recordsById('manufacturers', $manufacturerIds);
        $variants = $this->groupedByProduct('product_variants', $ids, 'id');
        $images = $details ? $this->groupedByProduct('product_images', $ids, 'position, id') : [];
        $specifications = $details
            ? $this->groupedByProduct('product_specifications', $ids, 'position, id')
            : [];
        $byId = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $productVariants = array_map(
                fn (array $variant): array => $this->variant($variant, $locale),
                $variants[$id] ?? [],
            );
            $category = $categories[(int) $row['category_id']] ?? null;
            $product = $this->product($row, $locale);
            $product['category'] = $category === null ? null : $this->category($category, $locale);
            $product['variants'] = $productVariants;
            $product['brand_record'] = $row['brand_id'] === null
                ? null
                : $this->dictionary($brands[(int) $row['brand_id']] ?? null);
            $product['manufacturer'] = $row['manufacturer_id'] === null
                ? null
                : $this->dictionary($manufacturers[(int) $row['manufacturer_id']] ?? null);
            $product['available'] = $product['active'] && array_any(
                $productVariants,
                fn (array $variant): bool => $variant['stock'] > 0,
            );

            if ($details) {
                $product['images'] = array_map($this->image(...), $images[$id] ?? []);
                $product['specifications'] = array_map(
                    $this->specification(...),
                    $specifications[$id] ?? [],
                );
            }
            $byId[$id] = $product;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    private function recordsById(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$table} WHERE id IN (:ids)",
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $records = [];
        foreach ($rows as $row) {
            $records[(int) $row['id']] = $row;
        }

        return $records;
    }

    private function groupedByProduct(string $table, array $productIds, string $orderBy): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$table} WHERE product_id IN (:ids) ORDER BY {$orderBy}",
            ['ids' => $productIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['product_id']][] = $row;
        }

        return $grouped;
    }

    private function breadcrumbs(int $categoryId, string $locale): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM categories');
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $breadcrumbs = [];
        $seen = [];
        while (isset($byId[$categoryId]) && !isset($seen[$categoryId])) {
            $seen[$categoryId] = true;
            $category = $this->category($byId[$categoryId], $locale);
            array_unshift($breadcrumbs, $category);
            $categoryId = $category['parent_id'] ?? 0;
        }

        return $breadcrumbs;
    }

    private function category(array $row, string $locale): array
    {
        $translations = $this->json($row['name_translations'] ?? null);
        $slug = (string) $row['slug'];

        return [
            'id' => (int) $row['id'],
            'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'name' => (string) $row['name'],
            'name_translations' => $translations,
            'slug' => $slug,
            'image_url' => $row['image_url'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'localized_name' => $this->translations->find("catalog.categories.{$slug}.name", $locale)
                ?? $translations[$locale]
                ?? (string) $row['name'],
        ];
    }

    private function product(array $row, string $locale): array
    {
        $nameTranslations = $this->json($row['name_translations']);
        $descriptionTranslations = $this->json($row['description_translations']);
        $slug = (string) $row['slug'];

        return [
            'id' => (int) $row['id'],
            'category_id' => (int) $row['category_id'],
            'brand_id' => $row['brand_id'] === null ? null : (int) $row['brand_id'],
            'manufacturer_id' => $row['manufacturer_id'] === null
                ? null
                : (int) $row['manufacturer_id'],
            'name' => (string) $row['name'],
            'name_translations' => $nameTranslations,
            'slug' => $slug,
            'description' => (string) $row['description'],
            'description_translations' => $descriptionTranslations,
            'price_cents_net' => (int) $row['price_cents_net'],
            'compare_at_price_cents_net' => $row['compare_at_price_cents_net'] === null
                ? null
                : (int) $row['compare_at_price_cents_net'],
            'brand' => $row['brand'],
            'image_url' => (string) $row['image_url'],
            'featured' => $this->boolean($row['featured']),
            'active' => $this->boolean($row['active']),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'localized_name' => $this->translations->find("catalog.products.{$slug}.name", $locale)
                ?? $nameTranslations[$locale]
                ?? (string) $row['name'],
            'localized_description' => $this->translations->find(
                "catalog.products.{$slug}.description",
                $locale,
            ) ?? $descriptionTranslations[$locale] ?? (string) $row['description'],
        ];
    }

    private function variant(array $row, string $locale): array
    {
        $translations = $this->json($row['name_translations']);

        return [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'name' => (string) $row['name'],
            'name_translations' => $translations,
            'sku' => (string) $row['sku'],
            'price_cents_net' => $row['price_cents_net'] === null
                ? null
                : (int) $row['price_cents_net'],
            'stock' => (int) $row['stock'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'localized_name' => $translations[$locale] ?? (string) $row['name'],
        ];
    }

    private function dictionary(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function image(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'url' => (string) $row['url'],
            'alt' => $row['alt'],
            'position' => (int) $row['position'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function specification(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'name' => (string) $row['name'],
            'value' => (string) $row['value'],
            'unit' => $row['unit'],
            'position' => (int) $row['position'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function categoryCount(
        int $id,
        array $children,
        array $directCounts,
        array $seen = [],
    ): int {
        if (isset($seen[$id])) {
            return 0;
        }
        $seen[$id] = true;
        $count = $directCounts[$id] ?? 0;
        foreach ($children[$id] ?? [] as $childId) {
            $count += $this->categoryCount($childId, $children, $directCounts, $seen);
        }

        return $count;
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function likePattern(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
