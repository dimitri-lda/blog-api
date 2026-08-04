<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 255)]
    private string $name;
    #[ORM\Column(length: 255, unique: true)]
    private string $email;
    #[ORM\Column]
    private string $password;
    #[ORM\Column(type: 'json')]
    private array $roles = ['ROLE_USER'];
    #[ORM\Column(options: ['default' => false])]
    private bool $acceptedTerms = false;
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $emailVerifiedAt = null;
    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(string $name, string $email)
    {
        $this->name = $name;
        $this->email = strtolower($email);
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = strtolower($email);
        $this->emailVerifiedAt = null;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function acceptTerms(): void
    {
        $this->acceptedTerms = true;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function verifyEmail(): void
    {
        $this->emailVerifiedAt = new DateTimeImmutable();
    }

    public function eraseCredentials(): void
    {
    }
}
