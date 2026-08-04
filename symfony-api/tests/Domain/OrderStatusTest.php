<?php

namespace App\Tests\Domain;

use App\Domain\Orders\OrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
    public function testHappyPathTransitionsAreAllowed():void{$status=OrderStatus::PendingPayment;foreach([OrderStatus::Paid,OrderStatus::Processing,OrderStatus::Shipped,OrderStatus::Delivered]as$next){self::assertTrue($status->canTransitionTo($next));$status=$next;}}
    public function testTerminalStatusesHaveNoNextState():void{self::assertSame([],OrderStatus::Cancelled->allowedNext());self::assertSame([],OrderStatus::Refunded->allowedNext());}
    public function testSkippingPaymentIsRejected():void{self::assertFalse(OrderStatus::PendingPayment->canTransitionTo(OrderStatus::Shipped));}
}
