<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = ['product_id', 'name', 'name_translations', 'sku', 'price_cents_net', 'stock'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return ['name_translations' => 'array'];
    }

    public function getLocalizedNameAttribute(): string
    {
        return $this->name_translations[app()->getLocale()] ?? $this->name;
    }

    protected $appends = ['localized_name'];
}
