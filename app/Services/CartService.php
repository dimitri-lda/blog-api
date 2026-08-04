<?php

namespace App\Services;

use App\Domain\Orders\Entities\OrderLine;
use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CartService
{
    public function __construct(private readonly PricingService $pricing) {}

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

    public function data(Cart $cart, ?array $context = null): array
    {
        $items = $cart->items->map(function ($item) {
            $price = $item->variant->price_cents_net ?? $item->variant->product->price_cents_net;

            return ['id' => $item->id, 'quantity' => $item->quantity, 'variant_id' => $item->variant_id, 'variant_name' => $item->variant->localized_name, 'name' => $item->variant->product->localized_name, 'image_url' => $item->variant->product->image_url, 'unit_price_cents' => $price, 'line_total_cents' => $price * $item->quantity];
        });

        $data = ['items' => $items->values(), 'subtotal_cents' => $items->sum('line_total_cents'), 'count' => $items->sum('quantity')];
        if (! $context || $items->isEmpty()) {
            return $data;
        }
        $rawLines = $items->map(fn ($item) => new OrderLine($item['variant_id'], $item['name'], $item['variant_name'], $item['unit_price_cents'], $item['quantity']))->all();
        try {
            $quote = $this->pricing->quote($rawLines, $context, DeliveryMethod::Standard);
            $data['items'] = collect($quote['lines'])->map(fn (OrderLine $line, $index) => array_merge($items[$index], ['net_unit_price_cents' => $line->netUnitPriceCents, 'unit_price_cents' => $line->unitPriceCents, 'tax_cents' => $line->taxCents, 'line_total_cents' => $line->totalCents()]))->values();
            $data = array_merge($data, array_diff_key($quote, ['lines' => true]));
        } catch (\DomainException $e) {
            $data['pricing_error'] = $e->getMessage();
        }

        return $data;
    }
}
