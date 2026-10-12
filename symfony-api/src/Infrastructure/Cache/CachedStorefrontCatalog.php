<?php

namespace App\Infrastructure\Cache;

use App\Application\Catalog\StorefrontCatalog;
use App\Infrastructure\Localization\TranslationCatalog;
use App\Infrastructure\Persistence\Doctrine\Catalog\DbalStorefrontCatalog;

final readonly class CachedStorefrontCatalog implements StorefrontCatalog
{
    public function __construct(
        private DbalStorefrontCatalog $source,
        private StorefrontCache $cache,
        private TranslationCatalog $translations,
        private int $cardTtl,
        private int $maxSearchLength,
        private int $maxPage,
    ) {
    }

    public function categories(string $locale): array
    {
        return $this->cache->remember(
            'tree',
            "categories.{$locale}.{$this->translations->version()}",
            fn (): array => $this->source->categories($locale),
        );
    }

    public function featured(string $locale, int $limit = 8): array
    {
        $ids = $this->cache->remember(
            'list',
            "featured.{$limit}",
            fn (): array => $this->source->featuredIds($limit),
        );

        return $this->cards($ids, $locale);
    }

    public function page(array $filters, int $page, int $perPage, string $locale): array
    {
        $resolve = fn (): array => $this->source->pageIndex($filters, $page, $perPage);
        $search = (string) ($filters['q'] ?? '');
        $cacheable = mb_strlen($search) <= $this->maxSearchLength && $page <= $this->maxPage;
        $index = $cacheable
            ? $this->cache->remember('list', $this->pageKey($filters, $page, $perPage), $resolve)
            : $resolve();

        return [
            ...$index,
            'data' => $this->cards($index['ids'], $locale),
        ];
    }

    public function detail(string $slug, string $locale): ?array
    {
        $key = "detail.{$slug}.{$locale}.{$this->translations->version()}";
        $found = $this->cache->readMany('card', [$key]);
        if (array_key_exists($key, $found)) {
            $detail = $found[$key];
        } else {
            $detail = $this->source->detailIndex($slug, $locale);
            if ($detail === null) {
                return null;
            }
            $this->cache->writeMany('card', [$key => $detail], $this->cardTtl);
        }

        return [
            'product' => $detail['product'],
            'breadcrumbs' => $detail['breadcrumbs'],
            'related' => $this->cards($detail['related_ids'], $locale),
        ];
    }

    private function cards(array $ids, string $locale): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $version = $this->translations->version();
        $logicalKey = fn (int $id): string => "card.{$id}.{$locale}.{$version}";
        $logicalKeys = array_map($logicalKey, $ids);
        $found = $this->cache->readMany('card', $logicalKeys);
        $byId = [];
        $missing = [];
        foreach ($ids as $id) {
            $key = $logicalKey($id);
            if (array_key_exists($key, $found)) {
                $byId[$id] = $found[$key];
            } else {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            $loaded = $this->source->cards($missing, $locale);
            $write = [];
            foreach ($loaded as $product) {
                $id = (int) $product['id'];
                $byId[$id] = $product;
                $write[$logicalKey($id)] = $product;
            }
            $this->cache->writeMany('card', $write, $this->cardTtl);
        }

        return array_values(array_filter(
            array_map(fn (int $id): ?array => $byId[$id] ?? null, $ids),
        ));
    }

    private function pageKey(array $filters, int $page, int $perPage): string
    {
        return 'page.'.hash('sha256', json_encode([
            'q' => $filters['q'] ?? null,
            'category' => $filters['category'] ?? null,
            'sort' => $filters['sort'] ?? null,
            'page' => $page,
            'per_page' => $perPage,
        ], JSON_THROW_ON_ERROR));
    }
}
