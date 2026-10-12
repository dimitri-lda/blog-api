<?php

namespace App\Application\Orders;

final readonly class PlacedOrder
{
    public function __construct(
        public int $id,
        public string $number,
        public array $affectedProducts = [],
    ) {
    }
}
