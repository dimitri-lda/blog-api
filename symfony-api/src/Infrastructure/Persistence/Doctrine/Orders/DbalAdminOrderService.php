<?php

namespace App\Infrastructure\Persistence\Doctrine\Orders;

use App\Domain\Orders\ValueObjects\OrderStatus;
use App\Infrastructure\Cache\StorefrontCache;
use App\Infrastructure\Localization\TranslationCatalog;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use DomainException;

final readonly class DbalAdminOrderService
{
    public function __construct(
        private Connection $connection,
        private DbalOrderReader $reader,
        private StorefrontCache $cache,
        private TranslationCatalog $translations,
        private array $locales,
    ) {
    }

    public function page(?string $search, ?OrderStatus $status, int $page, int $perPage): array
    {
        $where = [];
        $parameters = [];
        if ($search !== null && trim($search) !== '') {
            $where[] = "(o.number LIKE :search ESCAPE '\\' OR o.email LIKE :search ESCAPE '\\')";
            $parameters['search'] = $this->likePattern(trim($search));
        }
        if ($status !== null) {
            $where[] = 'o.status = :status';
            $parameters['status'] = $status->value;
        }
        $condition = $where === [] ? '' : 'WHERE '.implode(' AND ', $where);
        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM orders o {$condition}",
            $parameters,
        );
        $parameters['limit'] = $perPage;
        $parameters['offset'] = ($page - 1) * $perPage;
        $types = [
            'limit' => ParameterType::INTEGER,
            'offset' => ParameterType::INTEGER,
        ];
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
SELECT
    o.id,
    o.number,
    o.email,
    o.status,
    o.total_cents,
    o.currency,
    o.created_at,
    COUNT(oi.id) AS items_count
FROM orders o
LEFT JOIN order_items oi ON oi.order_id = o.id
{$condition}
GROUP BY o.id
ORDER BY o.created_at DESC, o.id DESC
LIMIT :limit OFFSET :offset
SQL,
            $parameters,
            $types,
        );

        return [
            'data' => array_map($this->summary(...), $rows),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function details(int $id): ?array
    {
        $number = $this->connection->fetchOne(
            'SELECT number FROM orders WHERE id = :id',
            ['id' => $id],
        );

        return $number === false ? null : $this->reader->byNumber((string) $number);
    }

    public function changeStatus(int $id, OrderStatus $target): array
    {
        $result = $this->connection->transactional(function () use ($id, $target): array {
            $order = $this->connection->fetchAssociative(
                'SELECT * FROM orders WHERE id = :id FOR UPDATE',
                ['id' => $id],
            );
            if ($order === false) {
                throw new DomainException('Order not found.');
            }

            $current = OrderStatus::from((string) $order['status']);
            $next = $current->transitionTo($target);
            $releasedAt = $order['inventory_released_at'];
            $affectedProducts = [];
            if (
                $next === OrderStatus::Cancelled
                && $order['inventory_reserved_at'] !== null
                && $releasedAt === null
            ) {
                $release = $this->releaseInventory($id);
                $releasedAt = $release['released_at'];
                $affectedProducts = $release['products'];
            }

            $this->connection->update(
                'orders',
                [
                    'status' => $next->value,
                    'inventory_released_at' => $releasedAt,
                    'updated_at' => $this->now(),
                ],
                ['id' => $id],
            );

            return [
                'status' => $next,
                'next_statuses' => array_map(
                    fn (OrderStatus $status): string => $status->value,
                    $next->allowedNextStatuses(),
                ),
                'affected_products' => $affectedProducts,
            ];
        });
        $this->forgetProductCards($result['affected_products']);

        return $result;
    }

    private function releaseInventory(int $orderId): array
    {
        $items = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT oi.product_variant_id, oi.quantity, p.id AS product_id, p.slug
FROM order_items oi
INNER JOIN product_variants v ON v.id = oi.product_variant_id
INNER JOIN products p ON p.id = v.product_id
WHERE oi.order_id = :order_id AND oi.product_variant_id IS NOT NULL
SQL,
            ['order_id' => $orderId],
        );
        $variantIds = array_values(array_unique(array_map(
            fn (array $item): int => (int) $item['product_variant_id'],
            $items,
        )));
        if ($variantIds !== []) {
            $this->connection->fetchFirstColumn(
                'SELECT id FROM product_variants WHERE id IN (:ids) FOR UPDATE',
                ['ids' => $variantIds],
                ['ids' => ArrayParameterType::INTEGER],
            );
        }
        foreach ($items as $item) {
            $this->connection->executeStatement(
                'UPDATE product_variants SET stock = stock + :quantity, updated_at = :updated_at WHERE id = :id',
                [
                    'quantity' => (int) $item['quantity'],
                    'updated_at' => $this->now(),
                    'id' => (int) $item['product_variant_id'],
                ],
            );
        }

        return [
            'released_at' => $this->now(),
            'products' => array_map(
                fn (array $item): array => [
                    'id' => (int) $item['product_id'],
                    'slug' => (string) $item['slug'],
                ],
                $items,
            ),
        ];
    }

    private function summary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'number' => (string) $row['number'],
            'email' => (string) $row['email'],
            'status' => (string) $row['status'],
            'total_cents' => (int) $row['total_cents'],
            'currency' => (string) $row['currency'],
            'items_count' => (int) $row['items_count'],
            'created_at' => $row['created_at'],
        ];
    }

    private function likePattern(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }

    private function forgetProductCards(array $products): void
    {
        $version = $this->translations->version();
        $keys = [];
        foreach ($products as $product) {
            foreach ($this->locales as $locale) {
                $keys[] = "card.{$product['id']}.{$locale}.{$version}";
                $keys[] = "detail.{$product['slug']}.{$locale}.{$version}";
            }
        }
        $this->cache->forget('card', array_values(array_unique($keys)));
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
