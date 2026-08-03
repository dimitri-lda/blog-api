<?php

namespace App\Domain\Orders\Entities;

final readonly class OrderLine
{
    public function __construct(
        public int $variantId,
        public string $productName,
        public string $variantName,
        public int $unitPriceCents,
        public int $quantity,
    ) {
        if ($quantity < 1) {
            throw new \DomainException('An order line must contain at least one item.');
        }
    }

    public function totalCents(): int
    {
        return $this->unitPriceCents * $this->quantity;
    }
}
