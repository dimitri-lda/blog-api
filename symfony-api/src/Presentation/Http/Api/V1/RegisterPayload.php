<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 255)]
        public string $email,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 4096)]
        public string $password,
        #[Assert\NotBlank]
        public string $password_confirmation,
        #[Assert\IsTrue]
        public bool $accepted_terms,
    ) {
    }
}
