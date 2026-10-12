<?php

namespace App\Models;

use App\Domain\Payments\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    protected $fillable = [
        'payment_session_id', 'payment_method_hash', 'amount_cents', 'currency',
        'idempotency_key', 'payload', 'receipt_token', 'status', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'receipt_token' => 'encrypted',
            'amount_cents' => 'integer',
            'status' => PaymentAttemptStatus::class,
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class, 'payment_session_id');
    }
}
