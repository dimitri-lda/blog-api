<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContextControllerTest extends WebTestCase
{
    public function test_context_returns_market_countries_translations_and_csrf_token(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/context');

        self::assertResponseIsSuccessful();
        $data = $this->payload($client)['data'];
        self::assertNull($data['auth']['user']);
        self::assertSame('DE', $data['market']['country']);
        self::assertSame('EUR', $data['market']['currency']);
        self::assertSame('en', $data['market']['locale']);
        self::assertSame('Germany', $data['countries']['DE']['en']);
        self::assertSame('Shop all', $data['translations']['shop_all']);
        self::assertNotSame('', $data['csrf_token']);
    }

    public function test_preferences_are_saved_in_the_session_and_reflected_by_context(): void
    {
        $client = self::createClient();
        $this->enableCsrf($client);
        $client->jsonRequest('PUT', '/api/v1/preferences', [
            'country' => 'PL',
            'locale' => 'ru',
        ]);

        self::assertResponseIsSuccessful();
        $preference = $this->payload($client)['data'];
        self::assertSame('PL', $preference['market']['country']);
        self::assertSame('PLN', $preference['market']['currency']);
        self::assertSame('ru', $preference['market']['locale']);
        self::assertSame('Настройки обновлены.', $preference['message']);

        $client->request('GET', '/api/v1/context');
        self::assertResponseIsSuccessful();
        $market = $this->payload($client)['data']['market'];
        self::assertSame('PL', $market['country']);
        self::assertSame('ru', $market['locale']);
    }

    public function test_preferences_reject_an_unsupported_country(): void
    {
        $client = self::createClient();
        $this->enableCsrf($client);
        $client->jsonRequest('PUT', '/api/v1/preferences', [
            'country' => 'XX',
            'locale' => 'en',
        ]);

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload($client);
        self::assertSame(422, $payload['status']);
        self::assertSame(['Choose a supported country.'], $payload['errors']['country']);
    }

    private function payload(KernelBrowser $client): array
    {
        return json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function enableCsrf(KernelBrowser $client): void
    {
        $client->request('GET', '/api/v1/context');
        $client->setServerParameter(
            'HTTP_X_CSRF_TOKEN',
            $this->payload($client)['data']['csrf_token'],
        );
    }
}
