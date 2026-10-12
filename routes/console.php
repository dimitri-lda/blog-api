<?php

use App\Services\ShopPay\ShopPayConfiguration;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('commerce:refresh-exchange-rates')->hourly();

Schedule::command('storefront:cache-warm')->everyFiveMinutes();

Schedule::command('shop-pay:reconcile')
    ->everyFiveMinutes()
    ->when(fn () => app(ShopPayConfiguration::class)->enabled());
