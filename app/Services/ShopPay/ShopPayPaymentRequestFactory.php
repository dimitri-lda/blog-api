<?php

namespace App\Services\ShopPay;

use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Models\Order;
use App\Models\OrderItem;

final class ShopPayPaymentRequestFactory
{
    public function frontend(Order $order): array
    {
        $order->loadMissing(['items.variant', 'address']);
        $money = fn (int $cents) => ['amount' => $cents / 100, 'currencyCode' => $order->currency];

        return [
            'supportedDeliveryMethodTypes' => ['SHIPPING'],
            'selectedDeliveryMethodType' => 'SHIPPING',
            'discountCodes' => [],
            'locale' => $order->locale ?? 'en',
            'presentmentCurrency' => $order->currency,
            'lineItems' => $order->items->map(fn (OrderItem $item) => $this->lineItem($item, $money))->all(),
            'deliveryMethods' => $this->deliveryMethods($order, $money),
            'shippingLines' => [[
                'label' => $this->deliveryLabel($order),
                'code' => $order->delivery_method,
                'amount' => $money($this->deliveryNet($order)),
            ]],
            'subtotal' => $money($this->netSubtotal($order)),
            'totalTax' => $money($this->totalTax($order)),
            'totalShippingPrice' => [
                'originalTotal' => $money($this->deliveryNet($order)),
                'finalTotal' => $money($this->deliveryNet($order)),
            ],
            'total' => $money($order->total_cents),
            'shippingAddress' => $this->shippingAddress($order),
        ];
    }

    public function storefront(Order $order, ?string $paymentMethod = null): array
    {
        $order->loadMissing(['items.variant', 'address']);
        $money = fn (int $cents) => [
            'amount' => $this->decimal($cents),
            'currencyCode' => $order->currency,
        ];
        $request = [
            'discountCodes' => [],
            'locale' => $order->locale ?? 'en',
            'presentmentCurrency' => $order->currency,
            'selectedDeliveryMethodType' => 'SHIPPING',
            'lineItems' => $order->items->map(fn (OrderItem $item) => $this->lineItem($item, $money))->all(),
            'deliveryMethods' => $this->deliveryMethods($order, $money),
            'shippingLines' => [[
                'label' => $this->deliveryLabel($order),
                'code' => $order->delivery_method,
                'amount' => $money($this->deliveryNet($order)),
            ]],
            'subtotal' => $money($this->netSubtotal($order)),
            'totalTax' => $money($this->totalTax($order)),
            'totalShippingPrice' => [
                'originalTotal' => $money($this->deliveryNet($order)),
                'finalTotal' => $money($this->deliveryNet($order)),
            ],
            'total' => $money($order->total_cents),
            'shippingAddress' => $this->shippingAddress($order),
        ];
        if ($paymentMethod !== null) {
            $request['paymentMethod'] = $paymentMethod;
        }

        return $request;
    }

    public function deliveryQuote(Order $order, DeliveryMethod $method): array
    {
        $rate = $order->exchange_rate ? (float) $order->exchange_rate : 1.0;
        $vatMultiplier = 1 + (($order->vat_rate_basis_points ?? 0) / 10000);
        $threshold = (int) round(
            config('commerce.delivery.free_shipping_gross_eur_cents') * $rate,
            0,
            PHP_ROUND_HALF_UP,
        );
        $net = $method === DeliveryMethod::Standard && $order->subtotal_cents >= $threshold
            ? 0
            : (int) round(
                config('commerce.delivery.'.($method === DeliveryMethod::Express
                    ? 'express_net_cents' : 'standard_net_cents')) * $rate,
                0,
                PHP_ROUND_HALF_UP,
            );
        $gross = (int) round($net * $vatMultiplier, 0, PHP_ROUND_HALF_UP);

        return ['net' => $net, 'tax' => $gross - $net, 'gross' => $gross];
    }

    private function lineItem(OrderItem $item, callable $money): array
    {
        $netUnit = $item->net_unit_price_cents
            ?? intdiv($item->line_total_cents - (int) $item->tax_cents, $item->quantity);

        return [
            'label' => $this->label($item),
            'sku' => (string) ($item->variant?->sku ?? $item->product_variant_id),
            'quantity' => $item->quantity,
            'requiresShipping' => true,
            'originalItemPrice' => $money($netUnit),
            'finalItemPrice' => $money($netUnit),
            'originalLinePrice' => $money($netUnit * $item->quantity),
            'finalLinePrice' => $money($netUnit * $item->quantity),
        ];
    }

    private function deliveryMethods(Order $order, callable $money): array
    {
        return array_map(fn (DeliveryMethod $method) => [
            'code' => $method->value,
            'label' => $method === DeliveryMethod::Express ? 'Express' : 'Standard',
            'amount' => $money($this->deliveryQuote($order, $method)['net']),
        ], DeliveryMethod::cases());
    }

    private function deliveryLabel(Order $order): string
    {
        return $order->delivery_method === DeliveryMethod::Express->value ? 'Express' : 'Standard';
    }

    private function deliveryNet(Order $order): int
    {
        return (int) ($order->delivery_net_cents ?? $order->delivery_cents);
    }

    private function netSubtotal(Order $order): int
    {
        return (int) ($order->net_subtotal_cents ?? $order->items->sum(
            fn (OrderItem $item) => ($item->net_unit_price_cents
                ?? intdiv($item->line_total_cents - (int) $item->tax_cents, $item->quantity)) * $item->quantity,
        ));
    }

    private function totalTax(Order $order): int
    {
        return (int) ($order->tax_cents ?? 0) + (int) ($order->delivery_tax_cents ?? 0);
    }

    private function shippingAddress(Order $order): array
    {
        return [
            'firstName' => $order->address?->first_name,
            'lastName' => $order->address?->last_name,
            'address1' => $order->address?->line1,
            'address2' => $order->address?->line2,
            'city' => $order->address?->city,
            'postalCode' => $order->address?->postal_code,
            'countryCode' => $order->address?->country,
            'email' => $order->email,
            'phone' => $order->phone,
        ];
    }

    private function decimal(int $cents): string
    {
        return (string) intdiv($cents, 100)
            .'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function label(OrderItem $item): string
    {
        return $item->variant_name !== '' ? "{$item->name} — {$item->variant_name}" : $item->name;
    }
}
