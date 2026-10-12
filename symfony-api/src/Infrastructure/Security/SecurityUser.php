<?php

namespace App\Infrastructure\Security;

use App\Application\Security\UserIdentity;
use App\Domain\Users\ValueObjects\UserRole;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface, UserIdentity
{
    public function __construct(
        private readonly int $userId,
        private string $name,
        private string $email,
        private string $password,
        private readonly UserRole $role,
        private ?string $emailVerifiedAt,
    ) {
    }

    public function id(): int
    {
        return $this->userId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function role(): UserRole
    {
        return $this->role;
    }

    public function emailVerifiedAt(): ?string
    {
        return $this->emailVerifiedAt;
    }

    public function rename(string $name, string $email, ?string $emailVerifiedAt): void
    {
        $this->name = $name;
        $this->email = $email;
        $this->emailVerifiedAt = $emailVerifiedAt;
    }

    public function changePassword(string $password): void
    {
        $this->password = $password;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return match ($this->role) {
            UserRole::User => ['ROLE_USER'],
            UserRole::Admin => ['ROLE_ADMIN', 'ROLE_USER'],
            UserRole::Superadmin => ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_USER'],
        };
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function eraseCredentials(): void
    {
    }
}
