<?php

namespace App\Infrastructure\Security;

use App\Infrastructure\Persistence\Doctrine\Cart\DbalCartService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final readonly class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(private DbalCartService $carts)
    {
    }

    public function onAuthenticationSuccess(
        Request $request,
        TokenInterface $token,
    ): ?Response {
        $user = $token->getUser();
        if ($user instanceof SecurityUser) {
            $this->carts->mergeGuestCart($request, $user->id());
        }

        return new JsonResponse([
            'data' => [
                'user' => $user instanceof SecurityUser ? self::user($user) : null,
            ],
        ], $request->attributes->get('_route') === 'api.v1.auth.register' ? 201 : 200);
    }

    public static function user(SecurityUser $user): array
    {
        return [
            'id' => $user->id(),
            'name' => $user->name(),
            'email' => $user->email(),
            'email_verified_at' => $user->emailVerifiedAt(),
            'role' => $user->role()->value,
            'roles' => $user->getRoles(),
        ];
    }
}
