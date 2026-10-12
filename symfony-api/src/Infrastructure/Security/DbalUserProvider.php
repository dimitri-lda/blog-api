<?php

namespace App\Infrastructure\Security;

use App\Domain\Users\ValueObjects\UserRole;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final readonly class DbalUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function loadUserByIdentifier(string $identifier): SecurityUser
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM users WHERE email = :email',
            ['email' => strtolower($identifier)],
        );
        if ($row === false) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);
            throw $exception;
        }

        return $this->user($row);
    }

    public function refreshUser(UserInterface $user): SecurityUser
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException($user::class);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM users WHERE id = :id',
            ['id' => $user->id()],
        );
        if ($row === false) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($user->getUserIdentifier());
            throw $exception;
        }

        return $this->user($row);
    }

    public function supportsClass(string $class): bool
    {
        return $class === SecurityUser::class;
    }

    public function upgradePassword(
        PasswordAuthenticatedUserInterface|UserInterface $user,
        string $newHashedPassword,
    ): void {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException($user::class);
        }

        $this->connection->update(
            'users',
            [
                'password' => $newHashedPassword,
                'updated_at' => $this->now(),
            ],
            ['id' => $user->id()],
        );
        $user->changePassword($newHashedPassword);
    }

    private function user(array $row): SecurityUser
    {
        return new SecurityUser(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['password'],
            UserRole::from((string) $row['role']),
            $row['email_verified_at'],
        );
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
