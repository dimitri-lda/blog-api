<?php

namespace App\Application\Orders\Admin;

use App\Domain\Orders\Repository\AdminOrderRepository;
use App\Domain\Orders\ValueObjects\OrderStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class ChangeOrderStatus
{
    public function __construct(private AdminOrderRepository $orders)
    {
    }

    public function handle(int $orderId, OrderStatus $target): void
    {
        $current = $this->orders->statusOf($orderId);

        if (!$current) {
            throw (new ModelNotFoundException)->setModel('Order', [$orderId]);
        }

        $this->orders->updateStatus($orderId, $current->transitionTo($target));
    }
}
