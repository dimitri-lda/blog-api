<?php

namespace App\Infrastructure\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

final class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(
        Request $request,
        AuthenticationException $exception,
    ): Response {
        return new JsonResponse([
            'type' => 'https://httpstatuses.com/422',
            'title' => 'Authentication failed.',
            'status' => 422,
            'errors' => [
                'email' => ['The provided credentials are incorrect.'],
            ],
        ], 422);
    }
}
