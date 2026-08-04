<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;

final class ExchangeRateService
{
    public function rate(string $currency): array
    {
        if ($currency === 'EUR') {
            return ['rate' => 1.0, 'source' => 'base', 'quoted_at' => now()];
        }
        $stored = ExchangeRate::where('currency', $currency)->latest('quoted_at')->first();
        if ($stored && $stored->quoted_at->gt(now()->subHours(6))) {
            return $this->array($stored);
        }

        foreach ($this->sourcesFor($currency) as [$source, $resolver]) {
            try {
                $rate = $resolver();
                if ($rate > 0) {
                    $record = ExchangeRate::updateOrCreate(['currency' => $currency], ['rate' => $rate, 'source' => $source, 'quoted_at' => now()]);

                    return $this->array($record);
                }
            } catch (\Throwable) { /* try the next source */
            }
        }
        if ($stored) {
            return $this->array($stored);
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
        $url = env('MYFIN_EUR_BYN_URL', 'https://myfin.by/converter/eur-byn');
        $body = Http::timeout(4)->get($url)->body();
        if (! preg_match('/1\s*EUR.{0,120}?([0-9]+[,.][0-9]+)/isu', $body, $match)) {
            throw new \RuntimeException('Myfin rate not found.');
        }

        return (float) str_replace(',', '.', $match[1]);
    }

    private function walutomatPln(): float
    {
        $url = env('WALUTOMAT_EUR_PLN_URL');
        $token = env('WALUTOMAT_API_TOKEN');
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
