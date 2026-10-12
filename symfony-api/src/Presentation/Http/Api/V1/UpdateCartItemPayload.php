<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateCartItemPayload
{
    public function __construct(
        #[Assert\Range(min: 0, max: 20)]
        public int $quantity,
    ) {
    }
}
