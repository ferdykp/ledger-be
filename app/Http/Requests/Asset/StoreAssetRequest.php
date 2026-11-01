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
            'ownership_source' => ['nullable', Rule::in(['existing', 'new'])],
            'initial_quantity' => [Rule::requiredIf(fn () => $this->input('ownership_source') === 'existing' && $this->input('type') === 'gold'), 'nullable', 'numeric', 'gt:0', 'decimal:0,8', 'max:999999999.99999999'],
            'initial_cost_basis' => ['required_if:ownership_source,existing', 'nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'initial_date' => ['required_if:ownership_source,existing', 'nullable', 'date'],
        ];
    }
}
