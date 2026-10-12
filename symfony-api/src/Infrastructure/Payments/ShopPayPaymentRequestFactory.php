<?php

namespace App\Infrastructure\Payments;

use App\Domain\Orders\ValueObjects\DeliveryMethod;

final readonly class ShopPayPaymentRequestFactory
{
    public function __construct(
        private int $standardDeliveryNetCents,
        private int $expressDeliveryNetCents,
        private int $freeShippingGrossEurCents,
    ) {
    }

    public function frontend(array $order): array
    {
        $money = fn (int $cents): array => [
            'amount' => $cents / 100,
            'currencyCode' => $order['currency'],
        ];

        return [
            'supportedDeliveryMethodTypes' => ['SHIPPING'],
            'selectedDeliveryMethodType' => 'SHIPPING',
            ...$this->request($order, $money),
        ];
    }

    public function storefront(array $order, ?string $paymentMethod = null): array
    {
        $money = fn (int $cents): array => [
            'amount' => $this->decimal($cents),
            'currencyCode' => $order['currency'],
        ];
        $request = $this->request($order, $money);
        if ($paymentMethod !== null) {
            $request['paymentMethod'] = $paymentMethod;
        }

        return $request;
    }

    public function deliveryQuote(array $order, DeliveryMethod $method): array
    {
        $rate = $order['exchange_rate'] ? (float) $order['exchange_rate'] : 1.0;
        $vatMultiplier = 1 + (((int) ($order['vat_rate_basis_points'] ?? 0)) / 10000);
        $threshold = (int) round(
            $this->freeShippingGrossEurCents * $rate,
            0,
            PHP_ROUND_HALF_UP,
        );
        $deliveryNet = $method === DeliveryMethod::Standard
            ? $this->standardDeliveryNetCents
            : $this->expressDeliveryNetCents;
        $net = $method === DeliveryMethod::Standard && $order['subtotal_cents'] >= $threshold
            ? 0
            : (int) round($deliveryNet * $rate, 0, PHP_ROUND_HALF_UP);
        $gross = (int) round($net * $vatMultiplier, 0, PHP_ROUND_HALF_UP);

        return ['net' => $net, 'tax' => $gross - $net, 'gross' => $gross];
    }

    private function request(array $order, callable $money): array
    {
        return [
            'discountCodes' => [],
            'locale' => $order['locale'] ?? 'en',
            'presentmentCurrency' => $order['currency'],
            'selectedDeliveryMethodType' => 'SHIPPING',
            'lineItems' => array_map(
                fn (array $item): array => $this->lineItem($item, $money),
                $order['items'],
            ),
            'deliveryMethods' => array_map(
                fn (DeliveryMethod $method): array => [
                    'code' => $method->value,
                    'label' => $method === DeliveryMethod::Express ? 'Express' : 'Standard',
                    'amount' => $money($this->deliveryQuote($order, $method)['net']),
                ],
                DeliveryMethod::cases(),
            ),
            'shippingLines' => [[
                'label' => $order['delivery_method'] === 'express' ? 'Express' : 'Standard',
                'code' => $order['delivery_method'],
                'amount' => $money((int) ($order['delivery_net_cents'] ?? $order['delivery_cents'])),
            ]],
            'subtotal' => $money($this->netSubtotal($order)),
            'totalTax' => $money(
                (int) ($order['tax_cents'] ?? 0)
                + (int) ($order['delivery_tax_cents'] ?? 0),
            ),
            'totalShippingPrice' => [
                'originalTotal' => $money(
                    (int) ($order['delivery_net_cents'] ?? $order['delivery_cents']),
                ),
                'finalTotal' => $money(
                    (int) ($order['delivery_net_cents'] ?? $order['delivery_cents']),
                ),
            ],
            'total' => $money((int) $order['total_cents']),
            'shippingAddress' => [
                'firstName' => $order['address']['first_name'] ?? null,
                'lastName' => $order['address']['last_name'] ?? null,
                'address1' => $order['address']['line1'] ?? null,
                'address2' => $order['address']['line2'] ?? null,
                'city' => $order['address']['city'] ?? null,
                'postalCode' => $order['address']['postal_code'] ?? null,
                'countryCode' => $order['address']['country'] ?? null,
                'email' => $order['email'],
                'phone' => $order['phone'],
            ],
        ];
    }

    private function lineItem(array $item, callable $money): array
    {
        $netUnit = $item['net_unit_price_cents']
            ?? intdiv(
                $item['line_total_cents'] - (int) $item['tax_cents'],
                $item['quantity'],
            );

        return [
            'label' => $item['variant_name'] !== ''
                ? "{$item['name']} — {$item['variant_name']}"
                : $item['name'],
            'sku' => (string) ($item['sku'] ?? $item['product_variant_id']),
            'quantity' => $item['quantity'],
            'requiresShipping' => true,
            'originalItemPrice' => $money($netUnit),
            'finalItemPrice' => $money($netUnit),
            'originalLinePrice' => $money($netUnit * $item['quantity']),
            'finalLinePrice' => $money($netUnit * $item['quantity']),
        ];
    }

    private function netSubtotal(array $order): int
    {
        if ($order['net_subtotal_cents'] !== null) {
            return $order['net_subtotal_cents'];
        }

        return array_sum(array_map(
            fn (array $item): int => (
                $item['net_unit_price_cents']
                ?? intdiv(
                    $item['line_total_cents'] - (int) $item['tax_cents'],
                    $item['quantity'],
                )
            ) * $item['quantity'],
            $order['items'],
        ));
    }

    private function decimal(int $cents): string
    {
        return intdiv($cents, 100)
            .'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
