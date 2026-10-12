<?php

namespace App\Infrastructure\Security;

use App\Application\Security\RegistrationException;
use App\Domain\Users\ValueObjects\UserRole;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class DbalUserAccounts
{
    public function __construct(
        private Connection $connection,
        private UserPasswordHasherInterface $passwords,
    ) {
    }

    public function register(string $name, string $email, string $plainPassword): SecurityUser
    {
        $email = strtolower(trim($email));
        $name = trim($name);
        $user = new SecurityUser(0, $name, $email, '', UserRole::User, null);
        $password = $this->passwords->hashPassword($user, $plainPassword);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $id = (int) $this->connection->fetchOne(
                <<<'SQL'
INSERT INTO users (
    name,
    email,
    password,
    accepted_terms,
    terms_accepted_at,
    role,
    created_at,
    updated_at
)
VALUES (
    :name,
    :email,
    :password,
    TRUE,
    :terms_accepted_at,
    :role,
    :created_at,
    :updated_at
)
RETURNING id
SQL,
                [
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'terms_accepted_at' => $now,
                    'role' => UserRole::User->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            throw new RegistrationException('This email is already registered.');
        }

        return new SecurityUser($id, $name, $email, $password, UserRole::User, null);
    }
}
