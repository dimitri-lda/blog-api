<?php

namespace App\Observers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSpecification;
use App\Models\ProductVariant;
use App\Services\Cache\StorefrontCache;
use App\Services\TranslationCatalog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\defer;

/**
 * Keeps the storefront cache narrow: a stock change or an edit of a single
 * product invalidates only that product's entries, while structural changes
 * rotate the generations of the affected list/tree groups.
 */
final class StorefrontCacheObserver
{
    private const PRODUCT_LIST_FIELDS = [
        'slug', 'active', 'featured', 'category_id', 'price_cents_net',
        'compare_at_price_cents_net', 'name', 'name_translations',
        'brand', 'brand_id', 'manufacturer_id',
    ];

    private const PRODUCT_TREE_FIELDS = ['active', 'category_id'];

    private const CATEGORY_LIST_FIELDS = ['slug', 'parent_id'];

    public function created(Model $model): void
    {
        $this->invalidate($model, true);
    }

    public function updated(Model $model): void
    {
        $this->invalidate($model, false);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model, true);
    }

    public function restored(Model $model): void
    {
        $this->invalidate($model, true);
    }

    private function invalidate(Model $model, bool $structural): void
    {
        // On `updated` (including increment/decrement) the actual fields live
        // in getChanges(); getDirty() covers listeners fired before a save.
        $changed = array_keys($model->getDirty() + $model->getChanges());
        $actions = $this->actionsFor($model, $structural, $changed);
        foreach ($actions as $action) {
            $this->dispatch($action);
        }
        if ($actions !== []) {
            $this->scheduleWarm();
        }
    }

    /**
     * Invalidation runs immediately and, inside an open transaction, once
     * more after commit (after-commit callbacks never fire under the outer
     * transaction of RefreshDatabase tests, so both paths are required).
     *
     * @param  array<int, Closure>  $actions
     */
    private function dispatch(Closure $action): void
    {
        $action();
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($action);
        }
    }

    /** @return array<int, Closure> */
    private function actionsFor(Model $model, bool $structural, array $changed): array
    {
        $cache = app(StorefrontCache::class);
        if ($model instanceof Product) {
            $actions = [fn () => $this->forgetProductKeys((int) $model->id)];
            if ($structural || array_intersect($changed, self::PRODUCT_LIST_FIELDS) !== []) {
                $actions[] = fn () => $cache->rotate('list');
            }
            if ($structural || array_intersect($changed, self::PRODUCT_TREE_FIELDS) !== []) {
                $actions[] = fn () => $cache->rotate('tree');
            }

            return $actions;
        }
        if ($model instanceof ProductVariant
            || $model instanceof ProductImage
            || $model instanceof ProductSpecification) {
            return $this->productKeyActions($model);
        }
        if ($model instanceof Category) {
            $actions = [
                fn () => $cache->rotate('tree'),
                fn () => $cache->rotate('card'),
            ];
            if ($structural || array_intersect($changed, self::CATEGORY_LIST_FIELDS) !== []) {
                $actions[] = fn () => $cache->rotate('list');
            }

            return $actions;
        }
        if ($model instanceof Brand || $model instanceof Manufacturer) {
            return [
                fn () => $cache->rotate('card'),
                fn () => $cache->rotate('list'),
            ];
        }

        return [];
    }

    /**
     * Product children only bust the entries of the products they belong to,
     * including the previous owner when product_id itself changed.
     *
     * @return array<int, Closure>
     */
    private function productKeyActions(Model $model): array
    {
        $productIds = [(int) $model->product_id];
        $original = $model->getOriginal('product_id');
        if ($original !== null && (int) $original !== $productIds[0]) {
            $productIds[] = (int) $original;
        }

        return array_map(
            fn (int $id): Closure => fn () => $this->forgetProductKeys($id),
            array_unique($productIds),
        );
    }

    private function forgetProductKeys(int $productId): void
    {
        $cache = app(StorefrontCache::class);
        $version = TranslationCatalog::translationsVersion();
        $keys = [];
        foreach ((array) config('storefront.cache.locales', ['en']) as $locale) {
            $keys[] = "card:{$productId}:{$locale}:{$version}";
            $keys[] = "detail:{$productId}:{$locale}:{$version}";
        }
        $cache->forget('card', $keys);
    }

    /**
     * Queue a deferred warm-up after a web-request invalidation, debounced
     * through the 'sf:warm:pending' marker. The console check runs first so
     * an artisan-triggered invalidation never consumes the marker and blocks
     * a legitimate web warm-up for the debounce window.
     */
    private function scheduleWarm(): void
    {
        if (! (bool) config('storefront.cache.warm.after_invalidation')) {
            return;
        }
        defer(function (): void {
            try {
                if (app()->runningInConsole()) {
                    return;
                }
                $debounce = (int) config('storefront.cache.warm.debounce_seconds', 10);
                if (! Cache::add('sf:warm:pending', 1, $debounce)) {
                    return;
                }
                Artisan::call('storefront:cache-warm');
            } catch (\Throwable $e) {
                Log::warning('Storefront cache warm-up failed.', ['exception' => $e]);
            }
        });
    }
}
