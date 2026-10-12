<?php

namespace App\Application\Commerce;

interface ExchangeRateStore
{
    public function find(string $currency): ?RateQuote;

    public function save(string $currency, RateQuote $quote): void;
}
