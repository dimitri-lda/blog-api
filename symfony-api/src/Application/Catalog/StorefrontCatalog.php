<?php

namespace App\Application\Catalog;

interface StorefrontCatalog
{
    public function categories(string $locale): array;

    public function featured(string $locale, int $limit = 8): array;

    public function page(array $filters, int $page, int $perPage, string $locale): array;

    public function detail(string $slug, string $locale): ?array;
}
