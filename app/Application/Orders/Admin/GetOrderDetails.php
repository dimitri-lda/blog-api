<?php

namespace App\Application\Orders\Admin;

use App\Domain\Orders\Repository\AdminOrderRepository;

final readonly class GetOrderDetails
{
    public function __construct(private AdminOrderRepository $orders) {}

    public function handle(int $orderId): ?array
    {
        return $this->orders->findDetails($orderId);
    }
}
