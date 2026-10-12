<?php

namespace App\Infrastructure\Security;

use App\Application\Security\ProfileException;
use DateInterval;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class PasswordResetService
{
    public function __construct(
        private Connection $connection,
        private DbalUserProvider $users,
        private UserPasswordHasherInterface $passwords,
        private MailerInterface $mailer,
        private ClockInterface $clock,
        private string $frontendUrl,
        private string $sender,
    ) {
    }

    public function request(string $email): void
    {
        $email = strtolower(trim($email));
        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM users WHERE email = :email',
            ['email' => $email],
        );
        if ($exists === false) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO password_reset_tokens (email, token, created_at)
VALUES (:email, :token, :created_at)
ON CONFLICT (email) DO UPDATE SET
    token = EXCLUDED.token,
    created_at = EXCLUDED.created_at
SQL,
            [
                'email' => $email,
                'token' => hash('sha256', $token),
                'created_at' => $this->now(),
            ],
        );
        $url = rtrim($this->frontendUrl, '/').'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $email,
        ]);
        $this->mailer->send(
            (new Email())
                ->from($this->sender)
                ->to($email)
                ->subject('Reset your daoSport password')
                ->text("Reset your password: {$url}"),
        );
    }

    public function reset(string $email, string $token, string $newPassword): void
    {
        $email = strtolower(trim($email));
        $record = $this->connection->fetchAssociative(
            'SELECT token, created_at FROM password_reset_tokens WHERE email = :email',
            ['email' => $email],
        );
        if (
            $record === false
            || !hash_equals((string) $record['token'], hash('sha256', $token))
            || new \DateTimeImmutable((string) $record['created_at'])
                < $this->clock->now()->sub(new DateInterval('PT1H'))
        ) {
            throw new ProfileException('email', 'This password reset token is invalid or expired.');
        }

        try {
            $user = $this->users->loadUserByIdentifier($email);
        } catch (\Throwable) {
            throw new ProfileException('email', 'This password reset token is invalid or expired.');
        }
        $password = $this->passwords->hashPassword($user, $newPassword);
        $this->connection->transactional(function () use ($user, $password, $email): void {
            $this->connection->update(
                'users',
                [
                    'password' => $password,
                    'remember_token' => bin2hex(random_bytes(30)),
                    'updated_at' => $this->now(),
                ],
                ['id' => $user->id()],
            );
            $this->connection->delete('password_reset_tokens', ['email' => $email]);
        });
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
