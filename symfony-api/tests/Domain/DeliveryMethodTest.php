<?php

namespace App\Tests\Domain;

use App\Domain\Orders\DeliveryMethod;
use PHPUnit\Framework\TestCase;

final class DeliveryMethodTest extends TestCase
{
    public function testStandardDeliveryBecomesFreeAtFiftyEuros():void{self::assertSame(590,DeliveryMethod::Standard->feeFor(4999));self::assertSame(0,DeliveryMethod::Standard->feeFor(5000));}
    public function testExpressDeliveryHasFixedPrice():void{self::assertSame(1290,DeliveryMethod::Express->feeFor(100000));}
}
