<?php

namespace App\Domain\Orders;

use App\Domain\Orders\Entities\OrderLine;
use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Domain\Orders\ValueObjects\ShippingAddress;
use DomainException;

final readonly class Order
{
    /** @param list<OrderLine> $lines */
    public function __construct(
        public string $number,
        public ?int $customerId,
        public string $email,
        public string $phone,
        public DeliveryMethod $deliveryMethod,
        public ShippingAddress $shippingAddress,
        public array $lines,
        public string $currency = 'EUR',
        public ?string $market = null,
        public ?string $locale = null,
        public ?float $exchangeRate = null,
        public ?int $vatRateBasisPoints = null,
        public ?int $netSubtotalOverride = null,
        public ?int $taxOverride = null,
        public ?int $deliveryNetOverride = null,
        public ?int $deliveryTaxOverride = null,
        public ?int $deliveryOverride = null,
    ) {
        if ($lines === []) {
            throw new DomainException('An order cannot be empty.');
        }
    }

    public function deliveryCents(): int
    {
        if ($this->deliveryOverride !== null) {
            return $this->deliveryOverride;
        }

        return $this->deliveryMethod->feeFor($this->subtotalCents());
    }

    public function subtotalCents(): int
    {
        return array_sum(array_map(fn (OrderLine $line) => $line->totalCents(), $this->lines));
    }

    public function totalCents(): int
    {
        return $this->subtotalCents() + $this->deliveryCents();
    }

    public function netSubtotalCents(): int
    {
        return $this->netSubtotalOverride ?? $this->subtotalCents();
    }

    public function taxCents(): int
    {
        return $this->taxOverride ?? 0;
    }

    public function deliveryNetCents(): int
    {
        return $this->deliveryNetOverride ?? $this->deliveryCents();
    }

    public function deliveryTaxCents(): int
    {
        return $this->deliveryTaxOverride ?? 0;
    }
}
