<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name:'carts')]
class Cart
{
    #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    #[ORM\OneToOne,ORM\JoinColumn(nullable:true,onDelete:'CASCADE')] private ?User $user=null;
    #[ORM\Column(length:64,unique:true,nullable:true)] private ?string $token=null;
    #[ORM\OneToMany(mappedBy:'cart',targetEntity:CartItem::class,cascade:['persist','remove'],orphanRemoval:true)] private Collection $items;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    public function __construct(?User $user=null,?string $token=null){$this->user=$user;$this->token=$token;$this->items=new ArrayCollection();$this->createdAt=new \DateTimeImmutable();}
    public function getId():?int{return $this->id;} public function getItems():Collection{return $this->items;} public function getToken():?string{return $this->token;}
    public function addItem(CartItem $item):void{if(!$this->items->contains($item))$this->items->add($item);} public function removeItem(CartItem $item):void{$this->items->removeElement($item);}
}
