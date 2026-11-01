<?php

namespace App\Http\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['gold', 'rdpu'])],
            'name' => ['required', 'string', 'max:160'],
            'provider' => ['nullable', 'string', 'max:160'],
            'identifier' => ['nullable', 'string', 'max:100'],
            'storage_type' => ['nullable', Rule::in(['physical', 'digital'])],
        ];
    }
}
