<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyWebhookDelivery extends Model
{
    protected $fillable = ['delivery_id', 'topic', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
