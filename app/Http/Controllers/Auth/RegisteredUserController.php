<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'accepted_terms' => ['accepted'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'accepted_terms' => true,
            'terms_accepted_at' => now(),
        ]);

        event(new Registered($user));

        Auth::login($user);
        $this->mergeGuestCart($request, $user->id);

        return redirect()->intended(route('store.home', absolute: false));
    }

    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    private function mergeGuestCart(Request $request, int $userId): void
    {
        $token = $request->session()->get('cart_token');
        $guest = $token ? Cart::with('items')->where('token', $token)->first() : null;
        if (!$guest) return;
        $cart = Cart::firstOrCreate(['user_id' => $userId]);
        foreach ($guest->items as $item) $cart->items()->updateOrCreate(['product_variant_id' => $item->product_variant_id], ['quantity' => $item->quantity]);
        $guest->delete();
        $request->session()->forget('cart_token');
    }
}
