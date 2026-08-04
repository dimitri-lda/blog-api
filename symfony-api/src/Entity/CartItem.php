<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cart_items')]
#[ORM\UniqueConstraint(name: 'UNIQ_CART_VARIANT', columns: ['cart_id', 'variant_id'])]
class CartItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'items'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Cart $cart;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ProductVariant $variant;
    #[ORM\Column]
    private int $quantity;

    public function __construct(Cart $cart, ProductVariant $variant, int $quantity = 1)
    {
        $this->cart = $cart;
        $this->variant = $variant;
        $this->quantity = $quantity;
        $cart->addItem($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }
}
