<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'url', 'alt', 'position'];

    protected static function booted(): void
    {
        static::saving(function (self $image) {
            validator($image->getAttributes(), [
                'product_id' => ['required', 'exists:products,id'],
                'url' => ['required', 'string', 'max:255'],
                'alt' => ['nullable', 'string', 'max:255'],
                'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            ])->validate();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
