<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'account_tokens')]
class AccountToken
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;
    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;
    #[ORM\Column(length: 30)]
    private string $type;
    #[ORM\Column]
    private DateTimeImmutable $expiresAt;
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    public function __construct(User $user, string $tokenHash, string $type, DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->type = $type;
        $this->expiresAt = $expiresAt;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isUsable(string $type): bool
    {
        return $this->type === $type && $this->usedAt === null && $this->expiresAt > new DateTimeImmutable();
    }

    public function use(): void
    {
        $this->usedAt = new DateTimeImmutable();
    }
}
