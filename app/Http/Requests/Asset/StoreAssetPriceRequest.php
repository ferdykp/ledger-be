<?php

namespace App\Http\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetPriceRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'price' => ['required', 'numeric', 'gt:0', 'decimal:0,8', 'max:9999999999999999.99999999'],
            'captured_at' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:120'],
        ];
    }
}
