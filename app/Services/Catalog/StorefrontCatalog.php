<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Services\Cache\StorefrontCache;
use App\Services\TranslationCatalog;
use Illuminate\Support\Collection;

/**
 * Cached read-side of the storefront catalog. Every public method returns
 * plain arrays and scalars (results of Model::toArray), never models,
 * collections, dates or paginators.
 */
final class StorefrontCatalog
{
    public function __construct(private readonly StorefrontCache $cache) {}

    /** @return array<int, array<string, mixed>> */
    public function categories(): array
    {
        return $this->cache->remember('tree', 'tree:'.$this->localeSuffix(), function (): array {
            $categories = Category::withCount(['products' => fn ($query) => $query->where('active', true)])
                ->orderBy('name')->get();
            $counts = $categories->pluck('products_count', 'id');

            return $categories->map(function (Category $category) use ($categories, $counts): array {
                $data = $category->toArray();
                $data['products_count'] = $counts->only($this->descendantIds($category->id, $categories))->sum();

                return $data;
            })->all();
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function featured(): array
    {
        $ids = $this->cache->remember('list', 'featured', fn (): array => Product::query()
            ->where('featured', true)->where('active', true)->take(8)->pluck('id')->all());

        return $this->cards($ids);
    }

    /**
     * @param  array{q?: ?string, category?: ?string, sort?: ?string}  $filters
     * @return array{ids: array<int, int>, total: int, data: array<int, array<string, mixed>>}
     */
    public function page(array $filters, int $page, int $perPage): array
    {
        $resolve = fn (): array => $this->resolvePage($filters, $page, $perPage);
        $search = (string) ($filters['q'] ?? '');
        $cacheable = mb_strlen($search) <= (int) config('storefront.cache.max_cached_search_length', 32)
            && $page <= (int) config('storefront.cache.max_cached_page', 20);
        $key = 'page:'.sha1((string) json_encode([
            'q' => $filters['q'] ?? null,
            'category' => $filters['category'] ?? null,
            'sort' => $filters['sort'] ?? null,
            'page' => $page,
            'perPage' => $perPage,
        ]));
        $result = $cacheable ? $this->cache->remember('list', $key, $resolve) : $resolve();

        return [
            'ids' => $result['ids'],
            'total' => $result['total'],
            'data' => $this->cards($result['ids']),
        ];
    }

    /**
     * Product cards in the exact order of $ids; ids missing in the database
     * are skipped. Card payloads are cached per product, locale and
     * translation version.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function cards(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $keyOf = fn (int $id): string => "card:{$id}:".$this->localeSuffix();
        $byId = [];
        $missing = [];
        $found = $this->cache->readMany('card', array_map($keyOf, $ids));
        foreach ($ids as $id) {
            $key = $keyOf($id);
            if (array_key_exists($key, $found)) {
                $byId[$id] = $found[$key];
            } else {
                $missing[] = $id;
            }
        }
        if ($missing !== []) {
            $loaded = Product::with(['category', 'variants', 'brandRecord', 'manufacturer'])
                ->whereIn('id', $missing)->get();
            $write = [];
            foreach ($loaded as $product) {
                $card = $product->toArray();
                $byId[$product->id] = $card;
                $write[$keyOf($product->id)] = $card;
            }
            $this->cache->writeMany('card', $write, (int) config('storefront.cache.ttl.card', 1800));
        }

        $cards = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $cards[] = $byId[$id];
            }
        }

        return $cards;
    }

    /**
     * The cached bundle holds product, breadcrumbs and related_ids only;
     * the related cards themselves are resolved through cards() on every
     * call so a change to a related product never goes stale inside
     * another product's detail payload.
     *
     * @return array{product: array<string, mixed>, breadcrumbs: array<int, array>, related: array<int, array>}
     */
    public function detail(Product $product): array
    {
        $key = "detail:{$product->id}:".$this->localeSuffix();
        $found = $this->cache->readMany('card', [$key]);
        if (array_key_exists($key, $found)) {
            $bundle = $found[$key];
        } else {
            $bundle = $this->resolveDetail($product);
            $this->cache->writeMany('card', [$key => $bundle], (int) config('storefront.cache.ttl.card', 1800));
        }

        return [
            'product' => $bundle['product'],
            'breadcrumbs' => $bundle['breadcrumbs'],
            'related' => $this->cards($bundle['related_ids']),
        ];
    }

    /** @return array{product: array<string, mixed>, breadcrumbs: array<int, array>, related_ids: array<int, int>} */
    private function resolveDetail(Product $product): array
    {
        $byId = [];
        foreach ($this->categories() as $entry) {
            $byId[$entry['id']] = $entry;
        }
        $breadcrumbs = [];
        $category = $byId[$product->category_id] ?? null;
        $seen = [];
        while ($category !== null && ! in_array($category['id'], $seen, true)) {
            $seen[] = $category['id'];
            unset($category['products_count']);
            array_unshift($breadcrumbs, $category);
            $category = $byId[$category['parent_id']] ?? null;
        }

        $relatedIds = Product::where('active', true)
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->take(4)->pluck('id')->all();

        return [
            'product' => $product->load([
                'category', 'variants', 'images', 'specifications', 'brandRecord', 'manufacturer',
            ])->toArray(),
            'breadcrumbs' => $breadcrumbs,
            'related_ids' => array_map('intval', $relatedIds),
        ];
    }

    /**
     * @param  array{q?: ?string, category?: ?string, sort?: ?string}  $filters
     * @return array{ids: array<int, int>, total: int}
     */
    private function resolvePage(array $filters, int $page, int $perPage): array
    {
        $query = Product::query()->where('active', true);
        if (filled($filters['q'] ?? null)) {
            $pattern = $this->likePattern((string) $filters['q']);
            $query->where(fn ($q) => $q
                ->where('name', 'like', $pattern)
                ->orWhere('brand', 'like', $pattern)
                ->orWhereHas('brandRecord', fn ($brands) => $brands->where('name', 'like', $pattern)));
        }
        if (filled($filters['category'] ?? null)) {
            $category = Category::where('slug', (string) $filters['category'])->firstOrFail();
            $query->whereIn('category_id', $this->descendantIds($category->id));
        }
        if (in_array($filters['sort'] ?? null, ['price_asc', 'price_desc'], true)) {
            $priceColumn = $query->getModel()->qualifyColumn('price_cents_net');
            $query->orderBy($priceColumn, $filters['sort'] === 'price_asc' ? 'ASC' : 'DESC')->orderBy('id');
        } else {
            $query->latest()->orderByDesc('id');
        }

        $paginator = $query->paginate($perPage, ['id'], 'page', $page);

        return [
            'ids' => array_map('intval', $paginator->getCollection()->pluck('id')->all()),
            'total' => $paginator->total(),
        ];
    }

    /**
     * The id set of a category plus all of its descendants (BFS), identical
     * to the traversal previously inlined in StoreController.
     *
     * @return array<int, int>
     */
    private function descendantIds(int $rootId, ?Collection $categories = null): array
    {
        $categories ??= Category::get(['id', 'parent_id']);
        $ids = [$rootId];
        for ($index = 0; $index < count($ids); $index++) {
            foreach ($categories->where('parent_id', $ids[$index]) as $child) {
                if (! in_array($child->id, $ids)) {
                    $ids[] = $child->id;
                }
            }
        }

        return $ids;
    }

    private function likePattern(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }

    private function localeSuffix(): string
    {
        return app()->getLocale().':'.TranslationCatalog::translationsVersion();
    }
}
