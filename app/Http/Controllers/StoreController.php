<?php

namespace App\Http\Controllers;

use App\Application\Orders\PlaceOrder;
use App\Http\Requests\Store\PlaceOrderRequest;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use App\Services\PricingService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public function home(Request $request, PricingService $pricing): Response
    {
        return Inertia::render('Store/Home', [
            'categories' => Category::whereNull('parent_id')->withCount('products')->get(),
            'featured' => Product::with(['category', 'variants'])->where('featured', true)->where('active', true)->take(8)->get()->map(fn (Product $product) => $this->priced($product, $pricing, $request->attributes->get('market'))),
        ]);
    }

    public function catalog(Request $request, PricingService $pricing): Response
    {
        $query = Product::with(['category', 'variants'])->where('active', true);
        if ($request->filled('q')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$request->q.'%')->orWhere('brand', 'like', '%'.$request->q.'%'));
        }
        if ($request->filled('category')) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $request->category));
        }
        if ($request->get('sort') === 'price_asc') {
            $query->orderBy('price_cents_net');
        } elseif ($request->get('sort') === 'price_desc') {
            $query->orderByDesc('price_cents_net');
        } else {
            $query->latest();
        }

        return Inertia::render('Store/Catalog', [
            'products' => tap($query->paginate(12)->withQueryString(), fn ($products) => $products->getCollection()->transform(fn (Product $product) => $this->priced($product, $pricing, $request->attributes->get('market')))),
            'categories' => Category::whereNull('parent_id')->get(),
            'filters' => $request->only(['q', 'category', 'sort']),
        ]);
    }

    public function product(Product $product, Request $request, PricingService $pricing): Response
    {
        abort_unless($product->active, 404);

        return Inertia::render('Store/Product', ['product' => $this->priced($product->load(['category', 'variants', 'images']), $pricing, $request->attributes->get('market')), 'related' => Product::where('category_id', $product->category_id)->where('id', '!=', $product->id)->take(4)->get()->map(fn (Product $related) => $this->priced($related, $pricing, $request->attributes->get('market')))]);
    }

    public function checkout(Request $request, CartService $carts): Response
    {
        $cart = $carts->forRequest($request)->load('items.variant.product');
        if ($cart->items->isEmpty()) {
            return Inertia::render('Store/Cart', ['cart' => $carts->data($cart, $request->attributes->get('market'))]);
        }

        return Inertia::render('Store/Checkout', [
            'cart' => $carts->data($cart, $request->attributes->get('market')),
            'user' => $request->user()?->only(['name', 'email']),
            'address' => $request->user()?->savedAddress,
        ]);
    }

    public function placeOrder(PlaceOrderRequest $request, PlaceOrder $placeOrder): RedirectResponse
    {
        try {
            $order = $placeOrder->handle($request->validated(), $request->user()?->id, $request->session()->get('cart_token'), $request->attributes->get('market'));
        } catch (DomainException $exception) {
            return back()->withErrors(['cart' => $exception->getMessage()]);
        }
        $request->session()->put('last_order', $order->number);

        return redirect()->route('store.order', ['order' => $order->number])->with('success', 'Your order has been placed.');
    }

    public function order(Request $request, Order $order): Response
    {
        abort_unless($request->user()?->id === $order->user_id || $request->session()->get('last_order') === $order->number, 404);

        return Inertia::render('Store/Order', ['order' => $order->load(['items', 'address'])]);
    }

    private function priced(Product $product, PricingService $pricing, array $market): Product
    {
        try {
            $product->setAttribute('display_price', $pricing->displayPrice($product->price_cents_net, $market));
            $product->variants->each(function ($variant) use ($product, $pricing, $market) {
                $variant->setAttribute('display_price', $pricing->displayPrice($variant->price_cents_net ?? $product->price_cents_net, $market));
            });
        } catch (DomainException $e) {
            $product->setAttribute('pricing_error', $e->getMessage());
        }

        return $product;
    }
}
