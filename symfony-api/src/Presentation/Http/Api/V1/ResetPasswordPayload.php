<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ResetPasswordPayload
{
    public function __construct(
        #[Assert\NotBlank]
        public string $token,
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 4096)]
        public string $password,
        #[Assert\NotBlank]
        public string $password_confirmation,
    ) {
    }
}
