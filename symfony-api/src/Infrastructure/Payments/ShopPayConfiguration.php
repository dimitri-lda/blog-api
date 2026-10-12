<?php

namespace App\Infrastructure\Payments;

final readonly class ShopPayConfiguration
{
    public function __construct(
        private bool $configured,
        private ?string $shopDomain,
        private string $apiVersion,
        private ?string $shopId,
        private ?string $clientId,
        private ?string $storefrontPrivateToken,
        private ?string $adminAccessToken,
        private ?string $appClientSecret,
    ) {
    }

    public function enabled(): bool
    {
        return $this->configured
            && count($this->required()) === 6
            && $this->shopId !== null
            && ctype_digit($this->shopId)
            && (int) $this->shopId > 0;
    }

    public function publicConfig(): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        return [
            'shopId' => (int) $this->shopId,
            'clientId' => $this->clientId,
        ];
    }

    public function shopDomain(): string
    {
        return $this->value($this->shopDomain);
    }

    public function apiVersion(): string
    {
        return $this->apiVersion;
    }

    public function storefrontPrivateToken(): string
    {
        return $this->value($this->storefrontPrivateToken);
    }

    public function adminAccessToken(): string
    {
        return $this->value($this->adminAccessToken);
    }

    public function appClientSecret(): string
    {
        return $this->value($this->appClientSecret);
    }

    public function paymentHashKey(): string
    {
        return $this->appClientSecret();
    }

    private function required(): array
    {
        return array_filter([
            $this->shopDomain,
            $this->shopId,
            $this->clientId,
            $this->storefrontPrivateToken,
            $this->adminAccessToken,
            $this->appClientSecret,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    private function value(?string $value): string
    {
        if ($value === null || $value === '') {
            throw new \DomainException('Shop Pay is not configured.');
        }

        return $value;
    }
}
