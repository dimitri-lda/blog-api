<?php

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthControllerTest extends WebTestCase
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
    cart_items,
    carts,
    product_variants,
    products,
    categories,
    saved_addresses,
    users
RESTART IDENTITY CASCADE
SQL,
        );
        $this->enableCsrf();
    }

    public function test_user_can_register_is_authenticated_and_can_logout(): void
    {
        $this->register('user@example.test');

        self::assertResponseStatusCodeSame(201);
        $user = $this->payload()['data']['user'];
        self::assertSame('Test User', $user['name']);
        self::assertSame('user@example.test', $user['email']);
        self::assertSame('user', $user['role']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'));

        $this->client->request('GET', '/api/v1/context');
        self::assertResponseIsSuccessful();
        self::assertSame('user@example.test', $this->payload()['data']['auth']['user']['email']);

        $this->enableCsrf();
        $this->client->request('POST', '/api/v1/auth/logout');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['data']['logged_out']);

        $this->client->request('GET', '/api/v1/context');
        self::assertResponseIsSuccessful();
        self::assertNull($this->payload()['data']['auth']['user']);
    }

    public function test_existing_laravel_bcrypt_user_can_login_and_invalid_password_is_rejected(): void
    {
        $this->user('existing@example.test', password_hash('password', PASSWORD_BCRYPT));

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'existing@example.test',
            'password' => 'wrong-password',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['The provided credentials are incorrect.'],
            $this->payload()['errors']['email'],
        );

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'existing@example.test',
            'password' => 'password',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('existing@example.test', $this->payload()['data']['user']['email']);
    }

    public function test_registration_rejects_duplicate_email_and_password_mismatch(): void
    {
        $this->user('duplicate@example.test', password_hash('password', PASSWORD_BCRYPT));

        $this->register('duplicate@example.test');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['This email is already registered.'], $this->payload()['errors']['email']);

        $this->client->jsonRequest('POST', '/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'other@example.test',
            'password' => 'password',
            'password_confirmation' => 'different',
            'accepted_terms' => true,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['The password confirmation does not match.'],
            $this->payload()['errors']['password'],
        );
    }

    public function test_state_changing_api_requests_require_the_session_csrf_token(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');
        $this->client->jsonRequest('POST', '/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'user@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'accepted_terms' => true,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Invalid CSRF token.', $this->payload()['title']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_registration_merges_the_guest_cart_into_the_new_user_cart(): void
    {
        $this->catalogFixture();
        $this->client->jsonRequest('POST', '/api/v1/cart/items', [
            'variant_id' => 1,
            'quantity' => 2,
        ]);
        self::assertResponseStatusCodeSame(201);
        $guestCartId = (int) $this->connection->fetchOne(
            'SELECT cart_id FROM cart_items LIMIT 1',
        );

        $this->register('buyer@example.test');
        self::assertResponseStatusCodeSame(201);
        $userId = (int) $this->connection->fetchOne(
            'SELECT id FROM users WHERE email = :email',
            ['email' => 'buyer@example.test'],
        );
        $userCartId = (int) $this->connection->fetchOne(
            'SELECT id FROM carts WHERE user_id = :user_id',
            ['user_id' => $userId],
        );

        self::assertNotSame($guestCartId, $userCartId);
        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT quantity FROM cart_items WHERE cart_id = :cart_id',
            ['cart_id' => $userCartId],
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM carts WHERE id = :id',
            ['id' => $guestCartId],
        ));
    }

    private function register(string $email): void
    {
        $this->client->jsonRequest('POST', '/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
            'accepted_terms' => true,
        ]);
    }

    private function user(string $email, string $password): void
    {
        $this->connection->insert('users', [
            'name' => 'Existing User',
            'email' => $email,
            'password' => $password,
            'accepted_terms' => true,
            'terms_accepted_at' => '2026-01-01 00:00:00',
            'role' => 'user',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], ['accepted_terms' => ParameterType::BOOLEAN]);
    }

    private function catalogFixture(): void
    {
        $timestamps = [
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
        $this->connection->insert('categories', [
            'name' => 'Running',
            'slug' => 'running',
            ...$timestamps,
        ]);
        $categoryId = (int) $this->connection->lastInsertId();
        $this->connection->insert('products', [
            'category_id' => $categoryId,
            'name' => 'Shoes',
            'slug' => 'shoes',
            'description' => 'Description',
            'price_cents_net' => 1000,
            'image_url' => '/shoe.jpg',
            'featured' => false,
            'active' => true,
            ...$timestamps,
        ], [
            'featured' => ParameterType::BOOLEAN,
            'active' => ParameterType::BOOLEAN,
        ]);
        $productId = (int) $this->connection->lastInsertId();
        $this->connection->insert('product_variants', [
            'product_id' => $productId,
            'name' => '42',
            'sku' => 'SHOE-42',
            'stock' => 3,
            ...$timestamps,
        ]);
    }

    private function enableCsrf(): void
    {
        $this->client->request('GET', '/api/v1/context');
        $this->client->setServerParameter(
            'HTTP_X_CSRF_TOKEN',
            $this->payload()['data']['csrf_token'],
        );
    }

    private function payload(?KernelBrowser $client = null): array
    {
        $client ??= $this->client;

        return json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
