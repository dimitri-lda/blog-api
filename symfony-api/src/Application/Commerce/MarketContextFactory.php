<?php

namespace App\Application\Commerce;

final readonly class MarketContextFactory
{
    public function __construct(
        private string $defaultCountry,
        private array $markets,
        private array $euVat,
        private array $locales,
    ) {
    }

    public function make(string $country, ?string $locale = null): MarketContext
    {
        $country = strtoupper($country);
        $default = strtoupper($this->defaultCountry);

        if (!isset($this->markets[$default]) && !isset($this->euVat[$default])) {
            $default = 'DE';
        }
        if (!isset($this->markets[$country]) && !isset($this->euVat[$country])) {
            $country = $default;
        }

        $market = $this->markets[$country] ?? [
            'code' => 'eu_global',
            'currency' => 'EUR',
            'locale' => 'en',
            'vat' => $this->euVat[$country],
        ];
        $locale = in_array($locale, $this->locales, true) ? $locale : $market['locale'];

        return new MarketContext(
            country: $country,
            market: $market['code'],
            currency: $market['currency'],
            locale: $locale,
            vatRate: (float) $market['vat'],
        );
    }
}
