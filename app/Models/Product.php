<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Product extends Model { protected $fillable=['category_id','name','slug','description','price_cents','compare_at_price_cents','brand','image_url','featured','active']; protected function casts(): array { return ['featured'=>'boolean','active'=>'boolean']; } public function category(): BelongsTo { return $this->belongsTo(Category::class); } public function variants(): HasMany { return $this->hasMany(ProductVariant::class); } public function images(): HasMany { return $this->hasMany(ProductImage::class); } }
