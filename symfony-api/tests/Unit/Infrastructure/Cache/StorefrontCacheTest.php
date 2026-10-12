<?php

namespace App\Tests\Unit\Infrastructure\Cache;

use App\Infrastructure\Cache\DeferredTaskRegistry;
use App\Infrastructure\Cache\StorefrontCache;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;

final class StorefrontCacheTest extends TestCase
{
    /** @var list<string> */
    private array $lockDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->lockDirs as $dir) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    private function cache(
        ?ArrayAdapter &$pool,
        ?DeferredTaskRegistry &$registry,
        AbstractLogger $logger,
        bool $enabled = true,
        ?PersistingStoreInterface $lockStore = null,
    ): StorefrontCache {
        $pool = new ArrayAdapter();
        $registry = new DeferredTaskRegistry($logger);
        if ($lockStore === null) {
            $dir = sys_get_temp_dir().'/sf-lock-'.bin2hex(random_bytes(6));
            mkdir($dir);
            $this->lockDirs[] = $dir;
            $lockStore = new FlockStore($dir);
        }

        return new StorefrontCache(
            $pool,
            new LockFactory($lockStore),
            $registry,
            $logger,
            $enabled,
            'sf',
            2592000,
            10,
            500,
            ['tree' => ['fresh' => 600, 'total' => 1800], 'list' => ['fresh' => 120, 'total' => 600]],
        );
    }

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };
    }

    #[Test]
    public function a_miss_is_filled_once_and_fresh_hits_do_not_invoke_the_resolver(): void
    {
        $cache = $this->cache($pool, $registry, $this->logger());
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return ['a' => 1];
        };

        $this->assertSame(['a' => 1], $cache->remember('tree', 'k1', $resolver));
        $this->assertSame(['a' => 1], $cache->remember('tree', 'k1', $resolver));
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function a_stale_hit_returns_immediately_and_one_deferred_refresh_renews_the_entry(): void
    {
        $cache = $this->cache($pool, $registry, $this->logger());
        $generation = $cache->generation('tree');
        $this->assertNotNull($generation);

        $key = 'sf.tree.'.$generation.'.'.hash('sha256', 'k1');
        $item = $pool->getItem($key);
        $item->set(['value' => 'old', 'fresh_until' => time() - 5])->expiresAfter(1800);
        $pool->save($item);

        $refreshes = 0;
        $resolver = function () use (&$refreshes) {
            $refreshes++;

            return 'new';
        };

        $this->assertSame('old', $cache->remember('tree', 'k1', $resolver));
        $this->assertSame('old', $cache->remember('tree', 'k1', $resolver));

        $registry->run();
        $this->assertSame(1, $refreshes);
        $this->assertSame('new', $cache->remember('tree', 'k1', fn () => 'later'));
    }

    #[Test]
    public function resolver_exceptions_propagate_and_are_never_cached_or_logged(): void
    {
        $logger = $this->logger();
        $cache = $this->cache($pool, $registry, $logger);
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;
            throw new RuntimeException('boom');
        };

        foreach ([1, 2] as $ignored) {
            try {
                $cache->remember('tree', 'k1', $resolver);
                $this->fail('Resolver exception did not propagate.');
            } catch (RuntimeException) {
            }
        }

        $this->assertSame(2, $calls);
        $this->assertSame([], $logger->records);
    }

    #[Test]
    public function rotating_a_group_changes_the_generation_and_hides_old_entries(): void
    {
        $cache = $this->cache($pool, $registry, $this->logger());
        $before = $cache->generation('tree');
        $this->assertSame('v1', $cache->remember('tree', 'k1', fn () => 'v1'));

        $cache->rotate('tree');

        $after = $cache->generation('tree');
        $this->assertNotSame($before, $after);
        $this->assertSame('v2', $cache->remember('tree', 'k1', fn () => 'v2'));
    }

    #[Test]
    public function read_many_and_write_many_preserve_logical_keys_and_forget_is_selective(): void
    {
        $cache = $this->cache($pool, $registry, $this->logger());

        $cache->writeMany('card', ['a' => ['n' => 'A'], 'b' => ['n' => 'B']], 300);
        $this->assertSame(
            ['a' => ['n' => 'A'], 'b' => ['n' => 'B']],
            $cache->readMany('card', ['a', 'b']),
        );

        $cache->forget('card', 'a');
        $this->assertSame(['b' => ['n' => 'B']], $cache->readMany('card', ['a', 'b']));
    }

    #[Test]
    public function a_disabled_cache_never_touches_the_pool(): void
    {
        $cache = $this->cache($pool, $registry, $this->logger(), enabled: false);
        $this->assertFalse($cache->enabled());

        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return 'v';
        };

        $this->assertSame('v', $cache->remember('tree', 'k1', $resolver));
        $this->assertSame('v', $cache->remember('tree', 'k1', $resolver));
        $this->assertSame(2, $calls);
        $this->assertSame([], $pool->getValues());
    }

    #[Test]
    public function an_unknown_group_is_rejected_before_any_resolution(): void
    {
        $cache = $this->cache($pool, $registry, $this->logger());

        $this->expectException(InvalidArgumentException::class);
        $cache->remember('unknown', 'k1', fn () => 'v');
    }

    #[Test]
    public function flush_rotates_only_the_storefront_groups(): void
    {
        $cache = $this->cache($pool, $registry, $this->logger());
        $tree = $cache->generation('tree');
        $list = $cache->generation('list');
        $card = $cache->generation('card');
        $rate = $cache->generation('rate');

        $cache->flush();

        $this->assertNotSame($tree, $cache->generation('tree'));
        $this->assertNotSame($list, $cache->generation('list'));
        $this->assertNotSame($card, $cache->generation('card'));
        $this->assertSame($rate, $cache->generation('rate'));
    }

    #[Test]
    public function a_lock_store_failure_resolves_immediately_without_polling(): void
    {
        $logger = $this->logger();
        $cache = $this->cache($pool, $registry, $logger, lockStore: $this->failingLockStore());
        $item = $pool->getItem('sf.gen.tree');
        $item->set('seeded-gen');
        $pool->save($item);

        $calls = 0;
        $started = microtime(true);
        $value = $cache->remember('tree', 'k1', function () use (&$calls) {
            $calls++;

            return 'resolved';
        });
        $elapsed = microtime(true) - $started;

        $this->assertSame('resolved', $value);
        $this->assertSame(1, $calls);
        $this->assertLessThan(0.4, $elapsed);
        $this->assertSame(
            [['level' => 'warning', 'message' => 'Storefront cache store failure.']],
            $logger->records,
        );
    }

    #[Test]
    public function a_resolver_exception_is_not_masked_by_lock_failures(): void
    {
        $cache = $this->cache($pool, $registry, $this->logger(), lockStore: $this->failingLockStore());
        $item = $pool->getItem('sf.gen.tree');
        $item->set('seeded-gen');
        $pool->save($item);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('business broke');
        $cache->remember('tree', 'k1', fn () => throw new DomainException('business broke'));
    }

    #[Test]
    public function a_disabled_cache_does_not_rotate_generations(): void
    {
        $cache = $this->cache($pool, $registry, $this->logger(), enabled: false);
        $item = $pool->getItem('sf.gen.tree');
        $item->set('keepme');
        $pool->save($item);

        $cache->rotate('tree');

        $this->assertSame('keepme', $pool->getItem('sf.gen.tree')->get());
    }

    private function failingLockStore(): PersistingStoreInterface
    {
        return new class implements PersistingStoreInterface {
            public function save(Key $key): void
            {
                throw new RuntimeException('lock store down');
            }

            public function delete(Key $key): void
            {
            }

            public function exists(Key $key): bool
            {
                throw new RuntimeException('lock store down');
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
            }
        };
    }
}
