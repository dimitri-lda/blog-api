<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\ValueObjects\OrderStatus;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrderStatusTest extends TestCase
{
    #[Test]
    public function it_allows_the_happy_path_order_workflow(): void
    {
        $status = OrderStatus::PendingPayment;

        foreach ([OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered] as $next) {
            $status = $status->transitionTo($next);
        }

        $this->assertSame(OrderStatus::Delivered, $status);
    }

    #[Test]
    public function it_rejects_an_invalid_transition(): void
    {
        $this->expectException(DomainException::class);

        OrderStatus::PendingPayment->transitionTo(OrderStatus::Shipped);
    }

    #[Test]
    public function terminal_statuses_cannot_change(): void
    {
        $this->assertSame([], OrderStatus::Cancelled->allowedNextStatuses());
        $this->assertSame([], OrderStatus::Refunded->allowedNextStatuses());
    }
}
