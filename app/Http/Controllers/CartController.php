<?php

namespace App\Http\Controllers;

use App\Models\ProductVariant;
use App\Services\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function __construct(private CartService $carts) {}

    public function show(Request $request): Response
    {
        $cart = $this->carts->forRequest($request)->load('items.variant.product');

        return Inertia::render('Store/Cart', ['cart' => $this->carts->data($cart)]);
    }

    public function store(Request $request, ProductVariant $variant): RedirectResponse
    {
        abort_unless($variant->product->active, 404);

        $data = $request->validate(['quantity' => ['nullable', 'integer', 'min:1', 'max:20']]);
        $cart = $this->carts->forRequest($request);
        $item = $cart->items()->firstOrNew(['product_variant_id' => $variant->id]);
        $item->quantity = min($variant->stock, ($item->quantity ?? 0) + ($data['quantity'] ?? 1));
        abort_if($item->quantity < 1, 422, 'This product is out of stock.');
        $item->save();

        return back()->with('success', 'Added to your bag.');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['items' => ['array'], 'items.*.id' => ['integer'], 'items.*.quantity' => ['integer', 'min:0', 'max:20']]);
        $cart = $this->carts->forRequest($request);

        foreach ($data['items'] ?? [] as $itemData) {
            $item = $cart->items()->with('variant')->find($itemData['id']);
            if (! $item) {
                continue;
            }

            if ($itemData['quantity'] === 0) {
                $item->delete();
            } else {
                $item->quantity = min($item->variant->stock, $itemData['quantity']);
                $item->save();
            }
        }

        return back();
    }
}
