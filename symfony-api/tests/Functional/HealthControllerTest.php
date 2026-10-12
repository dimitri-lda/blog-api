<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthzReturnsExpectedPayload(): void
    {
        $client = self::createClient();
        $client->request('GET', '/healthz');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->contains('Content-Type', 'application/json'));
        self::assertSame(
            ['data' => ['status' => 'ok', 'service' => 'symfony-api']],
            json_decode((string) $response->getContent(), true),
        );
    }
}
