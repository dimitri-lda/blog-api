<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Cache\StorefrontCache;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontCacheBenchTest extends TestCase
{
    use RefreshDatabase;

    private int $queries = 0;

    private bool $counting = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        DB::listen(function (): void {
            if ($this->counting) {
                $this->queries++;
            }
        });
    }

    public function test_storefront_cache_benchmark(): void
    {
        if (env('STOREFRONT_BENCH') !== '1') {
            $this->markTestSkipped('Set STOREFRONT_BENCH=1 to run the storefront cache benchmark.');
        }

        $this->seed();
        $slug = Product::where('active', true)->value('slug');
        $targets = ['/', '/shop', '/shop/'.$slug];
        $inertiaHeaders = ['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()];

        $rows = [];
        foreach ([false, true] as $enabled) {
            config()->set('storefront.cache.enabled', $enabled);
            foreach ($targets as $uri) {
                foreach ([false, true] as $inertia) {
                    $headers = $inertia ? $inertiaHeaders : [];
                    app(StorefrontCache::class)->flush();
                    $cold = $this->measure(fn () => $this->get($uri, $headers)->assertOk());
                    $warm = [];
                    for ($i = 0; $i < 5; $i++) {
                        $warm[] = $this->measure(fn () => $this->get($uri, $headers)->assertOk());
                    }
                    $rows[] = [
                        'enabled' => $enabled ? 'on' : 'off',
                        'uri' => $uri,
                        'via' => $inertia ? 'inertia' : 'html',
                        'cold_ms' => $cold['ms'],
                        'cold_sql' => $cold['sql'],
                        'warm_ms' => array_sum(array_column($warm, 'ms')) / count($warm),
                        'warm_sql' => array_sum(array_column($warm, 'sql')) / count($warm),
                        'warm_ms_min' => min(array_column($warm, 'ms')),
                        'warm_ms_max' => max(array_column($warm, 'ms')),
                    ];
                }
            }
        }

        $out = PHP_EOL.'=== STOREFRONT CACHE BENCH ==='.PHP_EOL;
        $out .= sprintf(
            '%-6s %-28s %-8s %10s %9s %12s %9s %20s%s',
            'cache', 'uri', 'via', 'cold_ms', 'cold_sql', 'warm_ms_avg', 'warm_sql', 'warm_ms_range', PHP_EOL,
        );
        foreach ($rows as $row) {
            $out .= sprintf(
                '%-6s %-28s %-8s %10.1f %9d %12.1f %9.1f %20s%s',
                $row['enabled'], $row['uri'], $row['via'],
                $row['cold_ms'], (int) $row['cold_sql'], $row['warm_ms'], $row['warm_sql'],
                sprintf('%.1f..%.1f', $row['warm_ms_min'], $row['warm_ms_max']), PHP_EOL,
            );
        }
        fwrite(STDERR, $out);
        $this->assertTrue(true);
    }

    /** @return array{ms: float, sql: int} */
    private function measure(Closure $run): array
    {
        $this->queries = 0;
        $this->counting = true;
        $start = microtime(true);
        try {
            $run();
        } finally {
            $elapsed = (microtime(true) - $start) * 1000;
            $this->counting = false;
        }

        return ['ms' => $elapsed, 'sql' => $this->queries];
    }

    private function inertiaVersion(): string
    {
        $manifest = public_path('build/manifest.json');

        return is_file($manifest) ? (string) hash_file('xxh128', $manifest) : '';
    }
}
