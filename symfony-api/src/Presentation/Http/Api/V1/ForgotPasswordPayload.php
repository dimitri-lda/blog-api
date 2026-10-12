<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ForgotPasswordPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
    ) {
    }
}
