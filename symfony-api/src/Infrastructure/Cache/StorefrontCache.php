<?php

namespace App\Infrastructure\Cache;

use Closure;
use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Throwable;

final class StorefrontCache
{
    private const GROUPS = ['tree', 'list', 'card'];

    /** @var array<string, string> */
    private array $generations = [];

    private bool $failureLogged = false;

    public function __construct(
        #[Target('cache.storefront')]
        private readonly CacheItemPoolInterface $pool,
        private readonly LockFactory $locks,
        private readonly DeferredTaskRegistry $deferred,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
        private readonly string $prefix,
        private readonly int $generationTtl,
        private readonly int $lockSeconds,
        private readonly int $lockWaitMs,
        private readonly array $ttl,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function remember(string $group, string $logicalKey, Closure $resolver): mixed
    {
        $this->ttlFor($group);
        if (! $this->enabled || ($generation = $this->generation($group)) === null) {
            return $resolver();
        }

        $key = $this->dataKey($group, $generation, $logicalKey);
        $envelope = $this->read($key);
        if ($this->isEnvelope($envelope)) {
            if ($envelope['fresh_until'] > time()) {
                return $envelope['value'];
            }
            $this->deferred->add($key, fn () => $this->refresh($group, $key, $resolver));

            return $envelope['value'];
        }

        return $this->fill($group, $key, $resolver);
    }

    /** @param array<int, string> $logicalKeys @return array<string, mixed> */
    public function readMany(string $group, array $logicalKeys): array
    {
        if (! $this->enabled || ($generation = $this->generation($group)) === null) {
            return [];
        }

        $values = [];
        foreach ($logicalKeys as $logicalKey) {
            $value = $this->read($this->dataKey($group, $generation, $logicalKey));
            if ($value !== null) {
                $values[$logicalKey] = $value;
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    public function writeMany(string $group, array $values, int $ttl): void
    {
        if (! $this->enabled || ($generation = $this->generation($group)) === null) {
            return;
        }

        foreach ($values as $logicalKey => $value) {
            try {
                $item = $this->pool->getItem($this->dataKey($group, $generation, (string) $logicalKey));
                $item->set($value)->expiresAfter($ttl);
                $this->pool->save($item);
            } catch (Throwable $exception) {
                $this->logFailure($exception);
            }
        }
    }

    /** @param string|array<int, string> $logicalKeys */
    public function forget(string $group, string|array $logicalKeys): void
    {
        if (! $this->enabled || ($generation = $this->generation($group)) === null) {
            return;
        }

        foreach ((array) $logicalKeys as $logicalKey) {
            try {
                $this->pool->deleteItem($this->dataKey($group, $generation, $logicalKey));
            } catch (Throwable $exception) {
                $this->logFailure($exception);
            }
        }
    }

    public function rotate(string ...$groups): void
    {
        if (! $this->enabled) {
            return;
        }

        foreach ($groups as $group) {
            unset($this->generations[$group]);
            try {
                $this->pool->deleteItem($this->generationKey($group));
            } catch (Throwable $exception) {
                $this->logFailure($exception);
            }
        }
    }

    public function flush(): void
    {
        $this->rotate(...self::GROUPS);
    }

    public function generation(string $group): ?string
    {
        if (isset($this->generations[$group])) {
            return $this->generations[$group];
        }

        try {
            $generation = $this->pool->getItem($this->generationKey($group))->get();
            if (is_string($generation) && $generation !== '') {
                return $this->generations[$group] = $generation;
            }

            $lock = $this->locks->createLock($this->generationKey($group).'.lock', $this->lockSeconds);
            if ($this->acquire($lock)) {
                try {
                    $generation = $this->pool->getItem($this->generationKey($group))->get();
                    if (is_string($generation) && $generation !== '') {
                        return $this->generations[$group] = $generation;
                    }
                    $generation = bin2hex(random_bytes(8));
                    $item = $this->pool->getItem($this->generationKey($group));
                    $item->set($generation)->expiresAfter($this->generationTtl);
                    $this->pool->save($item);

                    return $this->generations[$group] = $generation;
                } finally {
                    $this->release($lock);
                }
            }
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }

        return null;
    }

    private function fill(string $group, string $key, Closure $resolver): mixed
    {
        $acquired = ($lock = $this->newLock($key)) === null ? null : $this->acquire($lock);
        if ($acquired === null) {
            $value = $resolver();
            $this->saveEnvelope($group, $key, $value);

            return $value;
        }
        if ($acquired) {
            try {
                $value = $resolver();
                $this->saveEnvelope($group, $key, $value);

                return $value;
            } finally {
                $this->release($lock);
            }
        }

        $deadline = microtime(true) + $this->lockWaitMs / 1000;
        while (microtime(true) < $deadline) {
            $envelope = $this->read($key);
            if ($this->isEnvelope($envelope)) {
                return $envelope['value'];
            }
            usleep(25000);
        }

        $value = $resolver();
        $this->saveEnvelope($group, $key, $value);

        return $value;
    }

    private function refresh(string $group, string $key, Closure $resolver): void
    {
        $lock = $this->newLock($key);
        if ($lock === null || $this->acquire($lock) !== true) {
            return;
        }

        try {
            $this->saveEnvelope($group, $key, $resolver());
        } finally {
            $this->release($lock);
        }
    }

    private function acquire(LockInterface $lock): ?bool
    {
        try {
            return $lock->acquire();
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return null;
        }
    }

    private function release(LockInterface $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    private function newLock(string $key): ?LockInterface
    {
        try {
            return $this->locks->createLock($key.'.lock', $this->lockSeconds);
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return null;
        }
    }

    private function read(string $key): mixed
    {
        try {
            $item = $this->pool->getItem($key);

            return $item->isHit() ? $item->get() : null;
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return null;
        }
    }

    private function saveEnvelope(string $group, string $key, mixed $value): void
    {
        try {
            $item = $this->pool->getItem($key);
            $item->set(['value' => $value, 'fresh_until' => time() + $this->ttlFor($group)['fresh']])
                ->expiresAfter($this->ttlFor($group)['total']);
            $this->pool->save($item);
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    private function isEnvelope(mixed $value): bool
    {
        return is_array($value)
            && array_key_exists('value', $value)
            && is_int($value['fresh_until'] ?? null);
    }

    /** @return array{fresh: int, total: int} */
    private function ttlFor(string $group): array
    {
        $ttl = $this->ttl[$group] ?? null;
        if (! is_array($ttl) || ! isset($ttl['fresh'], $ttl['total'])) {
            throw new InvalidArgumentException(sprintf('Unknown storefront cache group "%s".', $group));
        }

        return $ttl;
    }

    private function generationKey(string $group): string
    {
        return "{$this->prefix}.gen.{$group}";
    }

    private function dataKey(string $group, string $generation, string $logicalKey): string
    {
        return "{$this->prefix}.{$group}.{$generation}.".hash('sha256', $logicalKey);
    }

    private function logFailure(Throwable $exception): void
    {
        if ($this->failureLogged) {
            return;
        }
        $this->failureLogged = true;
        $this->logger->warning('Storefront cache store failure.', ['exception' => $exception]);
    }
}
