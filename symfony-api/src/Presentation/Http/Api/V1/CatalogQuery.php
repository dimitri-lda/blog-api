<?php

namespace App\Presentation\Http\Api\V1;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CatalogQuery
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $q = null,
        #[Assert\Length(max: 255)]
        public ?string $category = null,
        #[Assert\Choice(choices: ['price_asc', 'price_desc'])]
        public ?string $sort = null,
        #[Assert\Positive]
        public int $page = 1,
    ) {
    }

    public function filters(): array
    {
        return [
            'q' => $this->q,
            'category' => $this->category,
            'sort' => $this->sort,
        ];
    }
}
