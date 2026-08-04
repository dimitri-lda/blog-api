<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_variants')]
class ProductVariant
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'variants'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;
    #[ORM\Column(length: 120)]
    private string $name;
    #[ORM\Column(length: 120, unique: true)]
    private string $sku;
    #[ORM\Column(nullable: true)]
    private ?int $priceCents;
    #[ORM\Column]
    private int $stock;

    public function __construct(Product $product, string $name, string $sku, ?int $priceCents, int $stock)
    {
        $this->product = $product;
        $this->name = $name;
        $this->sku = $sku;
        $this->priceCents = $priceCents;
        $this->stock = $stock;
        $product->addVariant($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getPriceCents(): ?int
    {
        return $this->priceCents;
    }

    public function getStock(): int
    {
        return $this->stock;
    }
}
