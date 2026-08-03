<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Order extends Model { protected $fillable=['user_id','number','email','phone','status','delivery_method','subtotal_cents','delivery_cents','total_cents','currency']; public function items(): HasMany { return $this->hasMany(OrderItem::class); } public function address() { return $this->hasOne(OrderAddress::class); } }
