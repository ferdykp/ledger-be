<?php

namespace App\Http\Requests\Goal;

use Illuminate\Foundation\Http\FormRequest;

class StoreGoalItemRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,4', 'max:999999999999.9999'],
            'unit_price' => ['required', 'numeric', 'gte:0', 'decimal:0,2', 'max:9999999999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
