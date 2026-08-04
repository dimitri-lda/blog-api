<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name:'refresh_tokens')]
#[ORM\Index(name:'IDX_REFRESH_FAMILY',columns:['family'])]
class RefreshToken
{
    #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne,ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private User $user;
    #[ORM\Column(length:64,unique:true)] private string $tokenHash;
    #[ORM\Column(length:36)] private string $family;
    #[ORM\Column] private \DateTimeImmutable $expiresAt;
    #[ORM\Column(nullable:true)] private ?\DateTimeImmutable $revokedAt=null;
    public function __construct(User $user,string $tokenHash,string $family,\DateTimeImmutable $expiresAt){$this->user=$user;$this->tokenHash=$tokenHash;$this->family=$family;$this->expiresAt=$expiresAt;}
    public function getUser():User{return $this->user;} public function getFamily():string{return $this->family;} public function isValid():bool{return $this->revokedAt===null&&$this->expiresAt>new \DateTimeImmutable();} public function revoke():void{$this->revokedAt=new \DateTimeImmutable();}
}
