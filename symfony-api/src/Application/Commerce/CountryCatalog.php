<?php

namespace App\Application\Commerce;

final readonly class CountryCatalog
{
    public function __construct(private array $countries)
    {
    }

    public function all(): array
    {
        return $this->countries;
    }

    public function supports(string $country): bool
    {
        return isset($this->countries[strtoupper($country)]);
    }
}
