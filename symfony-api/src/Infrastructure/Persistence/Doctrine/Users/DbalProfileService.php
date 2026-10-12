<?php

namespace App\Infrastructure\Persistence\Doctrine\Users;

use App\Application\Security\ProfileException;
use App\Infrastructure\Security\SecurityUser;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class DbalProfileService
{
    public function __construct(
        private Connection $connection,
        private UserPasswordHasherInterface $passwords,
    ) {
    }

    public function profile(SecurityUser $user): array
    {
        return [
            'user' => [
                'id' => $user->id(),
                'name' => $user->name(),
                'email' => $user->email(),
                'email_verified_at' => $user->emailVerifiedAt(),
                'role' => $user->role()->value,
            ],
            'address' => $this->address($user),
        ];
    }

    public function address(SecurityUser $user): ?array
    {
        return $this->connection->fetchAssociative(
            'SELECT * FROM saved_addresses WHERE user_id = :user_id',
            ['user_id' => $user->id()],
        ) ?: null;
    }

    public function update(SecurityUser $user, string $name, string $email): void
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        $verifiedAt = $email === $user->email() ? $user->emailVerifiedAt() : null;

        try {
            $this->connection->update(
                'users',
                [
                    'name' => $name,
                    'email' => $email,
                    'email_verified_at' => $verifiedAt,
                    'updated_at' => $this->now(),
                ],
                ['id' => $user->id()],
            );
        } catch (UniqueConstraintViolationException) {
            throw new ProfileException('email', 'This email is already registered.');
        }
        $user->rename($name, $email, $verifiedAt);
    }

    public function saveAddress(SecurityUser $user, array $address): void
    {
        $now = $this->now();
        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO saved_addresses (
    user_id,
    first_name,
    last_name,
    line1,
    line2,
    city,
    postal_code,
    country,
    created_at,
    updated_at
)
VALUES (
    :user_id,
    :first_name,
    :last_name,
    :line1,
    :line2,
    :city,
    :postal_code,
    :country,
    :created_at,
    :updated_at
)
ON CONFLICT (user_id) DO UPDATE SET
    first_name = EXCLUDED.first_name,
    last_name = EXCLUDED.last_name,
    line1 = EXCLUDED.line1,
    line2 = EXCLUDED.line2,
    city = EXCLUDED.city,
    postal_code = EXCLUDED.postal_code,
    country = EXCLUDED.country,
    updated_at = EXCLUDED.updated_at
SQL,
            [
                'user_id' => $user->id(),
                ...$address,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function changePassword(
        SecurityUser $user,
        string $currentPassword,
        string $newPassword,
    ): void {
        if (!$this->passwords->isPasswordValid($user, $currentPassword)) {
            throw new ProfileException('current_password', 'The password is incorrect.');
        }

        $password = $this->passwords->hashPassword($user, $newPassword);
        $this->connection->update(
            'users',
            [
                'password' => $password,
                'updated_at' => $this->now(),
            ],
            ['id' => $user->id()],
        );
        $user->changePassword($password);
    }

    public function delete(SecurityUser $user, string $password): void
    {
        if (!$this->passwords->isPasswordValid($user, $password)) {
            throw new ProfileException('password', 'The password is incorrect.');
        }

        $this->connection->delete('users', ['id' => $user->id()]);
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
