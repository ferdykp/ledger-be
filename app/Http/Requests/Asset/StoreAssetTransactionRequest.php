<?php

namespace App\Http\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetTransactionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['purchase', 'subscription', 'sale', 'redemption'])],
            'status' => ['nullable', Rule::in(['pending', 'confirmed', 'settled'])],
            'cash_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)],
            'amount' => ['required', 'numeric', 'gte:0', 'decimal:0,2', 'max:9999999999999.99'],
            'quantity' => [Rule::requiredIf(fn () => $this->route('asset') instanceof \App\Models\Asset && ($this->route('asset')->type === 'gold' || ($this->route('asset')->type === 'rdpu' && ($this->input('type') === 'redemption' || $this->input('status') === 'confirmed')))), 'nullable', 'numeric', 'gt:0', 'decimal:0,8', 'max:999999999.99999999'],
            'unit_price' => ['nullable', 'numeric', 'gt:0', 'decimal:0,8', 'max:9999999999999999.99999999'],
            'price_unit_grams' => ['nullable', 'numeric', 'gt:0', 'decimal:0,8', 'max:99999999.99999999'],
            'fees' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2', 'max:9999999999999.99'],
            'cash_debited' => ['nullable', 'boolean'],
            'transaction_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ];
    }
}
