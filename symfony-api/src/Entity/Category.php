<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'categories')]
class Category
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 120)]
    private string $name;
    #[ORM\Column(length: 120, unique: true)]
    private string $slug;
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $imageUrl;

    public function __construct(string $name, string $slug, ?string $imageUrl = null)
    {
        $this->name = $name;
        $this->slug = $slug;
        $this->imageUrl = $imageUrl;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }
}
