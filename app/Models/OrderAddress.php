<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderAddress extends Model { protected $fillable=['order_id','first_name','last_name','line1','line2','city','postal_code','country']; }
