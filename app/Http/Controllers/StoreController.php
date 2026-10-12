<?php

namespace App\Http\Controllers;

use App\Application\Orders\PlaceOrder;
use App\Domain\Orders\ValueObjects\OrderStatus;
use App\Http\Requests\Store\PlaceOrderRequest;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Catalog\StorefrontCatalog;
use App\Services\PricingService;
use App\Services\ShopPay\ShopPayConfiguration;
use App\Services\ShopPay\ShopPayPaymentRequestFactory;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public function home(Request $request, PricingService $pricing, StorefrontCatalog $catalog): Response
    {
        $market = $request->attributes->get('market');

        return Inertia::render('Store/Home', [
            'categories' => array_values(array_filter(
                $catalog->categories(),
                fn (array $category) => $category['parent_id'] === null,
            )),
            'featured' => array_map(
                fn (array $product) => $this->pricedArray($product, $pricing, $market),
                $catalog->featured(),
            ),
        ]);
    }

    public function catalog(Request $request, PricingService $pricing, StorefrontCatalog $catalog): Response
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:price_asc,price_desc'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = max(1, (int) $request->query('page', 1));
        $result = $catalog->page([
            'q' => $request->query('q'),
            'category' => $request->query('category'),
            'sort' => $request->query('sort'),
        ], $page, 12);
        $market = $request->attributes->get('market');
        $items = array_map(
            fn (array $product) => $this->pricedArray($product, $pricing, $market),
            $result['data'],
        );
        $products = (new LengthAwarePaginator($items, $result['total'], 12, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]))->withQueryString();

        return Inertia::render('Store/Catalog', [
            'products' => $products,
            'categories' => $catalog->categories(),
            'filters' => $request->only(['q', 'category', 'sort']),
        ]);
    }

    public function product(
        Product $product,
        Request $request,
        PricingService $pricing,
        StorefrontCatalog $catalog,
    ): Response {
        abort_unless($product->active, 404);
        $market = $request->attributes->get('market');
        $detail = $catalog->detail($product);

        return Inertia::render('Store/Product', [
            'product' => $this->pricedArray($detail['product'], $pricing, $market),
            'breadcrumbs' => $detail['breadcrumbs'],
            'related' => array_map(
                fn (array $related) => $this->pricedArray($related, $pricing, $market),
                $detail['related'],
            ),
        ]);
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
            $order = $placeOrder->handle(
                $request->validated(),
                $request->user()?->id,
                $request->session()->get('cart_token'),
                $request->attributes->get('market'),
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['cart' => $exception->getMessage()]);
        }
        $request->session()->put('last_order', $order->number);

        return redirect()->route('store.order', ['order' => $order->number])
            ->with('success', 'Your order has been placed.');
    }

    public function order(Request $request, Order $order): Response
    {
        abort_unless(
            ($request->user() && $request->user()->id === $order->user_id)
                || $request->session()->get('last_order') === $order->number,
            404,
        );

        return Inertia::render('Store/Order', [
            'order' => $order->load(['items', 'address']),
            'shopPay' => fn () => $order->status === OrderStatus::PendingPayment
                ? [
                    'config' => app(ShopPayConfiguration::class)->publicConfig(),
                    'state' => $order->paymentSession?->status?->value,
                    'paymentRequest' => app(ShopPayPaymentRequestFactory::class)->frontend($order),
                ]
                : null,
        ]);
    }

    /**
     * Applies request-specific pricing on top of a cached card/detail payload.
     * display_price and pricing_error are always computed per request and
     * must never enter the shared cache.
     */
    private function pricedArray(array $product, PricingService $pricing, array $market): array
    {
        try {
            $product['display_price'] = $pricing->displayPrice($product['price_cents_net'], $market);
            foreach ($product['variants'] ?? [] as $index => $variant) {
                $product['variants'][$index]['display_price'] = $pricing->displayPrice(
                    $variant['price_cents_net'] ?? $product['price_cents_net'], $market,
                );
            }
        } catch (DomainException $e) {
            $product['pricing_error'] = $e->getMessage();
        }

        return $product;
    }
}
