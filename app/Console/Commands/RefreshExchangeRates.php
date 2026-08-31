<?php

namespace App\Console\Commands;

use App\Services\ExchangeRateService;
use Illuminate\Console\Command;

final class RefreshExchangeRates extends Command
{
    protected $signature = 'commerce:refresh-exchange-rates';

    protected $description = 'Refresh EUR conversion rates used for checkout.';

    public function handle(ExchangeRateService $rates): int
    {
        foreach (['PLN', 'BYN'] as $currency) {
            try {
                $rate = $rates->rate($currency);
                $this->line("{$currency}: {$rate['rate']} ({$rate['source']})");
            } catch (\Throwable $e) {
                $this->error("{$currency}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
