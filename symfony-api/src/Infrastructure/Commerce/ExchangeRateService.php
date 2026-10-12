<?php

namespace App\Infrastructure\Commerce;

use App\Application\Commerce\ExchangeRateFetcher;
use App\Application\Commerce\ExchangeRateProvider;
use App\Application\Commerce\ExchangeRateStore;
use App\Application\Commerce\RateQuote;
use DateInterval;
use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Throwable;

final class ExchangeRateService implements ExchangeRateProvider
{
    private array $resolved = [];

    public function __construct(
        private readonly ExchangeRateStore $store,
        private readonly ExchangeRateFetcher $fetcher,
        #[Target('cache.rate')] private readonly CacheItemPoolInterface $cache,
        private readonly ClockInterface $clock,
        private readonly int $ttl,
    ) {
    }

    public function rate(string $currency): RateQuote
    {
        return $this->resolved[$currency] ??= $this->resolveShared($currency);
    }

    public function refresh(string $currency): RateQuote
    {
        if ($currency === 'EUR') {
            return $this->resolved[$currency] = $this->baseRate();
        }

        $quote = $this->fetcher->fetch($currency);
        $this->store->save($currency, $quote);
        $this->deleteCache($currency);

        return $this->resolved[$currency] = $quote;
    }

    private function resolveShared(string $currency): RateQuote
    {
        if ($currency === 'EUR') {
            return $this->baseRate();
        }

        $cached = $this->readCache($currency);
        if ($cached !== null && $this->isFresh($cached)) {
            return $cached;
        }

        $quote = $this->resolve($currency);
        if ($this->isFresh($quote)) {
            $this->writeCache($currency, $quote);
        }

        return $quote;
    }

    private function resolve(string $currency): RateQuote
    {
        $stored = $this->store->find($currency);
        if ($stored !== null && $this->isFresh($stored)) {
            return $stored;
        }

        try {
            $quote = $this->fetcher->fetch($currency);
            $this->store->save($currency, $quote);

            return $quote;
        } catch (Throwable $exception) {
            if ($stored !== null) {
                return $stored;
            }

            throw $exception;
        }
    }

    private function readCache(string $currency): ?RateQuote
    {
        try {
            $item = $this->cache->getItem($this->cacheKey($currency));
            $payload = $item->isHit() ? $item->get() : null;
        } catch (Throwable) {
            return null;
        }
        if (
            !is_array($payload)
            || !isset($payload['rate'], $payload['source'], $payload['quoted_at'])
        ) {
            return null;
        }

        return new RateQuote(
            (float) $payload['rate'],
            (string) $payload['source'],
            new DateTimeImmutable((string) $payload['quoted_at']),
        );
    }

    private function writeCache(string $currency, RateQuote $quote): void
    {
        $freshUntil = $quote->quotedAt->add(new DateInterval('PT6H'))->getTimestamp();
        $ttl = min($this->ttl, max(0, $freshUntil - $this->clock->now()->getTimestamp()));
        if ($ttl < 1) {
            return;
        }

        try {
            $item = $this->cache->getItem($this->cacheKey($currency));
            $item->set([
                'rate' => $quote->rate,
                'source' => $quote->source,
                'quoted_at' => $quote->quotedAt->format(DATE_ATOM),
            ]);
            $item->expiresAfter($ttl);
            $this->cache->save($item);
        } catch (Throwable) {
        }
    }

    private function deleteCache(string $currency): void
    {
        try {
            $this->cache->deleteItem($this->cacheKey($currency));
        } catch (Throwable) {
        }
    }

    private function isFresh(RateQuote $quote): bool
    {
        return $quote->quotedAt > $this->clock->now()->sub(new DateInterval('PT6H'));
    }

    private function baseRate(): RateQuote
    {
        return new RateQuote(1.0, 'base', $this->clock->now());
    }

    private function cacheKey(string $currency): string
    {
        return 'rate.'.strtoupper($currency);
    }
}
