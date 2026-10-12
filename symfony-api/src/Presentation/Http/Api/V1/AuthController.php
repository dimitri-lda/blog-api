<?php

namespace App\Presentation\Http\Api\V1;

use App\Application\Security\RegistrationException;
use App\Infrastructure\Security\DbalUserAccounts;
use App\Infrastructure\Security\LoginSuccessHandler;
use App\Infrastructure\Security\PasswordResetService;
use App\Application\Security\ProfileException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AuthController
{
    public function __construct(
        private DbalUserAccounts $accounts,
        private Security $security,
        private PasswordResetService $passwordResets,
    ) {
    }

    #[Route('/api/v1/auth/register', name: 'api.v1.auth.register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload] RegisterPayload $payload,
    ): Response {
        if (!hash_equals($payload->password, $payload->password_confirmation)) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/422',
                'title' => 'Validation failed.',
                'status' => 422,
                'errors' => [
                    'password' => ['The password confirmation does not match.'],
                ],
            ], 422);
        }

        try {
            $user = $this->accounts->register($payload->name, $payload->email, $payload->password);
        } catch (RegistrationException $exception) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/422',
                'title' => 'Validation failed.',
                'status' => 422,
                'errors' => [
                    'email' => [$exception->getMessage()],
                ],
            ], 422);
        }

        return $this->security->login($user)
            ?? new JsonResponse(['data' => ['user' => LoginSuccessHandler::user($user)]], 201);
    }

    #[Route('/api/v1/auth/login', name: 'api.v1.auth.login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return new JsonResponse(['status' => 401], 401);
    }

    #[Route('/api/v1/auth/logout', name: 'api.v1.auth.logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        return new JsonResponse(['status' => 401], 401);
    }

    #[Route('/api/v1/auth/forgot-password', name: 'api.v1.auth.forgot_password', methods: ['POST'])]
    public function forgotPassword(
        #[MapRequestPayload] ForgotPasswordPayload $payload,
    ): JsonResponse {
        $this->passwordResets->request($payload->email);

        return new JsonResponse([
            'data' => [
                'message' => 'If the account exists, a reset link has been sent.',
            ],
        ]);
    }

    #[Route('/api/v1/auth/reset-password', name: 'api.v1.auth.reset_password', methods: ['POST'])]
    public function resetPassword(
        #[MapRequestPayload] ResetPasswordPayload $payload,
    ): JsonResponse {
        if (!hash_equals($payload->password, $payload->password_confirmation)) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/422',
                'title' => 'Validation failed.',
                'status' => 422,
                'errors' => [
                    'password' => ['The password confirmation does not match.'],
                ],
            ], 422);
        }

        try {
            $this->passwordResets->reset($payload->email, $payload->token, $payload->password);
        } catch (ProfileException $exception) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/422',
                'title' => 'Validation failed.',
                'status' => 422,
                'errors' => [$exception->field => [$exception->getMessage()]],
            ], 422);
        }

        return new JsonResponse(['data' => ['password_reset' => true]]);
    }
}
