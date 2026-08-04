<?php

namespace App\Entity;

use App\Domain\Orders\DeliveryMethod;
use App\Domain\Orders\OrderStatus;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'store_orders')]
class StoreOrder
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;
    #[ORM\Column(length: 32, unique: true)]
    private string $number;
    #[ORM\Column(length: 255)]
    private string $email;
    #[ORM\Column(length: 40)]
    private string $phone;
    #[ORM\Column(enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::PendingPayment;
    #[ORM\Column(enumType: DeliveryMethod::class)]
    private DeliveryMethod $deliveryMethod;
    #[ORM\Column]
    private int $subtotalCents;
    #[ORM\Column]
    private int $deliveryCents;
    #[ORM\Column]
    private int $totalCents;
    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';
    #[ORM\Column]
    private DateTimeImmutable $createdAt;
    #[ORM\OneToOne(mappedBy: 'order', targetEntity: OrderAddress::class, cascade: ['persist', 'remove'])]
    private ?OrderAddress $address = null;
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderItem::class, cascade: ['persist', 'remove'])]
    private Collection $items;

    public function __construct(?User $user, string $number, string $email, string $phone, DeliveryMethod $method, int $subtotal)
    {
        $this->user = $user;
        $this->number = $number;
        $this->email = $email;
        $this->phone = $phone;
        $this->deliveryMethod = $method;
        $this->subtotalCents = $subtotal;
        $this->deliveryCents = $method->feeFor($subtotal);
        $this->totalCents = $subtotal + $this->deliveryCents;
        $this->createdAt = new DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getDeliveryMethod(): DeliveryMethod
    {
        return $this->deliveryMethod;
    }

    public function getSubtotalCents(): int
    {
        return $this->subtotalCents;
    }

    public function getDeliveryCents(): int
    {
        return $this->deliveryCents;
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAddress(): ?OrderAddress
    {
        return $this->address;
    }

    public function getItems(): Collection
    {
        return $this->items;
    }

    public function setAddress(OrderAddress $address): void
    {
        $this->address = $address;
    }

    public function addItem(OrderItem $item): void
    {
        $this->items->add($item);
    }

    public function transitionTo(OrderStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) throw new DomainException('Invalid order status transition.');
        $this->status = $target;
    }
}
