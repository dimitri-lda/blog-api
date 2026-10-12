<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class DeleteProfilePayload
{
    public function __construct(
        #[Assert\NotBlank]
        public string $password,
    ) {
    }
}
