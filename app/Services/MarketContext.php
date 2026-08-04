<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class MarketContext
{
    public function forRequest(Request $request): array
    {
        $selected = $request->session()->get('market');
        if (is_array($selected) && isset($selected['country'], $selected['locale'])) {
            return $this->make($selected['country'], $selected['locale']);
        }

        $country = $this->countryFromIp($request) ?? config('commerce.default_country');
        $context = $this->make($country);
        $request->session()->put('market', ['country' => $context['country'], 'locale' => $context['locale']]);

        return $context;
    }

    public function set(Request $request, string $country, ?string $locale = null): array
    {
        $context = $this->make($country, $locale);
        $request->session()->put('market', ['country' => $context['country'], 'locale' => $context['locale']]);

        return $context;
    }

    public function make(string $country, ?string $locale = null): array
    {
        $country = strtoupper($country);
        $markets = config('commerce.markets');
        $euVat = config('commerce.eu_vat');
        if (! isset($markets[$country]) && ! isset($euVat[$country])) {
            $country = config('commerce.default_country');
        }
        $market = $markets[$country] ?? ['code' => 'eu_global', 'currency' => 'EUR', 'locale' => 'en', 'vat' => $euVat[$country]];
        $locale = in_array($locale, ['en', 'ru', 'pl'], true) ? $locale : $market['locale'];

        return ['country' => $country, 'market' => $market['code'], 'currency' => $market['currency'], 'locale' => $locale, 'vat_rate' => $market['vat']];
    }

    private function countryFromIp(Request $request): ?string
    {
        $ip = $request->ip();
        if (! $ip || in_array($ip, ['127.0.0.1', '::1'], true)) {
            return null;
        }

        return Cache::remember('geoip:'.hash('sha256', $ip), now()->addDays(30), function () use ($ip) {
            try {
                $url = str_replace('{ip}', rawurlencode($ip), config('commerce.geoip_url'));
                $data = Http::timeout(2)->get($url)->json();

                return ($data['success'] ?? false) ? strtoupper((string) ($data['country_code'] ?? '')) : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }
}
