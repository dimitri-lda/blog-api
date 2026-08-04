<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
class Product
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    private Category $category;
    #[ORM\Column(length: 160)]
    private string $name;
    #[ORM\Column(length: 160, unique: true)]
    private string $slug;
    #[ORM\Column(length: 120)]
    private string $brand;
    #[ORM\Column(type: 'text')]
    private string $description;
    #[ORM\Column]
    private int $priceCents;
    #[ORM\Column(nullable: true)]
    private ?int $compareAtPriceCents = null;
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $imageUrl;
    #[ORM\Column(options: ['default' => false])]
    private bool $featured = false;
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;
    #[ORM\Column]
    private DateTimeImmutable $createdAt;
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductVariant::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $variants;
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductImage::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $images;

    public function __construct(Category $category, string $name, string $slug, string $brand, string $description, int $priceCents, ?string $imageUrl = null, bool $featured = false)
    {
        $this->category = $category;
        $this->name = $name;
        $this->slug = $slug;
        $this->brand = $brand;
        $this->description = $description;
        $this->priceCents = $priceCents;
        $this->imageUrl = $imageUrl;
        $this->featured = $featured;
        $this->createdAt = new DateTimeImmutable();
        $this->variants = new ArrayCollection();
        $this->images = new ArrayCollection();
    }

    public function addVariant(ProductVariant $variant): void
    {
        if (!$this->variants->contains($variant)) $this->variants->add($variant);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getBrand(): string
    {
        return $this->brand;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPriceCents(): int
    {
        return $this->priceCents;
    }

    public function getCompareAtPriceCents(): ?int
    {
        return $this->compareAtPriceCents;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function getImages(): Collection
    {
        return $this->images;
    }
}
