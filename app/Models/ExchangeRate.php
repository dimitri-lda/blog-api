<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = ['currency', 'rate', 'source', 'quoted_at'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:8', 'quoted_at' => 'datetime'];
    }
}
