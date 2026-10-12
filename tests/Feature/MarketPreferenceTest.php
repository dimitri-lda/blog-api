<?php

namespace Tests\Feature;

use App\Services\MarketContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manual_market_and_locale_selection_is_saved_in_the_session(): void
    {
        $this->post(route('market.preferences.update'), ['country' => 'BY', 'locale' => 'ru'])
            ->assertRedirect()
            ->assertSessionHas('market', ['country' => 'BY', 'locale' => 'ru']);
    }

    public function test_market_context_uses_polish_currency_and_tax_for_poland(): void
    {
        $context = app(MarketContext::class)->make('PL');

        $this->assertSame('poland', $context['market']);
        $this->assertSame('PLN', $context['currency']);
        $this->assertSame('pl', $context['locale']);
        $this->assertSame(23.0, $context['vat_rate']);
    }
}
