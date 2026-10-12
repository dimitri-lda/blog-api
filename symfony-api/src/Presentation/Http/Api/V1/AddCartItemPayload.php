<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AddCartItemPayload
{
    public function __construct(
        #[Assert\Positive]
        public int $variant_id,
        #[Assert\Range(min: 1, max: 20)]
        public int $quantity = 1,
    ) {
    }
}
