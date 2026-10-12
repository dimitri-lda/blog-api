<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Services\Cache\StorefrontCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

final class ExchangeRateService
{
    /** @var array<string, array{rate: float, source: string, quoted_at: mixed}> */
    private array $resolved = [];

    /**
     * Set when this request fetched a quote from the external sources and
     * wrote it to exchange_rates. That write already rotated the 'rate'
     * generation, so the shared cache must not be written in this request —
     * the next one will cache the fresh stored row under the new generation.
     */
    private bool $fetchedFromSources = false;

    public function __construct(private readonly StorefrontCache $cache) {}

    public function rate(string $currency): array
    {
        return $this->resolved[$currency] ??= $this->resolveShared($currency);
    }

    /**
     * Force-refresh from the external sources: bypasses the memo and the
     * shared cache and updates the stored record. The resulting write to
     * exchange_rates rotates the 'rate' generation, so the previous shared
     * entries die on their own. When every source fails it throws and
     * leaves the cache untouched.
     */
    public function refresh(string $currency): array
    {
        if ($currency === 'EUR') {
            return $this->resolved[$currency] = $this->resolve($currency);
        }

        return $this->resolved[$currency] = $this->fetchFromSources($currency);
    }

    private function resolveShared(string $currency): array
    {
        if ($currency === 'EUR' || ! $this->cache->enabled()) {
            return $this->resolve($currency);
        }
        $generation = $this->cache->generation('rate');
        if ($generation === null) {
            return $this->resolve($currency);
        }
        $key = "rate:{$generation}:{$currency}";
        $payload = $this->cache->readValue($key);
        if (is_array($payload) && isset($payload['rate'], $payload['source'], $payload['quoted_at'])) {
            $quotedAt = Carbon::parse($payload['quoted_at']);
            if ($quotedAt->gt(now()->subHours(6))) {
                return [
                    'rate' => (float) $payload['rate'],
                    'source' => $payload['source'],
                    'quoted_at' => $quotedAt,
                ];
            }
            // The entry outlived its quote freshness: drop it and resolve live.
            $this->cache->forgetValue($key);
        }

        $resolved = $this->resolve($currency);
        // Only fresh DB-sourced quotes are shared: a fetched rate already
        // rotated the generation, and a stale fallback or a failed
        // resolution must never be cached.
        if (! $this->fetchedFromSources && $resolved['quoted_at']->gt(now()->subHours(6))) {
            $freshSeconds = max(0, $resolved['quoted_at']->copy()->addHours(6)->getTimestamp() - time());
            if ($freshSeconds > 0) {
                $this->cache->putValue($key, $this->payload($resolved), min($this->exchangeRateTtl(), $freshSeconds));
            }
        }

        return $resolved;
    }

    private function exchangeRateTtl(): int
    {
        return (int) config('storefront.cache.ttl.exchange_rate', 300);
    }

    /** @return array{rate: float, source: string, quoted_at: string} */
    private function payload(array $resolved): array
    {
        return [
            'rate' => $resolved['rate'],
            'source' => $resolved['source'],
            'quoted_at' => $resolved['quoted_at']->toIso8601String(),
        ];
    }

    private function resolve(string $currency): array
    {
        if ($currency === 'EUR') {
            return ['rate' => 1.0, 'source' => 'base', 'quoted_at' => now()];
        }
        $stored = ExchangeRate::where('currency', $currency)->latest('quoted_at')->first();
        if ($stored && $stored->quoted_at->gt(now()->subHours(6))) {
            return $this->array($stored);
        }

        try {
            return $this->fetchFromSources($currency);
        } catch (\DomainException $exception) {
            if ($stored) {
                return $this->array($stored);
            }
            throw $exception;
        }
    }

    private function fetchFromSources(string $currency): array
    {
        foreach ($this->sourcesFor($currency) as [$source, $resolver]) {
            try {
                $rate = $resolver();
                if ($rate > 0) {
                    $record = ExchangeRate::updateOrCreate(
                        ['currency' => $currency],
                        ['rate' => $rate, 'source' => $source, 'quoted_at' => now()],
                    );
                    $this->fetchedFromSources = true;

                    return $this->array($record);
                }
            } catch (\Throwable) { /* try the next source */
            }
        }
        throw new \DomainException(app(TranslationCatalog::class)->get('exchange_rate_unavailable'));
    }

    /** @return array<int, array{0:string,1:callable}> */
    private function sourcesFor(string $currency): array
    {
        return match ($currency) {
            'BYN' => [['myfin', fn () => $this->myfinByn()], ['nbrb', fn () => $this->nbrbByn()]],
            'PLN' => [['walutomat', fn () => $this->walutomatPln()], ['nbp', fn () => $this->nbpPln()]],
            default => [],
        };
    }

    private function myfinByn(): float
    {
        $url = config('commerce.exchange_rates.myfin_eur_byn_url', 'https://myfin.by/converter/eur-byn');
        $body = Http::timeout(4)->get($url)->body();
        if (! preg_match('/1\s*EUR.{0,120}?([0-9]+[,.][0-9]+)/isu', $body, $match)) {
            throw new \RuntimeException('Myfin rate not found.');
        }

        return (float) str_replace(',', '.', $match[1]);
    }

    private function walutomatPln(): float
    {
        $url = config('commerce.exchange_rates.walutomat_eur_pln_url');
        $token = config('commerce.exchange_rates.walutomat_api_token');
        if (! $url || ! $token) {
            throw new \RuntimeException('Walutomat credentials are not configured.');
        }
        $json = Http::timeout(4)->withToken($token)->get($url)->json();
        $value = $json['rate'] ?? $json['price'] ?? null;
        if (! is_numeric($value)) {
            throw new \RuntimeException('Walutomat rate not found.');
        }

        return (float) $value;
    }

    private function nbrbByn(): float
    {
        $json = Http::timeout(4)->get('https://api.nbrb.by/exrates/rates/EUR?parammode=2')->json();
        $rate = $json['Cur_OfficialRate'] ?? null;
        if (! is_numeric($rate)) {
            throw new \RuntimeException('NBRB rate not found.');
        }

        return (float) $rate;
    }

    private function nbpPln(): float
    {
        $eur = Http::timeout(4)->get('https://api.nbp.pl/api/exchangerates/rates/a/eur/?format=json')->json();
        // NBP table A quotes foreign currency in PLN; PLN itself is the base.
        $eurRate = $eur['rates'][0]['mid'] ?? null;
        if (! is_numeric($eurRate)) {
            throw new \RuntimeException('NBP EUR rate not found.');
        }

        return (float) $eurRate;
    }

    private function array(ExchangeRate $rate): array
    {
        return ['rate' => (float) $rate->rate, 'source' => $rate->source, 'quoted_at' => $rate->quoted_at];
    }
}
