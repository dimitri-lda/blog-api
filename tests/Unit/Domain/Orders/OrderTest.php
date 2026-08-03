<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\Entities\OrderLine;
use App\Domain\Orders\Order;
use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Domain\Orders\ValueObjects\ShippingAddress;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrderTest extends TestCase
{
    #[Test]
    public function it_calculates_standard_delivery_from_the_order_total(): void
    {
        $order = new Order('SP-TEST', null, 'buyer@example.test', '123', DeliveryMethod::Standard, new ShippingAddress('A', 'B', 'Street 1', null, 'City', '00-001', 'PL'), [new OrderLine(1, 'Product', 'Default', 2500, 2)]);

        $this->assertSame(5000, $order->subtotalCents());
        $this->assertSame(0, $order->deliveryCents());
        $this->assertSame(5000, $order->totalCents());
    }
}
