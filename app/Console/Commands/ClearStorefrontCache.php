<?php

namespace App\Console\Commands;

use App\Services\Cache\StorefrontCache;
use Illuminate\Console\Command;

final class ClearStorefrontCache extends Command
{
    protected $signature = 'storefront:cache-clear';

    protected $description = 'Rotate the storefront cache generations (tree, list, card).';

    public function handle(StorefrontCache $cache): int
    {
        $cache->flush();
        $this->components->info('Storefront cache cleared for groups: tree, list, card.');

        return self::SUCCESS;
    }
}
