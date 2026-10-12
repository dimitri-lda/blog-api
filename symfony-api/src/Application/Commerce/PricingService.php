<?php

namespace App\Application\Commerce;

use App\Domain\Orders\Entities\OrderLine;
use App\Domain\Orders\ValueObjects\DeliveryMethod;

final readonly class PricingService
{
    public function __construct(
        private ExchangeRateProvider $rates,
        private int $standardDeliveryNetCents,
        private int $expressDeliveryNetCents,
        private int $freeShippingGrossEurCents,
    ) {
    }

    public function quote(array $lines, MarketContext $context, DeliveryMethod $method): array
    {
        $rateInfo = $this->rates->rate($context->currency);
        $vatMultiplier = 1 + ($context->vatRate / 100);
        $priced = [];

        foreach ($lines as $line) {
            $netUnit = (int) round($line->unitPriceCents * $rateInfo->rate, 0, PHP_ROUND_HALF_UP);
            $grossUnit = (int) round($netUnit * $vatMultiplier, 0, PHP_ROUND_HALF_UP);
            $priced[] = new OrderLine(
                variantId: $line->variantId,
                productName: $line->productName,
                variantName: $line->variantName,
                unitPriceCents: $grossUnit,
                quantity: $line->quantity,
                netUnitPriceCents: $netUnit,
                taxCents: ($grossUnit - $netUnit) * $line->quantity,
            );
        }

        $netSubtotal = array_sum(array_map(
            fn (OrderLine $line): int => $line->netUnitPriceCents * $line->quantity,
            $priced,
        ));
        $subtotal = array_sum(array_map(fn (OrderLine $line): int => $line->totalCents(), $priced));
        $threshold = (int) round(
            $this->freeShippingGrossEurCents * $rateInfo->rate,
            0,
            PHP_ROUND_HALF_UP,
        );
        $deliveryNet = $method === DeliveryMethod::Standard && $subtotal >= $threshold
            ? 0
            : (int) round(
                $this->deliveryNetCents($method) * $rateInfo->rate,
                0,
                PHP_ROUND_HALF_UP,
            );
        $deliveryGross = (int) round($deliveryNet * $vatMultiplier, 0, PHP_ROUND_HALF_UP);

        return [
            'lines' => $priced,
            'currency' => $context->currency,
            'market' => $context->market,
            'locale' => $context->locale,
            'country' => $context->country,
            'exchange_rate' => $rateInfo->rate,
            'rate_source' => $rateInfo->source,
            'vat_rate_basis_points' => (int) round($context->vatRate * 100),
            'net_subtotal_cents' => $netSubtotal,
            'tax_cents' => $subtotal - $netSubtotal,
            'subtotal_cents' => $subtotal,
            'delivery_net_cents' => $deliveryNet,
            'delivery_tax_cents' => $deliveryGross - $deliveryNet,
            'delivery_cents' => $deliveryGross,
            'total_cents' => $subtotal + $deliveryGross,
        ];
    }

    public function displayPrice(int $netEurCents, MarketContext $context): array
    {
        $rateInfo = $this->rates->rate($context->currency);
        $net = (int) round($netEurCents * $rateInfo->rate, 0, PHP_ROUND_HALF_UP);
        $gross = (int) round($net * (1 + $context->vatRate / 100), 0, PHP_ROUND_HALF_UP);

        return [
            'net_cents' => $net,
            'gross_cents' => $gross,
            'tax_cents' => $gross - $net,
            'currency' => $context->currency,
            'vat_rate' => $context->vatRate,
        ];
    }

    private function deliveryNetCents(DeliveryMethod $method): int
    {
        return match ($method) {
            DeliveryMethod::Standard => $this->standardDeliveryNetCents,
            DeliveryMethod::Express => $this->expressDeliveryNetCents,
        };
    }
}
