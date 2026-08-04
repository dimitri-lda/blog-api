<?php

namespace App\Domain\Orders;

enum DeliveryMethod: string
{
    case Standard = 'standard';
    case Express = 'express';

    public function feeFor(int $subtotal): int
    {
        return match ($this) {
            self::Express => 1290,
            self::Standard => $subtotal >= 5000 ? 0 : 590,
        };
    }
}
