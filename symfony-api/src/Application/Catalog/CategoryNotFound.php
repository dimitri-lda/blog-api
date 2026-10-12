<?php

namespace App\Application\Catalog;

use RuntimeException;

final class CategoryNotFound extends RuntimeException
{
    public function __construct(string $slug)
    {
        parent::__construct("Category '{$slug}' was not found.");
    }
}
