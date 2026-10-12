<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ShopPayQuotePayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $session_token,
        #[Assert\NotBlank]
        public array $shipping_address,
        #[Assert\Choice(choices: ['standard', 'express'])]
        public string $delivery_method,
    ) {
    }
}
