<?php

namespace App\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class LogoutSubscriber
{
    #[AsEventListener]
    public function __invoke(LogoutEvent $event): void
    {
        $event->setResponse(new JsonResponse(['data' => ['logged_out' => true]]));
    }
}
