<?php

namespace App\Tests\Unit\Infrastructure\Commerce;

use App\Application\Commerce\ExchangeRateFetcher;
use App\Application\Commerce\ExchangeRateStore;
use App\Application\Commerce\ExchangeRateUnavailable;
use App\Application\Commerce\RateQuote;
use App\Infrastructure\Commerce\ExchangeRateService;
use DateInterval;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class ExchangeRateServiceTest extends TestCase
{
    #[Test]
    public function fresh_stored_rates_are_shared_between_service_instances(): void
    {
        $clock = new MockClock('2026-01-01 12:00:00 UTC');
        $stored = new RateQuote(4.25, 'stored', $clock->now()->sub(new DateInterval('PT1H')));
        $store = new InMemoryExchangeRateStore(['PLN' => $stored]);
        $fetcher = new FakeExchangeRateFetcher();
        $cache = new ArrayAdapter();

        $first = new ExchangeRateService($store, $fetcher, $cache, $clock, 300);
        self::assertSame(4.25, $first->rate('PLN')->rate);
        self::assertSame(1, $store->findCalls);

        $second = new ExchangeRateService($store, $fetcher, $cache, $clock, 300);
        self::assertSame(4.25, $second->rate('PLN')->rate);
        self::assertSame(1, $store->findCalls);
        self::assertSame(0, $fetcher->calls);
    }

    #[Test]
    public function stale_stored_rate_is_returned_only_when_every_source_fails(): void
    {
        $clock = new MockClock('2026-01-01 12:00:00 UTC');
        $stale = new RateQuote(3.5, 'stale', $clock->now()->sub(new DateInterval('PT7H')));
        $store = new InMemoryExchangeRateStore(['BYN' => $stale]);
        $fetcher = new FakeExchangeRateFetcher(exception: new ExchangeRateUnavailable('unavailable'));
        $cache = new ArrayAdapter();
        $service = new ExchangeRateService($store, $fetcher, $cache, $clock, 300);

        self::assertSame($stale, $service->rate('BYN'));
        self::assertFalse($cache->hasItem('rate.BYN'));
        self::assertSame(1, $fetcher->calls);
    }

    #[Test]
    public function failed_resolution_without_a_stored_rate_is_not_cached(): void
    {
        $clock = new MockClock('2026-01-01 12:00:00 UTC');
        $store = new InMemoryExchangeRateStore();
        $fetcher = new FakeExchangeRateFetcher(exception: new ExchangeRateUnavailable('unavailable'));
        $cache = new ArrayAdapter();
        $service = new ExchangeRateService($store, $fetcher, $cache, $clock, 300);

        $this->expectException(ExchangeRateUnavailable::class);

        try {
            $service->rate('BYN');
        } finally {
            self::assertFalse($cache->hasItem('rate.BYN'));
            self::assertSame(1, $fetcher->calls);
        }
    }

    #[Test]
    public function refresh_bypasses_stored_and_cached_values_and_persists_the_new_quote(): void
    {
        $clock = new MockClock('2026-01-01 12:00:00 UTC');
        $old = new RateQuote(4.0, 'old', $clock->now());
        $new = new RateQuote(4.5, 'new', $clock->now());
        $store = new InMemoryExchangeRateStore(['PLN' => $old]);
        $fetcher = new FakeExchangeRateFetcher($new);
        $cache = new ArrayAdapter();
        $item = $cache->getItem('rate.PLN');
        $item->set([
            'rate' => 4.0,
            'source' => 'old',
            'quoted_at' => $clock->now()->format(DATE_ATOM),
        ]);
        $cache->save($item);
        $service = new ExchangeRateService($store, $fetcher, $cache, $clock, 300);

        self::assertSame($new, $service->refresh('PLN'));
        self::assertSame($new, $store->quotes['PLN']);
        self::assertSame(1, $store->saveCalls);
        self::assertSame(1, $fetcher->calls);
        self::assertFalse($cache->hasItem('rate.PLN'));
        self::assertSame($new, $service->rate('PLN'));
    }

    #[Test]
    public function eur_is_a_request_memoized_base_rate_without_storage_or_fetching(): void
    {
        $clock = new MockClock('2026-01-01 12:00:00 UTC');
        $store = new InMemoryExchangeRateStore();
        $fetcher = new FakeExchangeRateFetcher();
        $service = new ExchangeRateService($store, $fetcher, new ArrayAdapter(), $clock, 300);

        $first = $service->rate('EUR');
        $second = $service->rate('EUR');

        self::assertSame($first, $second);
        self::assertSame(1.0, $first->rate);
        self::assertSame('base', $first->source);
        self::assertSame(0, $store->findCalls);
        self::assertSame(0, $fetcher->calls);
    }
}

final class InMemoryExchangeRateStore implements ExchangeRateStore
{
    public int $findCalls = 0;

    public int $saveCalls = 0;

    public function __construct(public array $quotes = [])
    {
    }

    public function find(string $currency): ?RateQuote
    {
        ++$this->findCalls;

        return $this->quotes[$currency] ?? null;
    }

    public function save(string $currency, RateQuote $quote): void
    {
        ++$this->saveCalls;
        $this->quotes[$currency] = $quote;
    }
}

final class FakeExchangeRateFetcher implements ExchangeRateFetcher
{
    public int $calls = 0;

    public function __construct(
        private readonly ?RateQuote $quote = null,
        private readonly ?ExchangeRateUnavailable $exception = null,
    ) {
    }

    public function fetch(string $currency): RateQuote
    {
        ++$this->calls;
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->quote ?? throw new ExchangeRateUnavailable("No {$currency} fixture.");
    }
}
