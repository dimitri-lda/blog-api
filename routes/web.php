<?php

use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\MarketPreferenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SavedAddressController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\ShopPayPaymentController;
use App\Http\Controllers\StoreController;
use App\Models\Order;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [StoreController::class, 'home'])->name('store.home');
Route::get('/shop', [StoreController::class, 'catalog'])->name('store.catalog');
Route::get('/shop/{product:slug}', [StoreController::class, 'product'])->name('store.product');
Route::get('/cart', [CartController::class, 'show'])->name('store.cart');
Route::post('/cart/items/{variant}', [CartController::class, 'store'])->name('store.cart.add');
Route::patch('/cart', [CartController::class, 'update'])->name('store.cart.update');
Route::get('/checkout', [StoreController::class, 'checkout'])->name('store.checkout');
Route::post('/checkout', [StoreController::class, 'placeOrder'])->name('store.checkout.place');
Route::get('/orders/{order:number}', [StoreController::class, 'order'])->name('store.order');
Route::middleware('throttle:30,1')->group(function () {
    Route::post('/orders/{order:number}/payments/shop-pay/session', [ShopPayPaymentController::class, 'createSession'])
        ->name('store.order.shop-pay.session');
    Route::patch('/orders/{order:number}/payments/shop-pay/quote', [ShopPayPaymentController::class, 'quote'])
        ->name('store.order.shop-pay.quote');
    Route::post('/orders/{order:number}/payments/shop-pay/submit', [ShopPayPaymentController::class, 'submit'])
        ->name('store.order.shop-pay.submit');
    Route::post('/orders/{order:number}/payments/shop-pay/reconcile', [ShopPayPaymentController::class, 'reconcile'])
        ->name('store.order.shop-pay.reconcile');
});
Route::post('/webhooks/shopify/orders', ShopifyWebhookController::class)->name('webhooks.shopify.orders');
Route::post('/preferences', [MarketPreferenceController::class, 'update'])->name('market.preferences.update');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard', ['orders' => Order::where('user_id', request()->user()->id)->with('items')->latest()->get()]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/address', [SavedAddressController::class, 'update'])->name('profile.address.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

Route::domain(config('admin.domain'))
    ->middleware(['auth', 'admin'])
    ->group(function (): void {
        Route::redirect('/', '/orders')->name('admin.home');
        Route::get('/orders', [AdminOrderController::class, 'index'])->name('admin.orders.index');
        Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->name('admin.orders.show');
        Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus'])->name('admin.orders.status.update');
    });
