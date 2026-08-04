<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name:'product_images')]
class ProductImage
{
    #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne(inversedBy:'images'),ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private Product $product;
    #[ORM\Column(length:500)] private string $url;
    #[ORM\Column] private int $position=0;
    public function __construct(Product $product,string $url,int $position=0){$this->product=$product;$this->url=$url;$this->position=$position;}
    public function getId():?int{return $this->id;} public function getUrl():string{return $this->url;} public function getPosition():int{return $this->position;}
}
