<?php

namespace App\Application\Commerce;

final readonly class MarketContext
{
    public function __construct(
        public string $country,
        public string $market,
        public string $currency,
        public string $locale,
        public float $vatRate,
    ) {
    }

    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'market' => $this->market,
            'currency' => $this->currency,
            'locale' => $this->locale,
            'vat_rate' => $this->vatRate,
        ];
    }
}
