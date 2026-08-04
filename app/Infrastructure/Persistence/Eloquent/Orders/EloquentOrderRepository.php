<?php

namespace App\Infrastructure\Persistence\Eloquent\Orders;

use App\Domain\Orders\Order as DomainOrder;
use App\Domain\Orders\Repository\OrderRepository;
use App\Models\Order;

final class EloquentOrderRepository implements OrderRepository
{
    public function save(DomainOrder $order): void
    {
        $record = Order::create([
            'user_id' => $order->customerId, 'number' => $order->number,
            'email' => $order->email, 'phone' => $order->phone, 'status' => 'pending_payment',
            'delivery_method' => $order->deliveryMethod->value,
            'subtotal_cents' => $order->subtotalCents(), 'delivery_cents' => $order->deliveryCents(), 'total_cents' => $order->totalCents(), 'currency' => $order->currency,
            'market' => $order->market, 'locale' => $order->locale, 'exchange_rate' => $order->exchangeRate,
            'vat_rate_basis_points' => $order->vatRateBasisPoints, 'net_subtotal_cents' => $order->netSubtotalCents(), 'tax_cents' => $order->taxCents(),
            'delivery_net_cents' => $order->deliveryNetCents(), 'delivery_tax_cents' => $order->deliveryTaxCents(),
        ]);
        $address = $order->shippingAddress;
        $record->address()->create(['first_name' => $address->firstName, 'last_name' => $address->lastName, 'line1' => $address->line1, 'line2' => $address->line2, 'city' => $address->city, 'postal_code' => $address->postalCode, 'country' => $address->country]);
        foreach ($order->lines as $line) {
            $record->items()->create(['product_variant_id' => $line->variantId, 'name' => $line->productName, 'variant_name' => $line->variantName, 'unit_price_cents' => $line->unitPriceCents, 'net_unit_price_cents' => $line->netUnitPriceCents, 'quantity' => $line->quantity, 'line_total_cents' => $line->totalCents(), 'tax_cents' => $line->taxCents]);
        }
    }
}
