<?php

namespace App\Http\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;

class CompleteRdpuUnitsRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,8', 'max:999999999.99999999'],
            'current_value' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'nav' => ['nullable', 'numeric', 'gt:0', 'decimal:0,8', 'max:9999999999999999.99999999'],
            'cost_basis' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'captured_at' => ['required', 'date'],
        ];
    }
}
