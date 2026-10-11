<?php

namespace App\Domain\Payments;

enum PaymentAttemptStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
