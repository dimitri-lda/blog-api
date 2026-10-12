<?php

namespace App\Application\Security;

interface UserIdentity
{
    public function id(): int;
}
