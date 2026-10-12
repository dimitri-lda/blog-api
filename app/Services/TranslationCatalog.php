<?php

namespace App\Services;

use App\Services\Cache\StorefrontCache;
use Symfony\Component\Yaml\Yaml;

final class TranslationCatalog
{
    private const LOCALES = ['en', 'ru', 'pl'];

    private const LEGACY_ALIASES = [
        'preferences_updated' => 'preferences.updated',
        'market' => 'preferences.market',
        'language' => 'preferences.language',
        'save_preferences' => 'preferences.save',
        'eu_global' => 'preferences.eu_global',
        'shop_all' => 'navigation.shop_all',
        'running' => 'navigation.running',
        'fitness' => 'navigation.fitness',
        'racket_sports' => 'navigation.racket_sports',
        'outdoor' => 'navigation.outdoor',
        'search_products' => 'navigation.search_products',
        'account' => 'navigation.account',
        'bag' => 'navigation.bag',
        'signed_in_as' => 'account.signed_in_as',
        'my_account' => 'account.my_account',
        'profile_settings' => 'account.profile_settings',
        'log_out' => 'account.log_out',
        'have_account' => 'account.have_account',
        'log_in' => 'account.log_in',
        'create_account' => 'account.create_account',
        'checkout' => 'checkout.title',
        'place_order' => 'checkout.place_order',
        'continue_shopping' => 'checkout.continue_shopping',
        'ready_checkout' => 'checkout.ready',
        'view_bag' => 'checkout.view_bag',
        'delivery' => 'checkout.delivery',
        'total' => 'checkout.total',
        'subtotal' => 'checkout.subtotal',
        'net' => 'checkout.net',
        'vat' => 'checkout.vat',
        'including_vat' => 'checkout.including_vat',
        'free' => 'checkout.free',
        'exchange_rate_unavailable' => 'checkout.exchange_rate_unavailable',
        'free_shipping_banner' => 'footer.free_shipping_banner',
        'delivery_returns' => 'footer.delivery_returns',
        'contact_us' => 'footer.contact_us',
        'size_guide' => 'footer.size_guide',
        'newsletter' => 'footer.newsletter',
        'newsletter_copy' => 'footer.newsletter_copy',
        'email_address' => 'footer.email_address',
        'footer_copy' => 'footer.copy',
        'catalog_eyebrow' => 'catalog.eyebrow',
        'catalog_title' => 'catalog.title',
        'catalog_count' => 'catalog.count',
        'catalog_search' => 'catalog.search',
        'catalog_latest' => 'catalog.latest',
        'catalog_price_asc' => 'catalog.price_asc',
        'catalog_price_desc' => 'catalog.price_desc',
        'catalog_go' => 'catalog.go',
        'catalog_all' => 'catalog.all',
        'catalog_featured' => 'catalog.featured',
    ];

    /** @var array<string, mixed>|null */
    private ?array $translations = null;

    /** @var array<int, string>|null */
    private ?array $leafPaths = null;

    /**
     * Whether the translations payload was injected from outside (tests do
     * this through reflection). Decided once on the first forLocale() call:
     * injected catalogs must never touch the shared cache, self-loaded ones
     * always use it.
     */
    private ?bool $injectedTranslations = null;

    /** @var array<string, array<string, string>> */
    private array $catalogs = [];

    /** @return array<string, string> */
    public function forLocale(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        if (isset($this->catalogs[$locale])) {
            return $this->catalogs[$locale];
        }
        $this->injectedTranslations ??= $this->translations !== null;
        if ($this->injectedTranslations) {
            return $this->catalogs[$locale] = $this->build($locale);
        }

        return $this->catalogs[$locale] = app(StorefrontCache::class)->rememberValue(
            'tr:'.$locale.':'.self::translationsVersion(),
            (int) config('storefront.cache.ttl.translations', 3600),
            fn () => $this->build($locale),
        );
    }

    /**
     * Version of the translations payload used in shared cache keys, derived
     * from file metadata only (the file itself is not parsed).
     */
    public static function translationsVersion(): string
    {
        $path = lang_path('store.yaml');
        if (! is_file($path)) {
            return 'none';
        }

        return md5(filemtime($path).':'.filesize($path));
    }

    /** @return array<string, string> */
    private function build(string $locale): array
    {
        $flattened = $this->flatten($this->translations(), '', $locale);
        $counts = array_count_values(array_map($this->basename(...), $this->leafPaths()));
        foreach ($this->leafPaths() as $path) {
            $basename = $this->basename($path);
            if ($counts[$basename] === 1 && isset($flattened[$path])) {
                $flattened[$basename] ??= $flattened[$path];
            }
        }
        foreach (self::LEGACY_ALIASES as $legacyKey => $path) {
            if (isset($flattened[$path])) {
                $flattened[$legacyKey] = $flattened[$path];
            }
        }

        return $flattened;
    }

    /** @param array<string, string|int|float> $replacements */
    public function get(string $key, array $replacements = [], ?string $locale = null): string
    {
        $text = $this->find($key, $locale) ?? $key;

        foreach ($replacements as $name => $value) {
            $text = str_replace(":{$name}", (string) $value, $text);
        }

        return $text;
    }

    public function find(string $key, ?string $locale = null): ?string
    {
        return $this->forLocale($locale)[$key] ?? $this->forLocale('en')[$key] ?? null;
    }

    /** @return array<string, mixed> */
    private function translations(): array
    {
        return $this->translations ??= Yaml::parseFile(lang_path('store.yaml')) ?? [];
    }

    /** @return array<int, string> */
    private function leafPaths(): array
    {
        return $this->leafPaths ??= $this->collectLeafPaths($this->translations());
    }

    /** @param array<string, mixed> $values @return array<int, string> */
    private function collectLeafPaths(array $values, string $prefix = ''): array
    {
        $paths = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value) && ! $this->isLeaf($value)) {
                $paths = array_merge($paths, $this->collectLeafPaths($value, $path));

                continue;
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /** @param array<string, mixed> $value */
    private function isLeaf(array $value): bool
    {
        return array_intersect(self::LOCALES, array_keys($value)) !== [];
    }

    private function basename(string $path): string
    {
        $position = strrpos($path, '.');

        return $position === false ? $path : substr($path, $position + 1);
    }

    /** @param array<string, mixed> $values @return array<string, string> */
    private function flatten(array $values, string $prefix = '', string $locale = 'en'): array
    {
        $flattened = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value) && ! $this->isLeaf($value)) {
                $flattened += $this->flatten($value, $path, $locale);

                continue;
            }
            $flattened[$path] = $this->translate($value, $locale, $path);
        }

        return $flattened;
    }

    private function translate(array|string|int|float $value, string $locale, string $path): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }

        return (string) ($value[$locale]
            ?? $value['en']
            ?? collect($value)->first(fn ($text) => is_scalar($text))
            ?? $path);
    }
}
