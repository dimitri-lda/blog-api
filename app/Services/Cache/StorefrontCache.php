<?php

namespace App\Services\Cache;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use function Illuminate\Support\defer;

final class StorefrontCache
{
    private const MISS = '__sf_miss__';

    /** @var array<string, string> */
    private array $generations = [];

    private bool $failureLogged = false;

    public function __construct(private readonly Factory $cache) {}

    public function enabled(): bool
    {
        return (bool) config('storefront.cache.enabled', true);
    }

    /**
     * Stale-while-revalidate read for generation-scoped groups ('tree', 'list').
     * The callback's exceptions always propagate to the caller.
     */
    public function remember(string $group, string $key, Closure $callback): mixed
    {
        if (! $this->enabled()) {
            return $callback();
        }
        $full = $this->key($group, $key);
        if ($full === null) {
            return $callback();
        }
        $entry = $this->read($full);
        if ($entry !== null && $entry['fresh_until'] > time()) {
            return $entry['value'];
        }
        if ($entry !== null) {
            $this->deferRefresh($group, $full, $callback);

            return $entry['value'];
        }

        return $this->fill($group, $full, $callback);
    }

    /**
     * Batch read for generation-scoped groups ('card'). Returns found entries
     * keyed by the caller's logical keys.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function readMany(string $group, array $keys): array
    {
        if (! $this->enabled() || $keys === []) {
            return [];
        }
        $map = [];
        foreach ($keys as $key) {
            $full = $this->key($group, $key);
            if ($full !== null) {
                $map[$full] = $key;
            }
        }
        if ($map === []) {
            return [];
        }
        $values = $this->safely(fn () => $this->store()->many(array_keys($map)), []);
        $found = [];
        foreach ($values as $full => $value) {
            if ($value !== null && isset($map[$full])) {
                $found[$map[$full]] = $value;
            }
        }

        return $found;
    }

    /** @param  array<string, mixed>  $values */
    public function writeMany(string $group, array $values, int $ttl): void
    {
        if (! $this->enabled() || $values === []) {
            return;
        }
        $mapped = [];
        foreach ($values as $key => $value) {
            $full = $this->key($group, (string) $key);
            if ($full !== null) {
                $mapped[$full] = $value;
            }
        }
        if ($mapped !== []) {
            $this->safely(fn () => $this->store()->putMany($mapped, $ttl));
        }
    }

    public function forget(string $group, string|array $keys): void
    {
        if (! $this->enabled()) {
            return;
        }
        foreach ((array) $keys as $key) {
            $full = $this->key($group, $key);
            if ($full !== null) {
                $this->safely(fn () => $this->store()->forget($full));
            }
        }
    }

    /**
     * Simple read-through outside of generations (rates, translations).
     * Null callback results are returned but never stored.
     */
    public function rememberValue(string $key, int $ttl, Closure $callback): mixed
    {
        if (! $this->enabled()) {
            return $callback();
        }
        $full = $this->prefix().':'.$key;
        $hit = $this->safely(fn () => $this->store()->get($full, self::MISS), self::MISS);
        if ($hit !== self::MISS) {
            return $hit;
        }
        $value = $callback();
        if ($value !== null) {
            $this->safely(fn () => $this->store()->put($full, $value, $ttl));
        }

        return $value;
    }

    /** Read a value outside of generations ('rate:*', 'tr:*'); null on miss or failure. */
    public function readValue(string $key): mixed
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->safely(fn () => $this->store()->get($this->prefix().':'.$key));
    }

    /** Write a value outside of generations; failures are swallowed via safely(). */
    public function putValue(string $key, mixed $value, int $ttl): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->safely(fn () => $this->store()->put($this->prefix().':'.$key, $value, $ttl));
    }

    public function forgetValue(string $key): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->safely(fn () => $this->store()->forget($this->prefix().':'.$key));
    }

    public function rotate(string ...$groups): void
    {
        if (! $this->enabled()) {
            return;
        }
        foreach ($groups as $group) {
            $this->safely(fn () => $this->store()->forget($this->prefix().':gen:'.$group));
            unset($this->generations[$group]);
        }
    }

    public function flush(): void
    {
        $this->rotate('tree', 'list', 'card');
    }

    /**
     * Generation token for a group, or null when the store is unavailable.
     * A lost token always produces a new namespace, never the old data.
     */
    public function generation(string $group): ?string
    {
        if (isset($this->generations[$group])) {
            return $this->generations[$group];
        }
        $storeKey = $this->prefix().':gen:'.$group;
        $token = $this->safely(fn () => $this->store()->get($storeKey));
        if (! is_string($token) || $token === '') {
            $this->safely(fn () => $this->store()->add(
                $storeKey,
                Str::random(8),
                (int) config('storefront.cache.generation_ttl', 2592000),
            ));
            $token = $this->safely(fn () => $this->store()->get($storeKey));
        }
        if (! is_string($token) || $token === '') {
            return null;
        }

        return $this->generations[$group] = $token;
    }

    private function store(): Repository
    {
        return $this->cache->store(config('storefront.cache.store'));
    }

    private function prefix(): string
    {
        return (string) config('storefront.cache.prefix', 'sf');
    }

    private function key(string $group, string $key): ?string
    {
        $generation = $this->generation($group);
        if ($generation === null) {
            return null;
        }

        return $this->prefix().":{$group}:{$generation}:{$key}";
    }

    /** @return array{value: mixed, fresh_until: int}|null */
    private function read(string $full): ?array
    {
        $entry = $this->safely(fn () => $this->store()->get($full));
        if (! is_array($entry) || ! array_key_exists('value', $entry) || ! isset($entry['fresh_until'])) {
            return null;
        }

        return $entry;
    }

    private function write(string $group, string $full, mixed $value): void
    {
        $ttl = $this->ttl($group);
        $this->safely(fn () => $this->store()->put($full, [
            'value' => $value,
            'fresh_until' => time() + $ttl['fresh'],
        ], $ttl['total']));
    }

    /** @return array{fresh: int, total: int} */
    private function ttl(string $group): array
    {
        $ttl = config("storefront.cache.ttl.{$group}", 0);
        if (is_array($ttl)) {
            return ['fresh' => (int) $ttl['fresh'], 'total' => (int) $ttl['total']];
        }

        return ['fresh' => (int) $ttl, 'total' => (int) $ttl];
    }

    private function fill(string $group, string $full, Closure $callback): mixed
    {
        $store = $this->store();
        $lock = null;
        if ($store->getStore() instanceof LockProvider) {
            $seconds = (int) config('storefront.cache.lock_seconds', 10);
            $lock = $this->safely(fn () => $store->getStore()->lock('lock:'.$full, $seconds));
            if ($lock !== null && ! $this->safely(fn () => $lock->get(), false)) {
                $lock = null; // another process holds it
            }
        }
        if ($lock !== null) {
            try {
                $entry = $this->read($full);
                if ($entry !== null && $entry['fresh_until'] > time()) {
                    return $entry['value'];
                }
                $value = $callback();
                $this->write($group, $full, $value);

                return $value;
            } finally {
                $this->safely(fn () => $lock->release());
            }
        }

        if ($store->getStore() instanceof LockProvider) {
            // The lock is busy: wait briefly for another process to publish.
            $waitMs = (int) config('storefront.cache.lock_wait_ms', 500);
            for ($waited = 0; $waited < $waitMs; $waited += 50) {
                usleep(50_000);
                $entry = $this->read($full);
                if ($entry !== null) {
                    return $entry['value'];
                }
            }
        }

        $value = $callback();
        $this->write($group, $full, $value);

        return $value;
    }

    private function deferRefresh(string $group, string $full, Closure $callback): void
    {
        if (app()->runningInConsole()) {
            $this->fill($group, $full, $callback);

            return;
        }
        defer(function () use ($group, $full, $callback): void {
            try {
                $this->fill($group, $full, $callback);
            } catch (\Throwable $e) {
                Log::warning('Storefront cache refresh failed.', ['exception' => $e]);
            }
        });
    }

    private function safely(Closure $operation, mixed $default = null): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            if (! $this->failureLogged) {
                $this->failureLogged = true;
                Log::warning('Storefront cache store failure.', ['exception' => $e]);
            }

            return $default;
        }
    }
}
