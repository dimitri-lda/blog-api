<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

final class ShopPaySubmitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'session_token' => ['required', 'string', 'max:255'],
            'payment_method' => ['required', 'string', 'max:10000'],
        ];
    }
}
