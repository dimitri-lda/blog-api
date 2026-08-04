<?php

namespace App\Application;

use App\Entity\{Cart, CartItem, ProductVariant, User};
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\Uid\Uuid;

final readonly class CartService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return array{Cart,?string} */
    public function resolve(?User $user, ?string $guestToken): array
    {
        if ($user) {
            $cart = $this->em->getRepository(Cart::class)->findOneBy(['user' => $user]);
            if (!$cart) {
                $cart = new Cart($user);
                $this->em->persist($cart);
                $this->em->flush();
            }
            return [$cart, null];
        }
        $cart = $guestToken ? $this->em->getRepository(Cart::class)->findOneBy(['token' => $guestToken]) : null;
        if (!$cart) {
            $guestToken = Uuid::v4()->toRfc4122();
            $cart = new Cart(null, $guestToken);
            $this->em->persist($cart);
            $this->em->flush();
            return [$cart, $guestToken];
        }
        return [$cart, null];
    }

    public function add(Cart $cart, ProductVariant $variant, int $quantity): void
    {
        if (!$variant->getProduct()->isActive() || $variant->getStock() < 1) throw new DomainException('This product is out of stock.');
        foreach ($cart->getItems() as $item) if ($item->getVariant() === $variant) {
            $item->setQuantity(min($variant->getStock(), 20, $item->getQuantity() + $quantity));
            $this->em->flush();
            return;
        }
        $this->em->persist(new CartItem($cart, $variant, min($variant->getStock(), 20, $quantity)));
        $this->em->flush();
    }

    public function update(Cart $cart, CartItem $item, int $quantity): void
    {
        if (!$cart->getItems()->contains($item)) throw new DomainException('Cart item not found.');
        if ($quantity <= 0) {
            $cart->removeItem($item);
            $this->em->remove($item);
        } else {
            $item->setQuantity(min(20, $item->getVariant()->getStock(), $quantity));
        }
        $this->em->flush();
    }
}
