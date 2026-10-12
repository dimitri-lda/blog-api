<?php

namespace App\Domain\Orders\ValueObjects;

final readonly class ShippingAddress
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $line1,
        public ?string $line2,
        public string $city,
        public string $postalCode,
        public string $country,
    ) {}
}
