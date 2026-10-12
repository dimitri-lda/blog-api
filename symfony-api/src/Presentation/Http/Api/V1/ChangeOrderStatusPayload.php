<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChangeOrderStatusPayload
{
    public function __construct(
        #[Assert\Choice(choices: [
            'pending_payment',
            'paid',
            'processing',
            'shipped',
            'delivered',
            'cancelled',
            'refunded',
        ])]
        public string $status,
    ) {
    }
}
