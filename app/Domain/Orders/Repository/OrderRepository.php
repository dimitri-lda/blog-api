<?php

namespace App\Domain\Orders\Repository;

use App\Domain\Orders\Order;

interface OrderRepository
{
    public function save(Order $order): void;
}
