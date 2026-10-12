<?php

namespace App\Presentation\Http\Api\V1;

use App\Infrastructure\Persistence\Doctrine\Orders\DbalOrderReader;
use App\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final readonly class AccountOrderController
{
    public function __construct(
        private DbalOrderReader $orders,
        private Security $security,
    ) {
    }

    #[Route('/api/v1/me/orders', name: 'api.v1.me.orders', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof SecurityUser) {
            throw new \LogicException('Authenticated user is unavailable.');
        }

        return new JsonResponse(['data' => $this->orders->forUser($user->id())]);
    }
}
