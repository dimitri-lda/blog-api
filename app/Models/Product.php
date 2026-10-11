<?php

namespace App\Models;

use App\Services\TranslationCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'brand_id', 'manufacturer_id', 'category_id', 'name', 'name_translations', 'slug',
        'description', 'description_translations', 'price_cents_net', 'compare_at_price_cents_net',
        'brand', 'image_url', 'featured', 'active',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $product) {
            validator($product->getAttributes(), [
                'category_id' => ['required', 'exists:categories,id'],
                'brand_id' => ['nullable', 'exists:brands,id'],
                'manufacturer_id' => ['nullable', 'exists:manufacturers,id'],
            ])->validate();
        });
    }

    public function brandRecord(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function specifications(): HasMany
    {
        return $this->hasMany(ProductSpecification::class)->orderBy('position')->orderBy('id');
    }

    public function getAvailableAttribute(): bool
    {
        return $this->active && ($this->relationLoaded('variants')
            ? $this->variants->contains(fn ($variant) => $variant->stock > 0)
            : $this->variants()->where('stock', '>', 0)->exists());
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'featured' => 'boolean', 'active' => 'boolean',
            'name_translations' => 'array', 'description_translations' => 'array',
        ];
    }

    public function getLocalizedNameAttribute(): string
    {
        return app(TranslationCatalog::class)->find("catalog.products.{$this->slug}.name")
            ?? $this->name_translations[app()->getLocale()]
            ?? $this->name;
    }

    public function getLocalizedDescriptionAttribute(): string
    {
        return app(TranslationCatalog::class)->find("catalog.products.{$this->slug}.description")
            ?? $this->description_translations[app()->getLocale()]
            ?? $this->description;
    }

    protected $appends = ['localized_name', 'localized_description', 'available'];
}
