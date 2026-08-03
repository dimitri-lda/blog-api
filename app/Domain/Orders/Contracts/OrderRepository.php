<?php

namespace App\Domain\Orders\Contracts;

use App\Domain\Orders\Order;

interface OrderRepository
{
    public function save(Order $order): void;
}
