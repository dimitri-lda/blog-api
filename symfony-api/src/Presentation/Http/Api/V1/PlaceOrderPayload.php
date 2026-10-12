<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PlaceOrderPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
        #[Assert\NotBlank]
        #[Assert\Length(max: 40)]
        public string $phone,
        #[Assert\NotBlank]
        #[Assert\Length(max: 80)]
        public string $first_name,
        #[Assert\NotBlank]
        #[Assert\Length(max: 80)]
        public string $last_name,
        #[Assert\NotBlank]
        #[Assert\Length(max: 180)]
        public string $line1,
        #[Assert\NotBlank]
        #[Assert\Length(max: 80)]
        public string $city,
        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        public string $postal_code,
        #[Assert\NotBlank]
        #[Assert\Length(min: 2, max: 2)]
        public string $country,
        #[Assert\Choice(choices: ['standard', 'express'])]
        public string $delivery_method,
        #[Assert\Length(max: 180)]
        public ?string $line2 = null,
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
