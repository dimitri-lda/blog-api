<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'product_variant_id', 'name', 'variant_name', 'unit_price_cents', 'quantity', 'line_total_cents'];
}
