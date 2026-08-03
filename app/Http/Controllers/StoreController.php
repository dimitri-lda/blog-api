<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public function home(): Response
    {
        return Inertia::render('Store/Home', [
            'categories' => Category::whereNull('parent_id')->withCount('products')->get(),
            'featured' => Product::with(['category', 'variants'])->where('featured', true)->where('active', true)->take(8)->get(),
        ]);
    }

    public function catalog(Request $request): Response
    {
        $query = Product::with(['category', 'variants'])->where('active', true);
        if ($request->filled('q')) $query->where(fn ($q) => $q->where('name', 'like', '%'.$request->q.'%')->orWhere('brand', 'like', '%'.$request->q.'%'));
        if ($request->filled('category')) $query->whereHas('category', fn ($q) => $q->where('slug', $request->category));
        if ($request->get('sort') === 'price_asc') $query->orderBy('price_cents');
        elseif ($request->get('sort') === 'price_desc') $query->orderByDesc('price_cents');
        else $query->latest();
        return Inertia::render('Store/Catalog', [
            'products' => $query->paginate(12)->withQueryString(),
            'categories' => Category::whereNull('parent_id')->get(),
            'filters' => $request->only(['q', 'category', 'sort']),
        ]);
    }

    public function product(Product $product): Response
    {
        abort_unless($product->active, 404);
        return Inertia::render('Store/Product', ['product' => $product->load(['category', 'variants', 'images']), 'related' => Product::where('category_id', $product->category_id)->where('id', '!=', $product->id)->take(4)->get()]);
    }

    public function cart(Request $request): Response
    {
        $cart = $this->cartFor($request)->load('items.variant.product');
        return Inertia::render('Store/Cart', ['cart' => $this->cartData($cart)]);
    }

    public function addToCart(Request $request, ProductVariant $variant): RedirectResponse
    {
        abort_unless($variant->product->active, 404);
        $data = $request->validate(['quantity' => ['nullable', 'integer', 'min:1', 'max:20']]);
        $cart = $this->cartFor($request);
        $item = $cart->items()->firstOrNew(['product_variant_id' => $variant->id]);
        $item->quantity = min($variant->stock, ($item->quantity ?? 0) + ($data['quantity'] ?? 1));
        abort_if($item->quantity < 1, 422, 'This product is out of stock.');
        $item->save();
        return back()->with('success', 'Added to your bag.');
    }

    public function updateCart(Request $request): RedirectResponse
    {
        $data = $request->validate(['items' => ['array'], 'items.*.id' => ['integer'], 'items.*.quantity' => ['integer', 'min:0', 'max:20']]);
        $cart = $this->cartFor($request);
        foreach ($data['items'] ?? [] as $itemData) {
            $item = $cart->items()->with('variant')->find($itemData['id']);
            if (!$item) continue;
            if ($itemData['quantity'] === 0) $item->delete();
            else { $item->quantity = min($item->variant->stock, $itemData['quantity']); $item->save(); }
        }
        return back();
    }

    public function checkout(Request $request): Response
    {
        $cart = $this->cartFor($request)->load('items.variant.product');
        if ($cart->items->isEmpty()) return Inertia::render('Store/Cart', ['cart' => $this->cartData($cart)]);
        return Inertia::render('Store/Checkout', ['cart' => $this->cartData($cart), 'user' => $request->user()?->only(['name', 'email'])]);
    }

    public function placeOrder(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'phone' => ['required', 'string', 'max:40'], 'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'], 'line1' => ['required', 'string', 'max:180'], 'line2' => ['nullable', 'string', 'max:180'], 'city' => ['required', 'string', 'max:80'], 'postal_code' => ['required', 'string', 'max:20'], 'country' => ['required', 'size:2'], 'delivery_method' => ['required', 'in:standard,express']]);
        $cart = $this->cartFor($request)->load('items.variant.product'); abort_if($cart->items->isEmpty(), 422, 'Your bag is empty.');
        $subtotal = $cart->items->sum(fn ($item) => ($item->variant->price_cents ?? $item->variant->product->price_cents) * $item->quantity);
        $delivery = $data['delivery_method'] === 'express' ? 1290 : ($subtotal >= 5000 ? 0 : 590);
        $order = Order::create(['user_id' => $request->user()?->id, 'number' => 'SP-'.strtoupper(Str::random(8)), 'email' => $data['email'], 'phone' => $data['phone'], 'status' => 'pending_payment', 'delivery_method' => $data['delivery_method'], 'subtotal_cents' => $subtotal, 'delivery_cents' => $delivery, 'total_cents' => $subtotal + $delivery, 'currency' => 'EUR']);
        $order->address()->create(collect($data)->only(['first_name','last_name','line1','line2','city','postal_code','country'])->all());
        foreach ($cart->items as $item) { $price = $item->variant->price_cents ?? $item->variant->product->price_cents; $order->items()->create(['product_variant_id' => $item->variant_id, 'name' => $item->variant->product->name, 'variant_name' => $item->variant->name, 'unit_price_cents' => $price, 'quantity' => $item->quantity, 'line_total_cents' => $price * $item->quantity]); }
        $cart->items()->delete();
        $request->session()->put('last_order', $order->number);
        return redirect()->route('store.order', $order)->with('success', 'Your order has been placed.');
    }

    public function order(Request $request, Order $order): Response
    { abort_unless($request->user()?->id === $order->user_id || $request->session()->get('last_order') === $order->number, 404); return Inertia::render('Store/Order', ['order' => $order->load(['items', 'address'])]); }

    private function cartFor(Request $request): Cart
    {
        if ($request->user()) return Cart::firstOrCreate(['user_id' => $request->user()->id]);
        $token = $request->session()->get('cart_token');
        $cart = $token ? Cart::where('token', $token)->first() : null;
        if (!$cart) { $cart = Cart::create(['token' => (string) Str::uuid()]); $request->session()->put('cart_token', $cart->token); }
        return $cart;
    }
    private function cartData(Cart $cart): array
    {
        $items = $cart->items->map(function ($item) { $price = $item->variant->price_cents ?? $item->variant->product->price_cents; return ['id' => $item->id, 'quantity' => $item->quantity, 'variant_id' => $item->variant_id, 'variant_name' => $item->variant->name, 'name' => $item->variant->product->name, 'image_url' => $item->variant->product->image_url, 'unit_price_cents' => $price, 'line_total_cents' => $price * $item->quantity]; });
        return ['items' => $items->values(), 'subtotal_cents' => $items->sum('line_total_cents'), 'count' => $items->sum('quantity')];
    }
}
