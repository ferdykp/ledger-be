<?php

namespace App\Http\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetAllocationRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,8', 'max:999999999.99999999'],
            'action' => ['required', Rule::in(['allocate', 'release'])],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ];
    }
}
