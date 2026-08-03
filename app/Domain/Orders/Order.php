<?php

namespace App\Domain\Orders;

use App\Domain\Orders\Entities\OrderLine;
use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Domain\Orders\ValueObjects\ShippingAddress;

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
    ) {
        if ($lines === []) {
            throw new \DomainException('An order cannot be empty.');
        }
    }

    public function subtotalCents(): int
    {
        return array_sum(array_map(fn (OrderLine $line) => $line->totalCents(), $this->lines));
    }

    public function deliveryCents(): int
    {
        return $this->deliveryMethod->feeFor($this->subtotalCents());
    }

    public function totalCents(): int
    {
        return $this->subtotalCents() + $this->deliveryCents();
    }
}
