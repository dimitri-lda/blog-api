<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateProfilePayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 255)]
        public string $email,
    ) {
    }
}
