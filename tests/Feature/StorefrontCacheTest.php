<?php

namespace Tests\Feature;

use App\Application\Orders\Admin\ChangeOrderStatus;
use App\Domain\Orders\ValueObjects\OrderStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Manufacturer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Cache\StorefrontCache;
use App\Services\ExchangeRateService;
use Closure;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StorefrontCacheTest extends TestCase
{
    use RefreshDatabase;

    private int $queries = 0;

    private bool $counting = false;

    private ?string $queryPattern = null;

    private int $productQueries = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        DB::listen(function ($query): void {
            if (! $this->counting) {
                return;
            }
            if (preg_match('/\bproducts\b/', $query->sql)) {
                $this->productQueries++;
            }
            if ($this->queryPattern === null || preg_match($this->queryPattern, $query->sql)) {
                $this->queries++;
            }
        });
    }

    private function product(Category $category, array $attributes = [], ?int $stock = 3): Product
    {
        $product = Product::create([
            'name' => 'Shoes', 'slug' => 'shoes-'.uniqid(), 'description' => 'Product description',
            'category_id' => $category->id, 'price_cents_net' => 10000,
            'image_url' => '/shoe.jpg', 'active' => true, ...$attributes,
        ]);
        if ($stock !== null) {
            $product->variants()->create(['name' => '42', 'sku' => uniqid(), 'stock' => $stock]);
        }

        return $product;
    }

    private function checkoutData(): array
    {
        return [
            'email' => 'buyer@example.test', 'phone' => '123456789',
            'first_name' => 'Test', 'last_name' => 'Buyer', 'line1' => 'Test 1',
            'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE',
            'delivery_method' => 'standard',
        ];
    }

    private function measureQueries(Closure $run, ?string $pattern = null): int
    {
        $this->queries = 0;
        $this->productQueries = 0;
        $this->queryPattern = $pattern;
        $this->counting = true;
        try {
            $run();
        } finally {
            $this->counting = false;
            $this->queryPattern = null;
        }

        return $this->queries;
    }

    public function test_miss_and_hit_render_identical_props_and_warm_hits_use_fewer_queries(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $product = $this->product($category, ['featured' => true]);

        $pages = [
            '/' => ['categories', 'featured'],
            '/shop' => ['products', 'categories', 'filters'],
            '/shop/'.$product->slug => ['product', 'breadcrumbs', 'related'],
        ];
        foreach ($pages as $uri => $keys) {
            $cold = null;
            $coldQueries = $this->measureQueries(function () use ($uri, &$cold): void {
                $cold = $this->get($uri)->assertOk();
            });
            $warm = null;
            $warmQueries = $this->measureQueries(function () use ($uri, &$warm): void {
                $warm = $this->get($uri)->assertOk();
            });

            foreach ($keys as $key) {
                $this->assertEquals(
                    $cold->viewData('page')['props'][$key],
                    $warm->viewData('page')['props'][$key],
                    "Prop '{$key}' differs between a cold and a warm render of {$uri}",
                );
            }
            $this->assertLessThan(
                $coldQueries,
                $warmQueries,
                "Warm {$uri} performed {$warmQueries} queries against {$coldQueries} cold",
            );
            $this->assertLessThanOrEqual(1, $warmQueries, "Warm {$uri} should not query the catalog");
        }
    }

    public function test_catalog_writes_are_visible_without_waiting_for_ttl(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $brand = Brand::create(['name' => 'Brand', 'slug' => 'brand']);
        $manufacturer = Manufacturer::create(['name' => 'Factory', 'slug' => 'factory']);
        $product = $this->product($category, [
            'brand_id' => $brand->id, 'manufacturer_id' => $manufacturer->id,
        ]);

        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.price_cents_net', 10000));

        $product->update(['price_cents_net' => 22222]);
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.price_cents_net', 22222));

        $product->update(['name' => 'Renamed shoes']);
        $this->get('/shop/'.$product->slug)->assertInertia(fn (Assert $page) => $page
            ->where('product.name', 'Renamed shoes'));

        $product->images()->create(['url' => '/new.jpg', 'position' => 1]);
        $product->specifications()->create(['name' => 'Weight', 'value' => '300', 'unit' => 'g']);
        $brand->update(['name' => 'New brand']);
        $manufacturer->update(['name' => 'New factory']);
        $this->get('/shop/'.$product->slug)->assertInertia(fn (Assert $page) => $page
            ->where('product.images.0.url', '/new.jpg')
            ->where('product.specifications.0.name', 'Weight')
            ->where('product.brand_record.name', 'New brand')
            ->where('product.manufacturer.name', 'New factory'));

        $product->update(['active' => false]);
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
        $this->get('/shop/'.$product->slug)->assertNotFound();
    }

    public function test_creates_deletes_and_slug_changes_update_lists_cards_and_counts(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $this->product($category);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('categories.0.products_count', 1));

        $second = $this->product($category);
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('products.total', 2));
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('categories.0.products_count', 2));

        $oldSlug = $second->slug;
        $second->update(['slug' => 'renamed-shoes']);
        $this->get('/shop/'.$oldSlug)->assertNotFound();
        $this->get('/shop/renamed-shoes')->assertInertia(fn (Assert $page) => $page
            ->where('product.slug', 'renamed-shoes'));

        $second->delete();
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('products.total', 1));
        $this->get('/shop/renamed-shoes')->assertNotFound();

        $extra = Category::create(['name' => 'Fitness', 'slug' => 'fitness']);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('categories', 2));
        $extra->delete();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('categories', 1));
    }

    public function test_order_and_cancellation_update_cached_stock_immediately(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $product = $this->product($category, [], 1);
        $variant = $product->variants->first();

        $this->get('/shop/'.$product->slug)->assertInertia(fn (Assert $page) => $page
            ->where('product.available', true)->where('product.variants.0.stock', 1));

        $this->post('/cart/items/'.$variant->id, ['quantity' => 1])->assertRedirect();
        $this->post('/checkout', $this->checkoutData())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, $variant->fresh()->stock);

        $this->get('/shop/'.$product->slug)->assertInertia(fn (Assert $page) => $page
            ->where('product.available', false)->where('product.variants.0.stock', 0));

        app(ChangeOrderStatus::class)->handle(Order::sole()->id, OrderStatus::Cancelled);
        $this->assertSame(1, $variant->fresh()->stock);
        $this->get('/shop/'.$product->slug)->assertInertia(fn (Assert $page) => $page
            ->where('product.available', true)->where('product.variants.0.stock', 1));
    }

    public function test_ordering_a_product_does_not_invalidate_the_whole_catalog(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $products = array_map(fn () => $this->product($category), range(1, 4));
        $variant = $products[0]->variants->first();
        $cache = app(StorefrontCache::class);

        $this->get('/shop')->assertOk(); // warm
        $cold = $this->measureQueries(function (): void {
            app(StorefrontCache::class)->flush();
            $this->get('/shop')->assertOk();
        });
        $this->get('/shop')->assertOk(); // warm again after the forced cold pass
        $warm = $this->measureQueries(fn () => $this->get('/shop')->assertOk());
        $this->assertSame(0, $warm, 'Warm catalog page should not touch the database');

        $generations = [
            'tree' => $cache->generation('tree'),
            'list' => $cache->generation('list'),
            'card' => $cache->generation('card'),
        ];

        $this->post('/cart/items/'.$variant->id, ['quantity' => 1])->assertRedirect();
        $this->post('/checkout', $this->checkoutData())->assertRedirect()->assertSessionHasNoErrors();

        // A stock change must forget only the affected card — no generation rotation.
        foreach ($generations as $group => $token) {
            $this->assertSame($token, $cache->generation($group), "Generation '{$group}' was rotated");
        }

        $afterOrder = $this->measureQueries(fn () => $this->get('/shop')
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 4)));
        $this->assertLessThan($cold, $afterOrder);
        $this->assertLessThanOrEqual(6, $afterOrder, 'More than the affected card was re-fetched');
        $this->assertSame(
            1,
            $this->productQueries,
            'Only the single card of the ordered product may be re-fetched; list ids must stay cached',
        );
    }

    public function test_filter_sort_and_pagination_cache_keys_do_not_mix(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        for ($index = 0; $index < 13; $index++) {
            $this->product($category, ['price_cents_net' => 100 + $index]);
        }

        $filtered = '/shop?category=sport&q=Shoes&sort=price_asc&page=2';
        $assertFiltered = fn (Assert $page) => $page
            ->where('products.total', 13)->has('products.data', 1)
            ->where('products.data.0.price_cents_net', 112)
            ->where('products.prev_page_url', fn ($url) => str_contains($url, 'category=sport')
                && str_contains($url, 'q=Shoes') && str_contains($url, 'sort=price_asc'));

        $this->get($filtered)->assertInertia($assertFiltered);
        $this->get('/shop?sort=price_desc')->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 13)->has('products.data', 12)
            ->where('products.data.0.price_cents_net', 112));
        $this->get('/shop?q=Unrelated')->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 0));
        // The first query string must still resolve to its own cached page.
        $this->get($filtered)->assertInertia($assertFiltered);
    }

    public function test_market_locales_currencies_and_vat_do_not_bleed_between_requests(): void
    {
        ExchangeRate::create(['currency' => 'BYN', 'rate' => 3, 'source' => 'test', 'quoted_at' => now()]);
        ExchangeRate::create(['currency' => 'PLN', 'rate' => 4, 'source' => 'test', 'quoted_at' => now()]);
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $this->product($category, [
            'name_translations' => ['en' => 'Shoe EN', 'ru' => 'Shoe RU', 'pl' => 'Shoe PL'],
        ]);

        $this->withSession(['market' => ['country' => 'BY', 'locale' => 'ru']])
            ->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('market.currency', 'BYN')
            ->where('products.data.0.localized_name', 'Shoe RU')
            ->where('products.data.0.display_price.currency', 'BYN')
            ->where('products.data.0.display_price.vat_rate', 20)
            ->where('products.data.0.display_price.gross_cents', 36000));
        $this->withSession(['market' => ['country' => 'PL', 'locale' => 'pl']])
            ->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.localized_name', 'Shoe PL')
            ->where('products.data.0.display_price.currency', 'PLN')
            ->where('products.data.0.display_price.vat_rate', 23)
            ->where('products.data.0.display_price.gross_cents', 49200));
        $this->withSession(['market' => ['country' => 'DE', 'locale' => 'en']])
            ->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.localized_name', 'Shoe EN')
            ->where('products.data.0.display_price.currency', 'EUR')
            ->where('products.data.0.display_price.vat_rate', 19)
            ->where('products.data.0.display_price.gross_cents', 11900));
        $this->withSession(['market' => ['country' => 'LU', 'locale' => 'en']])
            ->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.localized_name', 'Shoe EN')
            ->where('products.data.0.display_price.currency', 'EUR')
            ->where('products.data.0.display_price.vat_rate', 17)
            ->where('products.data.0.display_price.gross_cents', 11700));
    }

    public function test_shared_cache_never_contains_auth_flash_or_market_props(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $this->product($category);

        $this->actingAs($user)->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $user->id)->where('products.total', 1));
        $this->post('/logout')->assertRedirect();
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user', null)->where('products.total', 1));

        $this->withSession(['market' => ['country' => 'BY', 'locale' => 'ru']])
            ->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('market.currency', 'BYN')->where('products.total', 1));
        $this->withSession(['market' => ['country' => 'DE', 'locale' => 'en']])
            ->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('market.country', 'DE')->where('products.total', 1));

        $this->app['session']->flash('success', 'Done!');
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', 'Done!')->where('products.total', 1));
        // Flash belongs to the session, never to the shared catalog cache.
        $this->app['session']->forget('success');
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', null)->where('products.total', 1));
    }

    public function test_a_failing_cache_store_still_serves_correct_pages(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $product = $this->product($category);
        $failing = new class implements Store
        {
            public function get($key)
            {
                throw new \RuntimeException('cache store is down');
            }

            public function many(array $keys)
            {
                throw new \RuntimeException('cache store is down');
            }

            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('cache store is down');
            }

            public function putMany(array $values, $seconds)
            {
                throw new \RuntimeException('cache store is down');
            }

            public function increment($key, $value = 1)
            {
                throw new \RuntimeException('cache store is down');
            }

            public function decrement($key, $value = 1)
            {
                throw new \RuntimeException('cache store is down');
            }

            public function forever($key, $value)
            {
                throw new \RuntimeException('cache store is down');
            }

            public function touch($key, $seconds)
            {
                throw new \RuntimeException('cache store is down');
            }

            public function forget($key)
            {
                throw new \RuntimeException('cache store is down');
            }

            public function flush()
            {
                throw new \RuntimeException('cache store is down');
            }

            public function getPrefix()
            {
                return 'broken';
            }
        };
        Cache::extend('broken', fn () => Cache::repository($failing));
        config()->set('storefront.cache.store', 'broken');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('categories', 1)->has('featured', 0));
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('products.total', 1));
        $this->get('/shop/'.$product->slug)->assertInertia(fn (Assert $page) => $page
            ->where('product.id', $product->id));
        $product->update(['name' => 'Still live']);
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.name', 'Still live'));
    }

    public function test_a_lost_generation_key_never_resurrects_old_data(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $product = $this->product($category);
        $cache = app(StorefrontCache::class);

        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.name', 'Shoes'));
        $before = $cache->generation('card');

        // A write that bypasses model events, followed by the loss of the generation key.
        DB::table('products')->where('id', $product->id)->update(['name' => 'Fresh shoes']);
        Cache::forget('sf:gen:card');
        $this->app->forgetInstance(StorefrontCache::class);

        $cache = app(StorefrontCache::class);
        $this->assertNotSame($before, $cache->generation('card'));
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.name', 'Fresh shoes'));
    }

    public function test_exchange_rates_are_shared_between_requests_without_extra_queries(): void
    {
        ExchangeRate::create(['currency' => 'BYN', 'rate' => 3, 'source' => 'test', 'quoted_at' => now()]);
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $this->product($category);

        $session = fn () => $this->withSession(['market' => ['country' => 'BY', 'locale' => 'ru']]);
        $session()->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.display_price.currency', 'BYN'));

        $rateQueries = $this->measureQueries(
            fn () => $session()->get('/shop')->assertOk(),
            '/\bexchange_rates\b/',
        );
        $this->assertSame(0, $rateQueries, 'A warm rate must not hit the database again');
        Http::assertNothingSent();
    }

    public function test_exchange_rate_refresh_is_seen_by_the_storefront_without_extra_source_calls(): void
    {
        ExchangeRate::create(['currency' => 'BYN', 'rate' => 3, 'source' => 'test', 'quoted_at' => now()]);
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $this->product($category);

        $session = fn () => $this->withSession(['market' => ['country' => 'BY', 'locale' => 'ru']]);
        $session()->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.display_price.gross_cents', 36000));

        config()->set('commerce.exchange_rates.myfin_eur_byn_url', 'https://rates.test/eur-byn');
        Http::fake([
            'rates.test/*' => Http::response('1 EUR = 3.55'),
            '*' => Http::response([], 503),
        ]);
        $this->artisan('commerce:refresh-exchange-rates')->assertExitCode(0);
        $this->assertSame(3.55, (float) ExchangeRate::where('currency', 'BYN')->value('rate'));

        // The write to exchange_rates rotated the rate generation: the next
        // request must show the new rate without calling the sources again.
        $this->app->forgetInstance(ExchangeRateService::class);
        $session()->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.display_price.gross_cents', 42600));
        Http::assertSentCount(2); // myfin for BYN plus the failed nbp fallback for PLN
    }

    public function test_an_unresolvable_rate_is_never_cached(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $this->product($category);
        Http::fake(['*' => Http::response([], 503)]);

        $session = fn () => $this->withSession(['market' => ['country' => 'BY', 'locale' => 'ru']]);
        $session()->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->has('products.data.0.pricing_error')->missing('products.data.0.display_price'));
        $session()->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->has('products.data.0.pricing_error')->missing('products.data.0.display_price'));
        Http::assertSentCount(4); // two sources per request — the failure was not cached
    }

    public function test_cache_clear_rotates_only_storefront_groups_and_warm_prefills_the_cache(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $this->product($category);
        Cache::put('unrelated:key', 'keep-me', 600);

        $this->get('/shop')->assertOk();
        $this->artisan('storefront:cache-clear')->assertExitCode(0);
        $this->assertSame('keep-me', Cache::get('unrelated:key'));

        $this->artisan('storefront:cache-warm', ['--pages' => 1])->assertExitCode(0);
        $queries = $this->measureQueries(fn () => $this->get('/shop')->assertOk());
        $this->assertSame(0, $queries, 'A warmed catalog page should not touch the database');
        $this->assertSame('keep-me', Cache::get('unrelated:key'));
    }

    public function test_a_related_product_edit_is_visible_without_waiting_for_ttl(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $product = $this->product($category, ['name' => 'Main shoe']);
        $related = $this->product($category, ['name' => 'Sibling shoe', 'price_cents_net' => 5000]);

        $this->get('/shop/'.$product->slug)->assertInertia(fn (Assert $page) => $page
            ->where('related.0.id', $related->id)
            ->where('related.0.name', 'Sibling shoe')
            ->where('related.0.price_cents_net', 5000));

        // The detail bundle caches related ids, not cards: editing the
        // related product must surface on the next request.
        $related->update(['name' => 'Sibling shoe v2', 'price_cents_net' => 7500]);

        $this->get('/shop/'.$product->slug)->assertInertia(fn (Assert $page) => $page
            ->where('related.0.id', $related->id)
            ->where('related.0.name', 'Sibling shoe v2')
            ->where('related.0.price_cents_net', 7500));
    }

    public function test_deferred_warm_up_is_scheduled_and_debounced(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $product = $this->product($category);

        // With the flag off (phpunit default) nothing is ever scheduled.
        $product->update(['price_cents_net' => 11000]);
        $this->get('/shop')->assertOk();
        $this->assertFalse(Cache::has('sf:warm:pending'));

        config()->set('storefront.cache.warm.after_invalidation', true);

        // A console-side invalidation returns before Cache::add and must not
        // consume the debounce marker.
        $product->update(['price_cents_net' => 12000]);
        $this->get('/shop')->assertOk();
        $this->assertFalse(Cache::has('sf:warm:pending'));

        // Simulate a web context: runningInConsole() honours the env
        // override once its memoized value is reset. A read request keeps
        // the forgery checks (skipped only for console-driven tests) out
        // of the way.
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';
        $isConsole = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $isConsole->setValue($this->app, null);
        try {
            $product->update(['price_cents_net' => 13000]);
            $this->get('/shop')->assertOk();

            // The deferred warm claimed its debounce marker at termination.
            $this->assertTrue(Cache::has('sf:warm:pending'));
            $this->assertFalse(Cache::add('sf:warm:pending', 1, 10));
        } finally {
            unset($_SERVER['APP_RUNNING_IN_CONSOLE']);
            $isConsole->setValue($this->app, null);
        }

        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.price_cents_net', 13000));
    }
}
