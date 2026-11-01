<?php

namespace App\Http\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['confirmed', 'settled', 'rejected', 'cancelled'])],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'decimal:0,8', 'max:999999999.99999999'],
            'unit_price' => ['nullable', 'numeric', 'gt:0', 'decimal:0,8'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'fees' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2'],
            'cash_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)],
            'cash_debited' => ['nullable', 'boolean'],
            'refund_received' => ['nullable', 'boolean'],
            'refund_amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
        ];
    }
}
