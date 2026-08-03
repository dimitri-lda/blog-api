<?php

namespace App\Http\Requests\Admin;

use App\Domain\Orders\ValueObjects\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ChangeOrderStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array { return ['status' => ['required', new Enum(OrderStatus::class)]]; }
}
