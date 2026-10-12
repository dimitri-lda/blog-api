<?php

namespace App\Infrastructure\Http;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class GeoIpResolver
{
    private const MISS = '__geoip_miss__';

    public function __construct(
        private HttpClientInterface $http,
        #[Target('cache.storefront')] private CacheItemPoolInterface $cache,
        private string $url,
        private int $missTtl,
    ) {
    }

    public function country(?string $ip): ?string
    {
        if ($ip === null || in_array($ip, ['127.0.0.1', '::1'], true)) {
            return null;
        }

        $key = 'geoip.'.hash('sha256', $ip);
        $cached = $this->read($key);
        if ($cached !== null) {
            return $cached === self::MISS ? null : $cached;
        }

        $country = $this->resolve($ip);
        $this->write($key, $country ?? self::MISS, $country === null ? $this->missTtl : 30 * 86400);

        return $country;
    }

    private function resolve(string $ip): ?string
    {
        try {
            $data = $this->http->request(
                'GET',
                str_replace('{ip}', rawurlencode($ip), $this->url),
                ['timeout' => 2],
            )->toArray(false);
        } catch (Throwable) {
            return null;
        }

        if (($data['success'] ?? false) !== true) {
            return null;
        }

        $country = strtoupper((string) ($data['country_code'] ?? ''));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }

    private function read(string $key): mixed
    {
        try {
            $item = $this->cache->getItem($key);

            return $item->isHit() ? $item->get() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function write(string $key, string $value, int $ttl): void
    {
        try {
            $item = $this->cache->getItem($key);
            $item->set($value);
            $item->expiresAfter($ttl);
            $this->cache->save($item);
        } catch (Throwable) {
        }
    }
}
