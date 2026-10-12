<?php

namespace App\Application\Orders;

use App\Application\Commerce\MarketContext;
use App\Application\Commerce\MarketContextFactory;
use App\Application\Commerce\PricingService;
use App\Application\Security\UserIdentity;
use App\Domain\Orders\Entities\OrderLine;
use App\Domain\Orders\Order;
use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Domain\Orders\ValueObjects\ShippingAddress;
use App\Infrastructure\Persistence\Doctrine\Cart\DbalCartService;
use App\Infrastructure\Cache\StorefrontCache;
use App\Infrastructure\Localization\TranslationCatalog;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use DomainException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final readonly class PlaceOrderService
{
    public function __construct(
        private Connection $connection,
        private DbalCartService $carts,
        private PricingService $pricing,
        private MarketContextFactory $markets,
        private Security $security,
        private StorefrontCache $cache,
        private TranslationCatalog $translations,
        private array $locales,
    ) {
    }

    public function handle(
        Request $request,
        array $data,
        MarketContext $selectedMarket,
    ): PlacedOrder {
        $method = DeliveryMethod::from($data['delivery_method']);
        $market = $this->markets->make($data['country'], $selectedMarket->locale);
        $lines = $this->carts->orderLines($request, $market->locale);
        $quote = $this->pricing->quote($lines, $market, $method);
        $user = $this->security->getUser();
        $userId = $user instanceof UserIdentity ? $user->id() : null;

        $order = $this->connection->transactional(
            fn (): PlacedOrder => $this->place(
                $request,
                $data,
                $method,
                $lines,
                $quote,
                $userId,
            ),
        );
        $this->forgetProductCards($order->affectedProducts);

        return $order;
    }

    private function place(
        Request $request,
        array $data,
        DeliveryMethod $method,
        array $lines,
        array $quote,
        ?int $userId,
    ): PlacedOrder {
        $variantIds = array_map(fn (OrderLine $line): int => $line->variantId, $lines);
        $variants = $variantIds === [] ? [] : $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    v.id,
    v.stock,
    v.price_cents_net,
    p.price_cents_net AS product_price_cents_net,
    p.id AS product_id,
    p.slug,
    p.active,
    p.name
FROM product_variants v
INNER JOIN products p ON p.id = v.product_id
WHERE v.id IN (:ids)
FOR UPDATE
SQL,
            ['ids' => $variantIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $variants = $this->indexBy($variants, 'id');

        foreach ($lines as $line) {
            $variant = $variants[$line->variantId] ?? null;
            if ($variant === null || !$this->boolean($variant['active'])) {
                throw new DomainException("{$line->productName} is no longer available.");
            }
            $price = (int) ($variant['price_cents_net'] ?? $variant['product_price_cents_net']);
            if ($price !== $line->unitPriceCents) {
                throw new DomainException("The price of {$line->productName} has changed.");
            }
            if ((int) $variant['stock'] < $line->quantity) {
                throw new DomainException("Not enough stock for {$line->productName}.");
            }

            $this->connection->executeStatement(
                'UPDATE product_variants SET stock = stock - :quantity, updated_at = :updated_at WHERE id = :id',
                [
                    'quantity' => $line->quantity,
                    'updated_at' => $this->now(),
                    'id' => $line->variantId,
                ],
            );
        }

        $order = new Order(
            number: 'SP-'.strtoupper(bin2hex(random_bytes(4))),
            customerId: $userId,
            email: $data['email'],
            phone: $data['phone'],
            deliveryMethod: $method,
            shippingAddress: new ShippingAddress(
                $data['first_name'],
                $data['last_name'],
                $data['line1'],
                $data['line2'] ?? null,
                $data['city'],
                $data['postal_code'],
                $data['country'],
            ),
            lines: $quote['lines'],
            currency: $quote['currency'],
            market: $quote['market'],
            locale: $quote['locale'],
            exchangeRate: $quote['exchange_rate'],
            vatRateBasisPoints: $quote['vat_rate_basis_points'],
            netSubtotalOverride: $quote['net_subtotal_cents'],
            taxOverride: $quote['tax_cents'],
            deliveryNetOverride: $quote['delivery_net_cents'],
            deliveryTaxOverride: $quote['delivery_tax_cents'],
            deliveryOverride: $quote['delivery_cents'],
        );
        $orderId = $this->insertOrder($order);
        $this->insertAddress($orderId, $order->shippingAddress);
        $this->insertItems($orderId, $order->lines);
        $this->carts->clear($request);

        return new PlacedOrder(
            $orderId,
            $order->number,
            array_values(array_map(
                fn (array $variant): array => [
                    'id' => (int) $variant['product_id'],
                    'slug' => (string) $variant['slug'],
                ],
                $variants,
            )),
        );
    }

    private function insertOrder(Order $order): int
    {
        $now = $this->now();

        return (int) $this->connection->fetchOne(
            <<<'SQL'
INSERT INTO orders (
    user_id,
    number,
    email,
    phone,
    status,
    delivery_method,
    subtotal_cents,
    delivery_cents,
    total_cents,
    currency,
    market,
    locale,
    base_currency,
    exchange_rate,
    vat_rate_basis_points,
    net_subtotal_cents,
    tax_cents,
    delivery_net_cents,
    delivery_tax_cents,
    inventory_reserved_at,
    created_at,
    updated_at
)
VALUES (
    :user_id,
    :number,
    :email,
    :phone,
    :status,
    :delivery_method,
    :subtotal_cents,
    :delivery_cents,
    :total_cents,
    :currency,
    :market,
    :locale,
    :base_currency,
    :exchange_rate,
    :vat_rate_basis_points,
    :net_subtotal_cents,
    :tax_cents,
    :delivery_net_cents,
    :delivery_tax_cents,
    :inventory_reserved_at,
    :created_at,
    :updated_at
)
RETURNING id
SQL,
            [
                'user_id' => $order->customerId,
                'number' => $order->number,
                'email' => $order->email,
                'phone' => $order->phone,
                'status' => 'pending_payment',
                'delivery_method' => $order->deliveryMethod->value,
                'subtotal_cents' => $order->subtotalCents(),
                'delivery_cents' => $order->deliveryCents(),
                'total_cents' => $order->totalCents(),
                'currency' => $order->currency,
                'market' => $order->market,
                'locale' => $order->locale,
                'base_currency' => 'EUR',
                'exchange_rate' => $order->exchangeRate,
                'vat_rate_basis_points' => $order->vatRateBasisPoints,
                'net_subtotal_cents' => $order->netSubtotalCents(),
                'tax_cents' => $order->taxCents(),
                'delivery_net_cents' => $order->deliveryNetCents(),
                'delivery_tax_cents' => $order->deliveryTaxCents(),
                'inventory_reserved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    private function insertAddress(int $orderId, ShippingAddress $address): void
    {
        $this->connection->insert('order_addresses', [
            'order_id' => $orderId,
            'first_name' => $address->firstName,
            'last_name' => $address->lastName,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'postal_code' => $address->postalCode,
            'country' => $address->country,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    private function insertItems(int $orderId, array $lines): void
    {
        foreach ($lines as $line) {
            $this->connection->insert('order_items', [
                'order_id' => $orderId,
                'product_variant_id' => $line->variantId,
                'name' => $line->productName,
                'variant_name' => $line->variantName,
                'unit_price_cents' => $line->unitPriceCents,
                'net_unit_price_cents' => $line->netUnitPriceCents,
                'quantity' => $line->quantity,
                'line_total_cents' => $line->totalCents(),
                'tax_cents' => $line->taxCents,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
        }
    }

    private function indexBy(array $rows, string $key): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row[$key]] = $row;
        }

        return $indexed;
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

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
