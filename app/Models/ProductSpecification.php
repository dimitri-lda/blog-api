<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSpecification extends Model
{
    protected $fillable = ['product_id', 'name', 'value', 'unit', 'position'];

    protected static function booted(): void
    {
        static::saving(function (self $parameter) {
            $parameter->name = trim((string) $parameter->name);
            $parameter->value = trim((string) $parameter->value);
            validator($parameter->getAttributes(), [
                'product_id' => ['required', 'exists:products,id'],
                'name' => ['required', 'string', 'max:255'],
                'value' => ['required', 'string', 'max:255'],
                'unit' => ['nullable', 'string', 'max:255'],
                'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            ])->validate();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
