<?php

namespace App\Application\Commerce;

interface ExchangeRateProvider
{
    public function rate(string $currency): RateQuote;
}
