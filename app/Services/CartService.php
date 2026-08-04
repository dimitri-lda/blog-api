<?php

namespace App\Services;

use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CartService
{
    public function forRequest(Request $request): Cart
    {
        if ($request->user()) {
            return Cart::firstOrCreate(['user_id' => $request->user()->id]);
        }

        $token = $request->session()->get('cart_token');
        $cart = $token ? Cart::where('token', $token)->first() : null;

        if (! $cart) {
            $cart = Cart::create(['token' => (string) Str::uuid()]);
            $request->session()->put('cart_token', $cart->token);
        }

        return $cart;
    }

    public function data(Cart $cart): array
    {
        $items = $cart->items->map(function ($item) {
            $price = $item->variant->price_cents ?? $item->variant->product->price_cents;

            return ['id' => $item->id, 'quantity' => $item->quantity, 'variant_id' => $item->variant_id, 'variant_name' => $item->variant->name, 'name' => $item->variant->product->name, 'image_url' => $item->variant->product->image_url, 'unit_price_cents' => $price, 'line_total_cents' => $price * $item->quantity];
        });

        return ['items' => $items->values(), 'subtotal_cents' => $items->sum('line_total_cents'), 'count' => $items->sum('quantity')];
    }
}
