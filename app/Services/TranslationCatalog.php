<?php

namespace App\Services;

use Symfony\Component\Yaml\Yaml;

final class TranslationCatalog
{
    /** @return array<string, string> */
    public function forLocale(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $path = lang_path('store.yaml');

        /** @var array<string, mixed> $translations */
        $translations = Yaml::parseFile($path) ?? [];
        $flattened = $this->flatten($translations, '', $locale);
        foreach ([
            'preferences_updated' => 'preferences.updated', 'market' => 'preferences.market', 'language' => 'preferences.language', 'save_preferences' => 'preferences.save', 'eu_global' => 'preferences.eu_global',
            'shop_all' => 'navigation.shop_all', 'running' => 'navigation.running', 'fitness' => 'navigation.fitness', 'racket_sports' => 'navigation.racket_sports', 'outdoor' => 'navigation.outdoor', 'search_products' => 'navigation.search_products', 'account' => 'navigation.account', 'bag' => 'navigation.bag',
            'signed_in_as' => 'account.signed_in_as', 'my_account' => 'account.my_account', 'profile_settings' => 'account.profile_settings', 'log_out' => 'account.log_out', 'have_account' => 'account.have_account', 'log_in' => 'account.log_in', 'create_account' => 'account.create_account',
            'checkout' => 'checkout.title', 'place_order' => 'checkout.place_order', 'continue_shopping' => 'checkout.continue_shopping', 'ready_checkout' => 'checkout.ready', 'view_bag' => 'checkout.view_bag', 'delivery' => 'checkout.delivery', 'total' => 'checkout.total', 'subtotal' => 'checkout.subtotal', 'net' => 'checkout.net', 'vat' => 'checkout.vat', 'including_vat' => 'checkout.including_vat', 'free' => 'checkout.free', 'exchange_rate_unavailable' => 'checkout.exchange_rate_unavailable',
            'free_shipping_banner' => 'footer.free_shipping_banner', 'delivery_returns' => 'footer.delivery_returns', 'contact_us' => 'footer.contact_us', 'size_guide' => 'footer.size_guide', 'newsletter' => 'footer.newsletter', 'newsletter_copy' => 'footer.newsletter_copy', 'email_address' => 'footer.email_address', 'footer_copy' => 'footer.copy',
            'catalog_eyebrow' => 'catalog.eyebrow', 'catalog_title' => 'catalog.title', 'catalog_count' => 'catalog.count', 'catalog_search' => 'catalog.search', 'catalog_latest' => 'catalog.latest', 'catalog_price_asc' => 'catalog.price_asc', 'catalog_price_desc' => 'catalog.price_desc', 'catalog_go' => 'catalog.go', 'catalog_all' => 'catalog.all', 'catalog_featured' => 'catalog.featured',
        ] as $legacyKey => $path) {
            if (isset($flattened[$path])) $flattened[$legacyKey] = $flattened[$path];
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

    /** @param array<string, mixed> $values @return array<string, string> */
    private function flatten(array $values, string $prefix = '', string $locale = 'en'): array
    {
        $flattened = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? $key : "{$prefix}.{$key}";
            if (is_array($value) && ! isset($value['en'], $value['ru'], $value['pl'])) {
                $flattened += $this->flatten($value, $path, $locale);
                continue;
            }
            $text = is_array($value) ? (string) ($value[$locale] ?? $value['en']) : (string) $value;
            $flattened[$path] = $text;
            // Keeps existing UI calls such as t('shop_all') working while
            // translation authors use readable nested YAML sections.
            $flattened[$key] ??= $text;
        }
        return $flattened;
    }
}
