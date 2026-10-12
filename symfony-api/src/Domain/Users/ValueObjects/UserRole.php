<?php

namespace App\Domain\Users\ValueObjects;

enum UserRole: string
{
    case User = 'user';
    case Admin = 'admin';
    case Superadmin = 'superadmin';

    public function isAdmin(): bool
    {
        return match ($this) {
            self::Admin, self::Superadmin => true,
            self::User => false,
        };
    }
}
