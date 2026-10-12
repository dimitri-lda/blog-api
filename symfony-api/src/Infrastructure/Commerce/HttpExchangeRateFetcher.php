<?php

namespace App\Infrastructure\Commerce;

use App\Application\Commerce\ExchangeRateFetcher;
use App\Application\Commerce\ExchangeRateUnavailable;
use App\Application\Commerce\RateQuote;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class HttpExchangeRateFetcher implements ExchangeRateFetcher
{
    public function __construct(
        private HttpClientInterface $http,
        private ClockInterface $clock,
        private string $myfinUrl,
        private ?string $walutomatUrl,
        private ?string $walutomatToken,
    ) {
    }

    public function fetch(string $currency): RateQuote
    {
        $sources = match ($currency) {
            'BYN' => ['myfin' => $this->myfin(...), 'nbrb' => $this->nbrb(...)],
            'PLN' => ['walutomat' => $this->walutomat(...), 'nbp' => $this->nbp(...)],
            default => [],
        };

        foreach ($sources as $name => $source) {
            try {
                $rate = $source();
                if ($rate > 0) {
                    return new RateQuote($rate, $name, $this->clock->now());
                }
            } catch (Throwable) {
            }
        }

        throw new ExchangeRateUnavailable('A current exchange rate is unavailable.');
    }

    private function myfin(): float
    {
        $body = $this->http->request('GET', $this->myfinUrl, ['timeout' => 4])->getContent();
        if (!preg_match('/1\s*EUR.{0,120}?([0-9]+[,.][0-9]+)/isu', $body, $match)) {
            throw new ExchangeRateUnavailable('Myfin rate not found.');
        }

        return (float) str_replace(',', '.', $match[1]);
    }

    private function walutomat(): float
    {
        if ($this->walutomatUrl === null || $this->walutomatToken === null) {
            throw new ExchangeRateUnavailable('Walutomat credentials are not configured.');
        }
        $data = $this->http->request('GET', $this->walutomatUrl, [
            'timeout' => 4,
            'auth_bearer' => $this->walutomatToken,
        ])->toArray();
        $value = $data['rate'] ?? $data['price'] ?? null;
        if (!is_numeric($value)) {
            throw new ExchangeRateUnavailable('Walutomat rate not found.');
        }

        return (float) $value;
    }

    private function nbrb(): float
    {
        $data = $this->http->request(
            'GET',
            'https://api.nbrb.by/exrates/rates/EUR?parammode=2',
            ['timeout' => 4],
        )->toArray();
        $rate = $data['Cur_OfficialRate'] ?? null;
        if (!is_numeric($rate)) {
            throw new ExchangeRateUnavailable('NBRB rate not found.');
        }

        return (float) $rate;
    }

    private function nbp(): float
    {
        $data = $this->http->request(
            'GET',
            'https://api.nbp.pl/api/exchangerates/rates/a/eur/?format=json',
            ['timeout' => 4],
        )->toArray();
        $rate = $data['rates'][0]['mid'] ?? null;
        if (!is_numeric($rate)) {
            throw new ExchangeRateUnavailable('NBP EUR rate not found.');
        }

        return (float) $rate;
    }
}
