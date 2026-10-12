<?php

namespace App\Domain\Payments;

enum PaymentSessionStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
}
