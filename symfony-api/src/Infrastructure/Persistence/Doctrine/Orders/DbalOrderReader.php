<?php

namespace App\Infrastructure\Persistence\Doctrine\Orders;

use Doctrine\DBAL\Connection;

final readonly class DbalOrderReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function byNumber(string $number): ?array
    {
        $order = $this->connection->fetchAssociative(
            'SELECT * FROM orders WHERE number = :number',
            ['number' => $number],
        );
        if ($order === false) {
            return null;
        }

        $order['id'] = (int) $order['id'];
        $order['user_id'] = $order['user_id'] === null ? null : (int) $order['user_id'];
        foreach ([
            'subtotal_cents',
            'delivery_cents',
            'total_cents',
            'vat_rate_basis_points',
            'net_subtotal_cents',
            'tax_cents',
            'delivery_net_cents',
            'delivery_tax_cents',
        ] as $field) {
            $order[$field] = $order[$field] === null ? null : (int) $order[$field];
        }
        $order['exchange_rate'] = $order['exchange_rate'] === null
            ? null
            : (float) $order['exchange_rate'];
        $order['customer'] = $order['user_id'] === null
            ? null
            : ($this->connection->fetchAssociative(
                'SELECT id, name, email FROM users WHERE id = :id',
                ['id' => $order['user_id']],
            ) ?: null);
        $order['address'] = $this->connection->fetchAssociative(
            'SELECT * FROM order_addresses WHERE order_id = :order_id',
            ['order_id' => $order['id']],
        ) ?: null;
        $order['items'] = array_map(
            $this->item(...),
            $this->connection->fetchAllAssociative(
                <<<'SQL'
SELECT oi.*, v.sku
FROM order_items oi
LEFT JOIN product_variants v ON v.id = oi.product_variant_id
WHERE oi.order_id = :order_id
ORDER BY oi.id
SQL,
                ['order_id' => $order['id']],
            ),
        );

        return $order;
    }

    public function forUser(int $userId): array
    {
        $numbers = $this->connection->fetchFirstColumn(
            'SELECT number FROM orders WHERE user_id = :user_id ORDER BY created_at DESC, id DESC',
            ['user_id' => $userId],
        );

        return array_values(array_filter(array_map(
            fn (string $number): ?array => $this->byNumber($number),
            $numbers,
        )));
    }

    private function item(array $item): array
    {
        foreach ([
            'id',
            'order_id',
            'product_variant_id',
            'unit_price_cents',
            'net_unit_price_cents',
            'quantity',
            'line_total_cents',
            'tax_cents',
        ] as $field) {
            $item[$field] = $item[$field] === null ? null : (int) $item[$field];
        }

        return $item;
    }
}
