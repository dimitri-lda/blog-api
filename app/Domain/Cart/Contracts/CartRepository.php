<?php

namespace App\Domain\Cart\Contracts;

use App\Domain\Orders\Entities\OrderLine;

interface CartRepository
{
    /** @return list<OrderLine> */
    public function orderLinesFor(?int $userId, ?string $token): array;

    public function clear(?int $userId, ?string $token): void;
}
