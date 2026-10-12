<?php

namespace App\Infrastructure\Persistence\Doctrine\Cart;

use App\Application\Cart\CartException;
use App\Application\Commerce\MarketContext;
use App\Application\Commerce\PricingService;
use App\Application\Security\UserIdentity;
use App\Domain\Orders\Entities\OrderLine;
use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Infrastructure\Localization\TranslationCatalog;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final readonly class DbalCartService
{
    public function __construct(
        private Connection $connection,
        private Security $security,
        private PricingService $pricing,
        private TranslationCatalog $translations,
    ) {
    }

    public function data(Request $request, MarketContext $market): array
    {
        $cartId = $this->cartId($request);
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    ci.id,
    ci.product_variant_id,
    ci.quantity,
    v.name AS variant_name,
    v.name_translations AS variant_name_translations,
    v.price_cents_net AS variant_price_cents_net,
    p.name,
    p.name_translations,
    p.slug,
    p.price_cents_net,
    p.image_url
FROM cart_items ci
INNER JOIN product_variants v ON v.id = ci.product_variant_id
INNER JOIN products p ON p.id = v.product_id
WHERE ci.cart_id = :cart_id
ORDER BY ci.id
SQL,
            ['cart_id' => $cartId],
        );
        $items = array_map(
            fn (array $row): array => $this->item($row, $market->locale),
            $rows,
        );
        $data = [
            'items' => $items,
            'subtotal_cents' => array_sum(array_column($items, 'line_total_cents')),
            'count' => array_sum(array_column($items, 'quantity')),
        ];
        if ($items === []) {
            return $data;
        }

        $lines = array_map(
            fn (array $item): OrderLine => new OrderLine(
                $item['variant_id'],
                $item['name'],
                $item['variant_name'],
                $item['unit_price_cents'],
                $item['quantity'],
            ),
            $items,
        );

        try {
            $standard = $this->pricing->quote($lines, $market, DeliveryMethod::Standard);
            $express = $this->pricing->quote($lines, $market, DeliveryMethod::Express);
            $data['items'] = array_map(
                fn (OrderLine $line, int $index): array => [
                    ...$items[$index],
                    'net_unit_price_cents' => $line->netUnitPriceCents,
                    'unit_price_cents' => $line->unitPriceCents,
                    'tax_cents' => $line->taxCents,
                    'line_total_cents' => $line->totalCents(),
                ],
                $standard['lines'],
                array_keys($standard['lines']),
            );
            $data = [...$data, ...$this->withoutLines($standard)];
            $data['delivery_quotes'] = [
                'standard' => $this->withoutLines($standard),
                'express' => $this->withoutLines($express),
            ];
        } catch (\DomainException $exception) {
            $data['pricing_error'] = $exception->getMessage();
        }

        return $data;
    }

    public function add(Request $request, int $variantId, int $quantity): array
    {
        $cartId = $this->cartId($request);
        $this->connection->transactional(function () use ($cartId, $variantId, $quantity): void {
            $variant = $this->connection->fetchAssociative(
                <<<'SQL'
SELECT v.stock, p.active
FROM product_variants v
INNER JOIN products p ON p.id = v.product_id
WHERE v.id = :variant_id
FOR UPDATE
SQL,
                ['variant_id' => $variantId],
            );
            if ($variant === false) {
                throw new CartException('Product variant not found.');
            }
            if (!$this->boolean($variant['active']) || (int) $variant['stock'] < 1) {
                throw new CartException('This product is out of stock.');
            }

            $current = $this->connection->fetchOne(
                <<<'SQL'
SELECT quantity
FROM cart_items
WHERE cart_id = :cart_id AND product_variant_id = :variant_id
FOR UPDATE
SQL,
                ['cart_id' => $cartId, 'variant_id' => $variantId],
            );
            $next = min((int) $variant['stock'], 20, (int) ($current ?: 0) + $quantity);
            $now = $this->now();
            $this->connection->executeStatement(
                <<<'SQL'
INSERT INTO cart_items (
    cart_id,
    product_variant_id,
    quantity,
    created_at,
    updated_at
)
VALUES (
    :cart_id,
    :variant_id,
    :quantity,
    :created_at,
    :updated_at
)
ON CONFLICT (cart_id, product_variant_id) DO UPDATE SET
    quantity = EXCLUDED.quantity,
    updated_at = EXCLUDED.updated_at
SQL,
                [
                    'cart_id' => $cartId,
                    'variant_id' => $variantId,
                    'quantity' => $next,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        });

        return ['cart_id' => $cartId];
    }

    public function update(Request $request, int $itemId, int $quantity): void
    {
        $cartId = $this->cartId($request);
        $this->connection->transactional(function () use ($cartId, $itemId, $quantity): void {
            $item = $this->connection->fetchAssociative(
                <<<'SQL'
SELECT ci.product_variant_id, v.stock, p.active
FROM cart_items ci
INNER JOIN product_variants v ON v.id = ci.product_variant_id
INNER JOIN products p ON p.id = v.product_id
WHERE ci.id = :item_id AND ci.cart_id = :cart_id
FOR UPDATE
SQL,
                ['item_id' => $itemId, 'cart_id' => $cartId],
            );
            if ($item === false) {
                throw new CartException('Cart item not found.');
            }
            if ($quantity < 1 || !$this->boolean($item['active']) || (int) $item['stock'] < 1) {
                $this->connection->delete('cart_items', ['id' => $itemId, 'cart_id' => $cartId]);

                return;
            }

            $this->connection->update(
                'cart_items',
                [
                    'quantity' => min(20, (int) $item['stock'], $quantity),
                    'updated_at' => $this->now(),
                ],
                ['id' => $itemId, 'cart_id' => $cartId],
            );
        });
    }

    public function remove(Request $request, int $itemId): void
    {
        $cartId = $this->cartId($request);
        $deleted = $this->connection->delete('cart_items', [
            'id' => $itemId,
            'cart_id' => $cartId,
        ]);
        if ($deleted === 0) {
            throw new CartException('Cart item not found.');
        }
    }

    public function mergeGuestCart(Request $request, int $userId): void
    {
        $token = $request->getSession()->get('cart_token');
        if (!is_string($token)) {
            return;
        }

        $this->connection->transactional(function () use ($token, $userId): void {
            $guestId = $this->connection->fetchOne(
                'SELECT id FROM carts WHERE token = :token FOR UPDATE',
                ['token' => $token],
            );
            if ($guestId === false) {
                return;
            }

            $userCartId = $this->userCartId($userId);
            $items = $this->connection->fetchAllAssociative(
                'SELECT product_variant_id, quantity FROM cart_items WHERE cart_id = :cart_id',
                ['cart_id' => (int) $guestId],
            );
            foreach ($items as $item) {
                $now = $this->now();
                $this->connection->executeStatement(
                    <<<'SQL'
INSERT INTO cart_items (
    cart_id,
    product_variant_id,
    quantity,
    created_at,
    updated_at
)
VALUES (
    :cart_id,
    :variant_id,
    :quantity,
    :created_at,
    :updated_at
)
ON CONFLICT (cart_id, product_variant_id) DO UPDATE SET
    quantity = EXCLUDED.quantity,
    updated_at = EXCLUDED.updated_at
SQL,
                    [
                        'cart_id' => $userCartId,
                        'variant_id' => (int) $item['product_variant_id'],
                        'quantity' => (int) $item['quantity'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
            $this->connection->delete('carts', ['id' => (int) $guestId]);
        });
        $request->getSession()->remove('cart_token');
    }

    public function orderLines(Request $request, string $locale): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    ci.product_variant_id,
    ci.quantity,
    v.name AS variant_name,
    v.name_translations AS variant_name_translations,
    v.price_cents_net AS variant_price_cents_net,
    p.name,
    p.name_translations,
    p.slug,
    p.price_cents_net,
    p.active,
    v.stock
FROM cart_items ci
INNER JOIN product_variants v ON v.id = ci.product_variant_id
INNER JOIN products p ON p.id = v.product_id
WHERE ci.cart_id = :cart_id
ORDER BY ci.id
SQL,
            ['cart_id' => $this->cartId($request)],
        );

        return array_map(function (array $row) use ($locale): OrderLine {
            if (!$this->boolean($row['active']) || (int) $row['stock'] < (int) $row['quantity']) {
                throw new CartException("Product variant {$row['product_variant_id']} is unavailable.");
            }
            $productTranslations = $this->json($row['name_translations']);
            $variantTranslations = $this->json($row['variant_name_translations']);

            return new OrderLine(
                (int) $row['product_variant_id'],
                $this->translations->find("catalog.products.{$row['slug']}.name", $locale)
                    ?? $productTranslations[$locale]
                    ?? (string) $row['name'],
                $variantTranslations[$locale] ?? (string) $row['variant_name'],
                (int) ($row['variant_price_cents_net'] ?? $row['price_cents_net']),
                (int) $row['quantity'],
            );
        }, $rows);
    }

    public function clear(Request $request): void
    {
        $this->connection->delete('cart_items', ['cart_id' => $this->cartId($request)]);
    }

    public function cartId(Request $request): int
    {
        $user = $this->security->getUser();
        if ($user instanceof UserIdentity) {
            return $this->userCartId($user->id());
        }

        $token = $request->getSession()->get('cart_token');
        if (is_string($token)) {
            $cartId = $this->connection->fetchOne(
                'SELECT id FROM carts WHERE token = :token',
                ['token' => $token],
            );
            if ($cartId !== false) {
                return (int) $cartId;
            }
        }

        $token = Uuid::v4()->toRfc4122();
        $cartId = (int) $this->connection->fetchOne(
            <<<'SQL'
INSERT INTO carts (token, created_at, updated_at)
VALUES (:token, :created_at, :updated_at)
RETURNING id
SQL,
            [
                'token' => $token,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ],
        );
        $request->getSession()->set('cart_token', $token);

        return $cartId;
    }

    private function userCartId(int $userId): int
    {
        $cartId = $this->connection->fetchOne(
            'SELECT id FROM carts WHERE user_id = :user_id ORDER BY id LIMIT 1',
            ['user_id' => $userId],
        );
        if ($cartId !== false) {
            return (int) $cartId;
        }

        return (int) $this->connection->fetchOne(
            <<<'SQL'
INSERT INTO carts (user_id, created_at, updated_at)
VALUES (:user_id, :created_at, :updated_at)
RETURNING id
SQL,
            [
                'user_id' => $userId,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ],
        );
    }

    private function item(array $row, string $locale): array
    {
        $productTranslations = $this->json($row['name_translations']);
        $variantTranslations = $this->json($row['variant_name_translations']);
        $price = $row['variant_price_cents_net'] ?? $row['price_cents_net'];

        return [
            'id' => (int) $row['id'],
            'quantity' => (int) $row['quantity'],
            'variant_id' => (int) $row['product_variant_id'],
            'variant_name' => $variantTranslations[$locale] ?? (string) $row['variant_name'],
            'name' => $this->translations->find(
                "catalog.products.{$row['slug']}.name",
                $locale,
            ) ?? $productTranslations[$locale] ?? (string) $row['name'],
            'image_url' => (string) $row['image_url'],
            'unit_price_cents' => (int) $price,
            'line_total_cents' => (int) $price * (int) $row['quantity'],
        ];
    }

    private function withoutLines(array $quote): array
    {
        unset($quote['lines']);

        return $quote;
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

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
