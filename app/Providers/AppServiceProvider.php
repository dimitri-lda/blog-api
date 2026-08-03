<?php

namespace App\Providers;

use App\Domain\Cart\Contracts\CartRepository;
use App\Domain\Orders\Contracts\OrderRepository;
use App\Infrastructure\Persistence\Eloquent\Cart\EloquentCartRepository;
use App\Infrastructure\Persistence\Eloquent\Orders\EloquentOrderRepository;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CartRepository::class, EloquentCartRepository::class);
        $this->app->bind(OrderRepository::class, EloquentOrderRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
