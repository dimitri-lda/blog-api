<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class SavedAddressController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'line1' => ['required', 'string', 'max:180'],
            'line2' => ['nullable', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:80'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
        ]);

        $request->user()->savedAddress()->updateOrCreate([], $data);

        return Redirect::route('profile.edit')->with('success', 'Address saved.');
    }
}
