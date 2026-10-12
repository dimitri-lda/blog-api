<?php

namespace App\Infrastructure\Cache;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

final class DeferredTaskRegistry
{
    /** @var array<string, Closure> */
    private array $tasks = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function add(string $key, Closure $task): void
    {
        $this->tasks[$key] ??= $task;
    }

    public function run(): void
    {
        $tasks = $this->tasks;
        $this->tasks = [];

        foreach ($tasks as $task) {
            try {
                $task();
            } catch (Throwable $exception) {
                $this->logger->error('Deferred storefront cache refresh failed.', ['exception' => $exception]);
            }
        }
    }
}
