<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AdminOrderQuery
{
    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $q = null,
        #[Assert\Choice(choices: [
            'pending_payment',
            'paid',
            'processing',
            'shipped',
            'delivered',
            'cancelled',
            'refunded',
        ])]
        public ?string $status = null,
        #[Assert\Positive]
        public int $page = 1,
    ) {
    }
}
