<?php

namespace App\Models;

use App\Services\TranslationCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Category extends Model
{
    protected $fillable = ['parent_id', 'name', 'name_translations', 'slug', 'image_url'];

    protected static function booted(): void
    {
        static::saving(function (self $category) {
            validator($category->getAttributes(), [
                'name' => ['required', 'string', 'max:255'],
                'slug' => ['required', 'string', 'max:255', Rule::unique('categories')->ignore($category->id)],
                'parent_id' => ['nullable', 'exists:categories,id'],
            ])->validate();
            $seen = $category->exists ? [$category->id] : [];
            $parent = $category->parent_id;
            while ($parent !== null) {
                if (in_array($parent, $seen)) {
                    throw ValidationException::withMessages(['parent_id' => 'Kategorie nie mogą tworzyć cyklu.']);
                }
                $seen[] = $parent;
                $parent = self::whereKey($parent)->value('parent_id');
            }
        });
        static::deleting(function (self $category) {
            if ($category->products()->exists() || $category->children()->exists()) {
                throw ValidationException::withMessages(['category' => 'Najpierw przenieś produkty i podkategorie.']);
            }
        });
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

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
        return app(TranslationCatalog::class)->find("catalog.categories.{$this->slug}.name")
            ?? $this->name_translations[app()->getLocale()]
            ?? $this->name;
    }

    protected $appends = ['localized_name'];
}
