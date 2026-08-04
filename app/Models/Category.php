<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['parent_id', 'name', 'name_translations', 'slug', 'image_url'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
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
