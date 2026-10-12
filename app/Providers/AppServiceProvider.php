<?php

namespace App\Providers;

use App\Domain\Cart\Repository\CartRepository;
use App\Domain\Orders\Repository\AdminOrderRepository;
use App\Domain\Orders\Repository\OrderRepository;
use App\Infrastructure\Persistence\Eloquent\Cart\EloquentCartRepository;
use App\Infrastructure\Persistence\Eloquent\Orders\EloquentAdminOrderRepository;
use App\Infrastructure\Persistence\Eloquent\Orders\EloquentOrderRepository;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSpecification;
use App\Models\ProductVariant;
use App\Observers\StorefrontCacheObserver;
use App\Services\Cache\StorefrontCache;
use App\Services\ExchangeRateService;
use App\Services\TranslationCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CartRepository::class, EloquentCartRepository::class);
        $this->app->bind(OrderRepository::class, EloquentOrderRepository::class);
        $this->app->bind(AdminOrderRepository::class, EloquentAdminOrderRepository::class);
        $this->app->singleton(TranslationCatalog::class);
        $this->app->singleton(ExchangeRateService::class);
        $this->app->singleton(StorefrontCache::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        foreach ([
            Product::class, ProductVariant::class, Category::class, Brand::class,
            Manufacturer::class, ProductImage::class, ProductSpecification::class,
        ] as $model) {
            $model::observe(StorefrontCacheObserver::class);
        }

        if ((bool) config('storefront.cache.enabled', true)) {
            // Exchange-rate quotes are money and must not go stale, but mass
            // operations and raw SQL on exchange_rates bypass model events —
            // rotate the shared 'rate' generation on every write to the table.
            $writePattern = '/^\s*(insert\s+into|update|delete\s+from)\s+["`\[]?exchange_rates\b/i';
            DB::listen(function (QueryExecuted $query) use ($writePattern): void {
                if (str_contains($query->sql, 'exchange_rates') && preg_match($writePattern, $query->sql)) {
                    app(StorefrontCache::class)->rotate('rate');
                }
            });
        }

        $proxies = config('app.trusted_proxies', []);
        if ($proxies !== []) {
            TrustProxies::at($proxies);
        }
    }
}
