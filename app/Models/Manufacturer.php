<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Manufacturer extends Model
{
    protected $fillable = ['name', 'slug'];

    protected static function booted(): void
    {
        static::saving(function (self $entry) {
            $entry->name = trim((string) $entry->name);
            validator($entry->getAttributes(), [
                'name' => ['required', 'string', 'max:255'],
                'slug' => [
                    'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                    Rule::unique($entry->getTable())->ignore($entry->id),
                ],
            ])->validate();
        });
        static::deleting(function (self $entry) {
            if ($entry->products()->exists()) {
                throw ValidationException::withMessages(['manufacturer' => 'Najpierw odłącz produkty od producenta.']);
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
