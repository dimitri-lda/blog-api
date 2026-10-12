<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

final class PlaceOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email'], 'phone' => ['required', 'string', 'max:40'], 'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'], 'line1' => ['required', 'string', 'max:180'], 'line2' => ['nullable', 'string', 'max:180'], 'city' => ['required', 'string', 'max:80'], 'postal_code' => ['required', 'string', 'max:20'], 'country' => ['required', 'in:'.implode(',', array_keys(config('commerce.country_names')))], 'delivery_method' => ['required', 'in:standard,express']];
    }
}
