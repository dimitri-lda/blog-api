<?php

namespace App\Domain\Orders\ValueObjects;

enum DeliveryMethod: string
{
    case Standard = 'standard';
    case Express = 'express';

    public function feeFor(int $subtotalCents): int
    {
        return match ($this) {
            self::Express => 1290,
            self::Standard => $subtotalCents >= 5000 ? 0 : 590,
        };
    }
}
