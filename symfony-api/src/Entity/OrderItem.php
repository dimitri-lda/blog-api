<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_items')]
class OrderItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'items'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private StoreOrder $order;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProductVariant $variant;
    #[ORM\Column(length: 160)]
    private string $name;
    #[ORM\Column(length: 120)]
    private string $variantName;
    #[ORM\Column]
    private int $unitPriceCents;
    #[ORM\Column]
    private int $quantity;
    #[ORM\Column]
    private int $lineTotalCents;

    public function __construct(StoreOrder $order, ProductVariant $variant, int $quantity)
    {
        $this->order = $order;
        $this->variant = $variant;
        $this->name = $variant->getProduct()->getName();
        $this->variantName = $variant->getName();
        $this->unitPriceCents = $variant->getPriceCents() ?? $variant->getProduct()->getPriceCents();
        $this->quantity = $quantity;
        $this->lineTotalCents = $this->unitPriceCents * $quantity;
        $order->addItem($this);
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'variant_name' => $this->variantName, 'unit_price_cents' => $this->unitPriceCents, 'quantity' => $this->quantity, 'line_total_cents' => $this->lineTotalCents];
    }
}
