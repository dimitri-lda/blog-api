<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name:'order_addresses')]
class OrderAddress
{
    #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    #[ORM\OneToOne(inversedBy:'address'),ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private StoreOrder $order;
    #[ORM\Column(length:80)] private string $firstName; #[ORM\Column(length:80)] private string $lastName; #[ORM\Column(length:180)] private string $line1; #[ORM\Column(length:180,nullable:true)] private ?string $line2; #[ORM\Column(length:80)] private string $city; #[ORM\Column(length:20)] private string $postalCode; #[ORM\Column(length:2)] private string $country;
    public function __construct(StoreOrder $order,string $firstName,string $lastName,string $line1,?string $line2,string $city,string $postalCode,string $country){$this->order=$order;$this->firstName=$firstName;$this->lastName=$lastName;$this->line1=$line1;$this->line2=$line2;$this->city=$city;$this->postalCode=$postalCode;$this->country=strtoupper($country);$order->setAddress($this);}
    public function toArray():array{return['first_name'=>$this->firstName,'last_name'=>$this->lastName,'line1'=>$this->line1,'line2'=>$this->line2,'city'=>$this->city,'postal_code'=>$this->postalCode,'country'=>$this->country];}
}
