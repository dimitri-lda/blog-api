<?php

namespace Tests\Feature;

use App\Domain\Orders\Entities\OrderLine;
use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Models\ExchangeRate;
use App\Services\MarketContext;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_belarus_quote_converts_net_eur_and_adds_twenty_percent_vat(): void
    {
        ExchangeRate::create(['currency' => 'BYN', 'rate' => 3, 'source' => 'test', 'quoted_at' => now()]);
        $quote = app(PricingService::class)->quote([new OrderLine(1, 'Shoes', '42', 10_000, 1)], app(MarketContext::class)->make('BY'), DeliveryMethod::Standard);

        $this->assertSame('BYN', $quote['currency']);
        $this->assertSame(30_000, $quote['net_subtotal_cents']);
        $this->assertSame(6_000, $quote['tax_cents']);
        $this->assertSame(36_000, $quote['subtotal_cents']);
        $this->assertSame(0, $quote['delivery_cents']);
    }

    public function test_eu_quote_keeps_eur_and_uses_destination_vat_rate(): void
    {
        $quote = app(PricingService::class)->quote([new OrderLine(1, 'Shoes', '42', 1_000, 1)], app(MarketContext::class)->make('DE'), DeliveryMethod::Standard);

        $this->assertSame('EUR', $quote['currency']);
        $this->assertSame(1_190, $quote['subtotal_cents']);
        $this->assertSame(190, $quote['tax_cents']);
        $this->assertSame(702, $quote['delivery_cents']);
    }
}
