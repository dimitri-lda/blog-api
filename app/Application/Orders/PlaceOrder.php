<?php

namespace App\Application\Orders;

use App\Domain\Cart\Repository\CartRepository;
use App\Domain\Orders\Entities\OrderLine;
use App\Domain\Orders\Order;
use App\Domain\Orders\Repository\OrderRepository;
use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Domain\Orders\ValueObjects\ShippingAddress;
use App\Models\ProductVariant;
use App\Services\MarketContext;
use App\Services\PricingService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class PlaceOrder
{
    public function __construct(
        private CartRepository $carts,
        private OrderRepository $orders,
        private PricingService $pricing,
        private MarketContext $markets,
    ) {}

    /**
     * @param array{email:string,phone:string,first_name:string,last_name:string,
     *     line1:string,'line2'?: string|null,city:string,postal_code:string,country:string,
     *     delivery_method:string} $data
     */
    public function handle(array $data, ?int $userId, ?string $cartToken, array $market): Order
    {
        $method = DeliveryMethod::from($data['delivery_method']);
        $context = $this->markets->make($data['country'], $market['locale'] ?? null);
        $lines = $this->carts->orderLinesFor($userId, $cartToken);
        $quote = $this->pricing->quote($lines, $context, $method);

        return DB::transaction(function () use ($data, $userId, $cartToken, $lines, $quote, $method): Order {
            $variants = ProductVariant::with('product')
                ->whereIn('id', array_map(fn (OrderLine $line) => $line->variantId, $lines))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            foreach ($lines as $line) {
                $variant = $variants->get($line->variantId);
                if (! $variant || ! $variant->product?->active) {
                    throw new DomainException("{$line->productName} is no longer available.");
                }
                $price = (int) ($variant->price_cents_net ?? $variant->product->price_cents_net);
                if ($price !== $line->unitPriceCents) {
                    throw new DomainException("The price of {$line->productName} has changed.");
                }
                if ($variant->stock < $line->quantity) {
                    throw new DomainException("Not enough stock for {$line->productName}.");
                }
                $variant->decrement('stock', $line->quantity);
            }

            $order = new Order(
                number: 'SP-'.strtoupper(Str::random(8)),
                customerId: $userId,
                email: $data['email'],
                phone: $data['phone'],
                deliveryMethod: $method,
                shippingAddress: new ShippingAddress(
                    $data['first_name'], $data['last_name'], $data['line1'],
                    $data['line2'] ?? null, $data['city'], $data['postal_code'], $data['country'],
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

            $this->orders->save($order);
            $this->carts->clear($userId, $cartToken);

            return $order;
        });
    }
}
