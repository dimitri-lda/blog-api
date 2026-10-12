<?php

namespace App\Infrastructure\Cache;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::TERMINATE)]
final readonly class RunDeferredTasksSubscriber
{
    public function __construct(private DeferredTaskRegistry $registry)
    {
    }

    public function __invoke(): void
    {
        $this->registry->run();
    }
}
