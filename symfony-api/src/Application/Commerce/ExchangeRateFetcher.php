<?php

namespace App\Application\Commerce;

interface ExchangeRateFetcher
{
    public function fetch(string $currency): RateQuote;
}
