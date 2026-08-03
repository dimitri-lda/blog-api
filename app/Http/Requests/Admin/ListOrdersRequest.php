<?php

namespace App\Http\Requests\Admin;

use App\Domain\Orders\ValueObjects\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', new Enum(OrderStatus::class)], 'page' => ['nullable', 'integer', 'min:1']];
    }
}
