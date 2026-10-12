<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

final class ShopPayQuoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'session_token' => ['required', 'string', 'max:255'],
            'shipping_address.first_name' => ['nullable', 'string', 'max:80'],
            'shipping_address.last_name' => ['required', 'string', 'max:80'],
            'shipping_address.line1' => ['required', 'string', 'max:180'],
            'shipping_address.line2' => ['nullable', 'string', 'max:180'],
            'shipping_address.city' => ['required', 'string', 'max:80'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:20'],
            'shipping_address.country' => ['required', 'string', 'size:2'],
            'delivery_method' => ['required', 'in:standard,express'],
        ];
    }
}
