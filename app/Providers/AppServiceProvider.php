<?php

namespace App\Providers;

use App\Domain\Cart\Repository\CartRepository;
use App\Domain\Orders\Repository\AdminOrderRepository;
use App\Domain\Orders\Repository\OrderRepository;
use App\Infrastructure\Persistence\Eloquent\Cart\EloquentCartRepository;
use App\Infrastructure\Persistence\Eloquent\Orders\EloquentAdminOrderRepository;
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
        $this->app->bind(AdminOrderRepository::class, EloquentAdminOrderRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
