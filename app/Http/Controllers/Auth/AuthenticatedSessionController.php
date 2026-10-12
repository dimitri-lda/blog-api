<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Cart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        $this->mergeGuestCart($request);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function mergeGuestCart(Request $request): void
    {
        $token = $request->session()->get('cart_token');
        $guest = $token ? Cart::with('items')->where('token', $token)->first() : null;
        if (! $guest) {
            return;
        }
        $cart = Cart::firstOrCreate(['user_id' => $request->user()->id]);
        foreach ($guest->items as $item) {
            $cart->items()->updateOrCreate(['product_variant_id' => $item->product_variant_id], ['quantity' => $item->quantity]);
        }
        $guest->delete();
        $request->session()->forget('cart_token');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
