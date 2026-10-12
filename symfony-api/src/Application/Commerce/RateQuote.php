<?php

namespace App\Application\Commerce;

use DateTimeImmutable;

final readonly class RateQuote
{
    public function __construct(
        public float $rate,
        public string $source,
        public DateTimeImmutable $quotedAt,
    ) {
    }
}
