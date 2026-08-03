<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;

Route::get('/', [StoreController::class, 'home'])->name('store.home');
Route::get('/shop', [StoreController::class, 'catalog'])->name('store.catalog');
Route::get('/shop/{product:slug}', [StoreController::class, 'product'])->name('store.product');
Route::get('/cart', [StoreController::class, 'cart'])->name('store.cart');
Route::post('/cart/items/{variant}', [StoreController::class, 'addToCart'])->name('store.cart.add');
Route::patch('/cart', [StoreController::class, 'updateCart'])->name('store.cart.update');
Route::get('/checkout', [StoreController::class, 'checkout'])->name('store.checkout');
Route::post('/checkout', [StoreController::class, 'placeOrder'])->name('store.checkout.place');
Route::get('/orders/{order:number}', [StoreController::class, 'order'])->name('store.order');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard', ['orders' => \App\Models\Order::where('user_id', request()->user()->id)->with('items')->latest()->get()]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
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
