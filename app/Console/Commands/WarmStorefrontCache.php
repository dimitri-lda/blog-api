<?php

namespace App\Console\Commands;

use App\Services\Catalog\StorefrontCatalog;
use Illuminate\Console\Command;

final class WarmStorefrontCache extends Command
{
    protected $signature = 'storefront:cache-warm {--pages=} {--locale=*}';

    protected $description = 'Pre-populate the storefront cache (category tree, featured, catalog pages).';

    public function handle(StorefrontCatalog $catalog): int
    {
        $pages = max(1, (int) ($this->option('pages') ?? config('storefront.cache.warm.pages', 2)));
        $locales = $this->option('locale') ?: (array) config('storefront.cache.locales', ['en']);
        $original = app()->getLocale();
        try {
            foreach ($locales as $locale) {
                app()->setLocale($locale);
                $catalog->categories();
                $catalog->featured();
                for ($page = 1; $page <= $pages; $page++) {
                    $catalog->page(['q' => null, 'category' => null, 'sort' => null], $page, 12);
                }
            }
        } finally {
            app()->setLocale($original);
        }
        $this->components->info('Storefront cache warmed for locales: '.implode(', ', $locales));

        return self::SUCCESS;
    }
}
