<?php

namespace App\Services\ShopPay;

final class ShopPayConfiguration
{
    private const REQUIRED = [
        'shop_domain', 'shop_id', 'client_id',
        'storefront_private_token', 'admin_access_token', 'app_client_secret',
    ];

    public function enabled(): bool
    {
        if (! (bool) config('services.shop_pay.enabled')) {
            return false;
        }
        foreach (self::REQUIRED as $key) {
            if ($this->get($key) === null) {
                return false;
            }
        }
        $shopId = $this->get('shop_id');

        return $shopId !== null && ctype_digit($shopId) && (int) $shopId > 0;
    }

    public function publicConfig(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return [
            'shopId' => (int) $this->get('shop_id'),
            'clientId' => (string) $this->get('client_id'),
        ];
    }

    public function get(string $key): ?string
    {
        $value = config("services.shop_pay.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
