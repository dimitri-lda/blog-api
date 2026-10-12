<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ShopPaySubmitPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $session_token,
        #[Assert\NotBlank]
        #[Assert\Length(max: 10000)]
        public string $payment_method,
    ) {
    }
}
