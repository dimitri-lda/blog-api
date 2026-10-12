<?php

namespace App\Presentation\Http;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class ApiExceptionSubscriber
{
    public function __construct(private Security $security)
    {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        $validation = $this->validation($exception);
        if ($validation !== null) {
            $event->setResponse($this->problem(422, 'Validation failed.', errors: $validation));

            return;
        }
        if ($exception instanceof AccessDeniedException) {
            $status = $this->security->getUser() === null ? 401 : 403;
            $event->setResponse($this->problem(
                $status,
                $status === 401 ? 'Authentication required.' : 'Forbidden.',
            ));

            return;
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $detail = $status < 500 && $exception->getMessage() !== ''
            ? $exception->getMessage()
            : null;
        $event->setResponse($this->problem(
            $status,
            $status === 404 ? 'Not Found' : ($status >= 500 ? 'Internal Server Error' : 'Request failed.'),
            $detail,
        ));
    }

    private function validation(\Throwable $exception): ?array
    {
        $previous = $exception;
        while ($previous !== null && !($previous instanceof ValidationFailedException)) {
            $previous = $previous->getPrevious();
        }
        if (!$previous instanceof ValidationFailedException) {
            return null;
        }

        $errors = [];
        foreach ($previous->getViolations() as $violation) {
            $field = $violation->getPropertyPath() ?: 'request';
            $errors[$field][] = (string) $violation->getMessage();
        }

        return $errors;
    }

    private function problem(
        int $status,
        string $title,
        ?string $detail = null,
        ?array $errors = null,
    ): JsonResponse {
        $payload = [
            'type' => "https://httpstatuses.com/{$status}",
            'title' => $title,
            'status' => $status,
        ];
        if ($detail !== null) {
            $payload['detail'] = $detail;
        }
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return new JsonResponse($payload, $status, ['Content-Type' => 'application/problem+json']);
    }
}
