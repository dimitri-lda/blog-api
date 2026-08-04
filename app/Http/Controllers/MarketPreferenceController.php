<?php

namespace App\Http\Controllers;

use App\Services\MarketContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MarketPreferenceController extends Controller
{
    public function update(Request $request, MarketContext $market): RedirectResponse
    {
        $countries = array_keys(config('commerce.country_names'));
        $data = $request->validate(['country' => ['required', Rule::in($countries)], 'locale' => ['nullable', Rule::in(['en', 'ru', 'pl'])]]);
        $context = $market->set($request, $data['country'], $data['locale'] ?? null);
        app()->setLocale($context['locale']);

        return back()->with('success', __('store.preferences_updated'));
    }
}
