<?php

namespace App\Tests\Integration\Security;

use App\Application\Security\ProfileException;
use App\Infrastructure\Security\DbalUserProvider;
use App\Infrastructure\Security\PasswordResetService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetServiceTest extends KernelTestCase
{
    private Connection $connection;

    private UserPasswordHasherInterface $passwords;

    protected function setUp(): void
    {
        if (getenv('RUN_DB_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DB_TESTS=1 with an isolated PostgreSQL database.');
        }

        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->passwords = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->connection->executeStatement(
            'TRUNCATE TABLE password_reset_tokens, users RESTART IDENTITY CASCADE',
        );
        $this->connection->insert('users', [
            'name' => 'Reset User',
            'email' => 'reset@example.test',
            'password' => password_hash('old-password', PASSWORD_BCRYPT),
            'accepted_terms' => true,
            'terms_accepted_at' => '2026-01-01 00:00:00',
            'role' => 'user',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], ['accepted_terms' => ParameterType::BOOLEAN]);
    }

    #[Test]
    public function reset_link_is_hashed_single_use_and_changes_the_password(): void
    {
        $transport = new CaptureTransport();
        $provider = new DbalUserProvider($this->connection);
        $service = new PasswordResetService(
            $this->connection,
            $provider,
            $this->passwords,
            new Mailer($transport),
            new MockClock('2026-01-01 12:00:00 UTC'),
            'https://store.test',
            'no-reply@store.test',
        );

        $service->request('reset@example.test');
        self::assertNotNull($transport->message);
        self::assertInstanceOf(Email::class, $transport->message);
        $body = $transport->message->getTextBody();
        self::assertMatchesRegularExpression('/token=([a-f0-9]{64})/', $body);
        preg_match('/token=([a-f0-9]{64})/', $body, $matches);
        $token = $matches[1];
        self::assertNotSame(
            $token,
            $this->connection->fetchOne(
                'SELECT token FROM password_reset_tokens WHERE email = :email',
                ['email' => 'reset@example.test'],
            ),
        );

        $service->reset('reset@example.test', $token, 'new-password');
        $user = $provider->loadUserByIdentifier('reset@example.test');
        self::assertTrue($this->passwords->isPasswordValid($user, 'new-password'));
        self::assertFalse($this->passwords->isPasswordValid($user, 'old-password'));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM password_reset_tokens',
        ));

        $this->expectException(ProfileException::class);
        $service->reset('reset@example.test', $token, 'another-password');
    }
}

final class CaptureTransport implements TransportInterface
{
    public ?RawMessage $message = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->message = $message;

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'capture://';
    }
}
