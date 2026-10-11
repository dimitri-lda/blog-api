<?php

namespace App\Models;

use App\Domain\Payments\PaymentSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentSession extends Model
{
    protected $fillable = [
        'order_id', 'provider', 'source_identifier', 'session_token', 'session_token_hash',
        'checkout_url', 'receipt_token', 'status', 'provider_order_id', 'last_error', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'session_token' => 'encrypted',
            'receipt_token' => 'encrypted',
            'status' => PaymentSessionStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }
}
