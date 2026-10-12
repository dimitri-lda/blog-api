<?php

namespace App\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class ApiCsrfSubscriber
{
    public function __construct(private CsrfTokenManagerInterface $tokens)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 32)]
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (
            !$event->isMainRequest()
            || !$request->getPathInfo()
            || !str_starts_with($request->getPathInfo(), '/api/v1/')
            || str_starts_with($request->getPathInfo(), '/api/v1/webhooks/')
            || in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
        ) {
            return;
        }

        $value = $request->headers->get('X-CSRF-Token');
        if (is_string($value) && $this->tokens->isTokenValid(new CsrfToken('api', $value))) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'type' => 'https://httpstatuses.com/403',
            'title' => 'Invalid CSRF token.',
            'status' => 403,
        ], 403));
    }
}
