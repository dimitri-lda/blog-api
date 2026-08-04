<?php

namespace App\Presentation;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsEventListener(event:'kernel.exception')]
final class ApiExceptionSubscriber
{
    public function __construct(#[Autowire('%kernel.debug%')]private readonly bool $debug){}
    public function __invoke(ExceptionEvent $event):void
    {
        $request=$event->getRequest();if(!str_starts_with($request->getPathInfo(),'/api/'))return;$error=$event->getThrowable();$status=$error instanceof HttpExceptionInterface?$error->getStatusCode():500;$message=$status>=500&&!$this->debug?'An unexpected error occurred.':$error->getMessage();$event->setResponse(new JsonResponse(['message'=>$message,'errors'=>new \stdClass()],$status));
    }
}
