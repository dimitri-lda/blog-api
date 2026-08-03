<?php

namespace App\Application\Orders\Admin;

use App\Domain\Orders\Repository\AdminOrderRepository;
use App\Domain\Orders\ValueObjects\OrderStatus;

final readonly class ListOrders
{
    public function __construct(private AdminOrderRepository $orders) {}

    public function handle(?string $search, ?OrderStatus $status, int $page = 1): array
    {
        return $this->orders->paginate($search, $status, $page, 20);
    }
}
