<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PreferencePayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 2, max: 2)]
        public string $country,
        #[Assert\Choice(choices: ['en', 'ru', 'pl'])]
        public ?string $locale = null,
    ) {
    }
}
