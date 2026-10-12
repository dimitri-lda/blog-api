<?php

namespace App\Presentation\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/healthz', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'status' => 'ok',
                'service' => 'symfony-api',
            ],
        ]);
    }
}
