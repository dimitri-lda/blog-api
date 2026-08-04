<?php

namespace App\Models;

use App\Services\TranslationCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['category_id', 'name', 'name_translations', 'slug', 'description', 'description_translations', 'price_cents_net', 'compare_at_price_cents_net', 'brand', 'image_url', 'featured', 'active'];

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
        return $this->hasMany(ProductImage::class);
    }

    protected function casts(): array
    {
        return ['featured' => 'boolean', 'active' => 'boolean', 'name_translations' => 'array', 'description_translations' => 'array'];
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

    protected $appends = ['localized_name', 'localized_description'];
}
