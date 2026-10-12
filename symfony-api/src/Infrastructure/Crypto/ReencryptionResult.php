<?php

namespace App\Infrastructure\Crypto;

final class ReencryptionResult
{
    public int $readable = 0;

    public int $rewritten = 0;

    public int $unreadable = 0;
}
