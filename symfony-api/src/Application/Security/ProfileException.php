<?php

namespace App\Application\Security;

use DomainException;

final class ProfileException extends DomainException
{
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
