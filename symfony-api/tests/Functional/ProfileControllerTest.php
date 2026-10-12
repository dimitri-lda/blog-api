<?php

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfileControllerTest extends WebTestCase
{
    private Connection $connection;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        if (getenv('RUN_DB_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DB_TESTS=1 with an isolated PostgreSQL database.');
        }

        $this->client = self::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement(
            <<<'SQL'
TRUNCATE TABLE
    saved_addresses,
    users
RESTART IDENTITY CASCADE
SQL,
        );
    }

    public function test_anonymous_profile_request_is_rejected(): void
    {
        $this->client->request('GET', '/api/v1/profile');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('Authentication required.', $this->payload()['title']);
    }

    public function test_profile_returns_user_and_null_address(): void
    {
        $this->user('user@example.test');
        $this->login('user@example.test');

        $this->client->request('GET', '/api/v1/profile');
        self::assertResponseIsSuccessful();
        $data = $this->payload()['data'];
        self::assertSame(1, $data['user']['id']);
        self::assertSame('Existing User', $data['user']['name']);
        self::assertSame('user@example.test', $data['user']['email']);
        self::assertNull($data['address']);
    }

    public function test_profile_update_preserves_verification_and_lowercases_new_email(): void
    {
        $this->user('user@example.test');
        $this->login('user@example.test');

        $this->client->jsonRequest('PATCH', '/api/v1/profile', [
            'name' => 'Renamed User',
            'email' => 'user@example.test',
        ]);
        self::assertResponseIsSuccessful();
        $user = $this->payload()['data']['user'];
        self::assertSame('Renamed User', $user['name']);
        self::assertNotNull($user['email_verified_at']);
        self::assertSame(
            '2026-01-01 00:00:00',
            $this->connection->fetchOne('SELECT email_verified_at FROM users WHERE id = 1'),
        );

        $this->client->jsonRequest('PATCH', '/api/v1/profile', [
            'name' => 'Renamed User',
            'email' => 'MIXED@Example.Test',
        ]);
        self::assertResponseIsSuccessful();
        $user = $this->payload()['data']['user'];
        self::assertSame('mixed@example.test', $user['email']);
        self::assertNull($user['email_verified_at']);
        $row = $this->connection->fetchAssociative(
            'SELECT email, email_verified_at FROM users WHERE id = 1',
        );
        self::assertSame('mixed@example.test', $row['email']);
        self::assertNull($row['email_verified_at']);
    }

    public function test_saved_address_is_created_then_updated_in_place(): void
    {
        $this->user('user@example.test');
        $this->login('user@example.test');

        $this->client->jsonRequest('PUT', '/api/v1/profile/address', [
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'line1' => 'Marszalkowska 1',
            'city' => 'Warsaw',
            'postal_code' => '00-001',
            'country' => 'PL',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('Warsaw', $this->payload()['data']['address']['city']);
        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM saved_addresses WHERE user_id = 1'),
        );

        $this->client->jsonRequest('PUT', '/api/v1/profile/address', [
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'line1' => 'Marszalkowska 1',
            'line2' => 'Apt 5',
            'city' => 'Krakow',
            'postal_code' => '30-001',
            'country' => 'PL',
        ]);
        self::assertResponseIsSuccessful();
        $address = $this->payload()['data']['address'];
        self::assertSame('Krakow', $address['city']);
        self::assertSame('Apt 5', $address['line2']);
        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM saved_addresses WHERE user_id = 1'),
        );
        self::assertSame('Krakow', $this->connection->fetchOne(
            'SELECT city FROM saved_addresses WHERE user_id = 1',
        ));
    }

    public function test_password_change_validates_and_new_password_authenticates(): void
    {
        $this->user('user@example.test');
        $this->login('user@example.test');

        $this->client->jsonRequest('PUT', '/api/v1/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('current_password', $this->payload()['errors']);

        $this->client->jsonRequest('PUT', '/api/v1/profile/password', [
            'current_password' => 'password',
            'password' => 'new-password-9',
            'password_confirmation' => 'different-9',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('password', $this->payload()['errors']);

        $this->client->jsonRequest('PUT', '/api/v1/profile/password', [
            'current_password' => 'password',
            'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['data']['password_updated']);

        $this->logout();
        $this->enableCsrf();
        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'user@example.test',
            'password' => 'new-password-9',
        ]);
        self::assertResponseIsSuccessful();

        $this->logout();
        $this->enableCsrf();
        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'user@example.test',
            'password' => 'password',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function test_delete_profile_requires_password_and_invalidates_session(): void
    {
        $this->user('user@example.test');
        $this->login('user@example.test');

        $this->client->jsonRequest('DELETE', '/api/v1/profile', [
            'password' => 'wrong-password',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('password', $this->payload()['errors']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'));

        $this->client->jsonRequest('DELETE', '/api/v1/profile', [
            'password' => 'password',
        ]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'));

        $this->client->request('GET', '/api/v1/context');
        self::assertResponseIsSuccessful();
        self::assertNull($this->payload()['data']['auth']['user']);
    }

    private function user(string $email): void
    {
        $this->connection->insert('users', [
            'name' => 'Existing User',
            'email' => $email,
            'email_verified_at' => '2026-01-01 00:00:00',
            'password' => password_hash('password', PASSWORD_BCRYPT),
            'accepted_terms' => true,
            'terms_accepted_at' => '2026-01-01 00:00:00',
            'role' => 'user',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], ['accepted_terms' => ParameterType::BOOLEAN]);
    }

    private function login(string $email): void
    {
        $this->enableCsrf();
        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'password',
        ]);
        self::assertResponseIsSuccessful();
        $this->enableCsrf();
    }

    private function logout(): void
    {
        $this->enableCsrf();
        $this->client->request('POST', '/api/v1/auth/logout');
        self::assertResponseIsSuccessful();
    }

    private function enableCsrf(): void
    {
        $this->client->request('GET', '/api/v1/context');
        $this->client->setServerParameter(
            'HTTP_X_CSRF_TOKEN',
            $this->payload()['data']['csrf_token'],
        );
    }

    private function payload(): array
    {
        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
