<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use App\Services\TranslationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\TestCase;

class TranslationCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function catalog(array $translations): TranslationCatalog
    {
        $catalog = new TranslationCatalog;
        (new ReflectionProperty($catalog, 'translations'))->setValue($catalog, $translations);

        return $catalog;
    }

    public function test_partial_leaf_falls_back_to_en_then_the_first_scalar(): void
    {
        $catalog = $this->catalog([
            'section' => [
                'partial' => ['en' => 'English', 'pl' => 'Polski'],
                'no_en' => ['ru' => 'Русский', 'pl' => 'Polski'],
            ],
        ]);

        $this->assertSame('English', $catalog->forLocale('ru')['section.partial']);
        $this->assertSame('Polski', $catalog->forLocale('pl')['section.partial']);
        $this->assertSame('Русский', $catalog->forLocale('en')['section.no_en']);
        $this->assertSame('Polski', $catalog->forLocale('pl')['section.no_en']);
    }

    public function test_only_unambiguous_basenames_get_bare_aliases(): void
    {
        $catalog = $this->catalog([
            'a' => [
                'name' => ['en' => 'A name'],
                'leaf' => ['en' => 'Unique'],
            ],
            'b' => [
                'name' => ['en' => 'B name'],
                'other' => 'scalar',
            ],
        ]);

        $en = $catalog->forLocale('en');
        $this->assertSame('A name', $en['a.name']);
        $this->assertSame('B name', $en['b.name']);
        $this->assertArrayNotHasKey('name', $en);
        $this->assertSame('Unique', $en['leaf']);
        $this->assertSame('scalar', $en['other']);
    }

    public function test_catalog_is_a_singleton_and_memoizes_per_locale(): void
    {
        $this->assertSame(app(TranslationCatalog::class), app(TranslationCatalog::class));

        $catalog = app(TranslationCatalog::class);
        $en = $catalog->forLocale('en');
        $this->assertSame($en, $catalog->forLocale('en'));
        $this->assertSame('Checkout', $en['checkout.title']);
        $this->assertSame('Shop all', $en['shop_all']);
        $this->assertArrayNotHasKey('title', $en);
        $this->assertArrayNotHasKey('name', $en);
    }

    public function test_exchange_rate_service_resolves_each_currency_once(): void
    {
        ExchangeRate::create(['currency' => 'BYN', 'rate' => 3, 'source' => 'test', 'quoted_at' => now()]);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $service = app(ExchangeRateService::class);
        $this->assertSame(3.0, $service->rate('BYN')['rate']);
        $this->assertSame(1, $queries);
        $service->rate('BYN');
        $service->rate('EUR');
        $this->assertSame(1, $queries);
    }

    public function test_exchange_rate_http_fetch_is_memoized_for_the_request(): void
    {
        config()->set('commerce.exchange_rates.myfin_eur_byn_url', 'https://rates.test/eur-byn');
        ExchangeRate::create([
            'currency' => 'BYN', 'rate' => 3, 'source' => 'test', 'quoted_at' => now()->subDay(),
        ]);
        Http::fake(['rates.test/*' => Http::response('1 EUR = 3.55')]);

        $service = app(ExchangeRateService::class);
        $this->assertSame(3.55, $service->rate('BYN')['rate']);
        $this->assertSame(3.55, $service->rate('BYN')['rate']);
        Http::assertSentCount(1);
    }
}
