<?php

namespace App\Models;

use App\Domain\Orders\ValueObjects\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = ['user_id', 'number', 'email', 'phone', 'status', 'delivery_method', 'subtotal_cents', 'delivery_cents', 'total_cents', 'currency', 'market', 'locale', 'base_currency', 'exchange_rate', 'vat_rate_basis_points', 'net_subtotal_cents', 'tax_cents', 'delivery_net_cents', 'delivery_tax_cents'];

    protected function casts(): array
    {
        return ['status' => OrderStatus::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function address()
    {
        return $this->hasOne(OrderAddress::class);
    }
}
