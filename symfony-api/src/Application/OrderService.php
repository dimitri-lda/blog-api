<?php

namespace App\Application;

use App\Domain\Orders\DeliveryMethod;
use App\Entity\{Cart, OrderAddress, OrderItem, StoreOrder, User};
use Doctrine\ORM\EntityManagerInterface;

final readonly class OrderService
{
    public function __construct(private EntityManagerInterface $em) {}
    public function place(Cart $cart,?User $user,array $data):StoreOrder
    {
        return $this->em->wrapInTransaction(function()use($cart,$user,$data){
            if($cart->getItems()->isEmpty())throw new \DomainException('Your cart is empty.');
            $subtotal=0;foreach($cart->getItems() as $item){$variant=$item->getVariant();if(!$variant->getProduct()->isActive()||$variant->getStock()<$item->getQuantity())throw new \DomainException("Product variant {$variant->getId()} is unavailable.");$subtotal+=($variant->getPriceCents()??$variant->getProduct()->getPriceCents())*$item->getQuantity();}
            $method=DeliveryMethod::tryFrom((string)($data['delivery_method']??''))??throw new \DomainException('Invalid delivery method.');
            $order=new StoreOrder($user,'SP-'.strtoupper(bin2hex(random_bytes(4))),$data['email'],$data['phone'],$method,$subtotal);
            $this->em->persist($order);$this->em->persist(new OrderAddress($order,$data['first_name'],$data['last_name'],$data['line1'],$data['line2']??null,$data['city'],$data['postal_code'],$data['country']));
            foreach($cart->getItems()->toArray() as $cartItem){$this->em->persist(new OrderItem($order,$cartItem->getVariant(),$cartItem->getQuantity()));$this->em->remove($cartItem);}
            $this->em->flush();return $order;
        });
    }
}
