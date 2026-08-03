<?php

namespace App\Infrastructure\Persistence\Eloquent\Orders;

use App\Domain\Orders\Contracts\OrderRepository;
use App\Domain\Orders\Order as DomainOrder;
use App\Models\Order;

final class EloquentOrderRepository implements OrderRepository
{
    public function save(DomainOrder $order): void
    {
        $record = Order::create([
            'user_id' => $order->customerId, 'number' => $order->number,
            'email' => $order->email, 'phone' => $order->phone, 'status' => 'pending_payment',
            'delivery_method' => $order->deliveryMethod->value,
            'subtotal_cents' => $order->subtotalCents(), 'delivery_cents' => $order->deliveryCents(),
            'total_cents' => $order->totalCents(), 'currency' => $order->currency,
        ]);
        $address = $order->shippingAddress;
        $record->address()->create(['first_name' => $address->firstName, 'last_name' => $address->lastName, 'line1' => $address->line1, 'line2' => $address->line2, 'city' => $address->city, 'postal_code' => $address->postalCode, 'country' => $address->country]);
        foreach ($order->lines as $line) {
            $record->items()->create(['product_variant_id' => $line->variantId, 'name' => $line->productName, 'variant_name' => $line->variantName, 'unit_price_cents' => $line->unitPriceCents, 'quantity' => $line->quantity, 'line_total_cents' => $line->totalCents()]);
        }
    }
}
