<?php

namespace App\Domain\Orders\ValueObjects;

use DomainException;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function transitionTo(self $target): self
    {
        if (! $this->canTransitionTo($target)) {
            throw new DomainException("Cannot change an order from {$this->value} to {$target->value}.");
        }

        return $target;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNextStatuses(), true);
    }

    /** @return list<self> */
    public function allowedNextStatuses(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid, self::Cancelled],
            self::Paid => [self::Processing, self::Cancelled, self::Refunded],
            self::Processing => [self::Shipped, self::Cancelled, self::Refunded],
            self::Shipped => [self::Delivered, self::Refunded],
            self::Delivered => [self::Refunded],
            self::Cancelled, self::Refunded => [],
        };
    }
}
