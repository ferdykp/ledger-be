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
            'type' => ['required', Rule::in(['gold', 'rdpu', 'deposit'])],
            'name' => ['required', 'string', 'max:160'],
            'provider' => ['nullable', 'string', 'max:160'],
            'identifier' => ['nullable', 'string', 'max:100'],
            'storage_type' => ['nullable', Rule::in(['physical', 'digital'])],
            'start_date' => [Rule::requiredIf(fn () => $this->input('type') === 'deposit'), 'nullable', 'date'],
            'maturity_date' => [Rule::requiredIf(fn () => $this->input('type') === 'deposit'), 'nullable', 'date', 'after_or_equal:start_date'],
            'ownership_source' => ['nullable', Rule::in(['existing', 'new'])],
            'opening_mode' => ['nullable', Rule::in(['units', 'value_only'])],
            'initial_quantity' => [Rule::requiredIf(fn () => $this->input('ownership_source') === 'existing' && ($this->input('type') === 'gold' || ($this->input('type') === 'rdpu' && $this->input('opening_mode', 'units') === 'units'))), 'nullable', 'numeric', 'gt:0', 'decimal:0,8', 'max:999999999.99999999'],
            'initial_cost_basis' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2', 'max:9999999999999.99'],
            'initial_current_value' => [Rule::requiredIf(fn () => $this->input('ownership_source') === 'existing' && in_array($this->input('type'), ['rdpu', 'deposit'], true) && ($this->input('type') === 'deposit' || $this->input('opening_mode') === 'value_only')), 'nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'initial_nav' => ['nullable', 'numeric', 'gt:0', 'decimal:0,8', 'max:9999999999999999.99999999'],
            'initial_price' => [Rule::requiredIf(fn () => $this->input('ownership_source') === 'existing' && $this->input('type') === 'gold'), 'nullable', 'numeric', 'gt:0', 'decimal:0,8', 'max:9999999999999999.99999999'],
            'initial_date' => ['required_if:ownership_source,existing', 'nullable', 'date'],
            'purchase_amount' => [Rule::requiredIf(fn () => $this->input('ownership_source') === 'new'), 'nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'purchase_quantity' => [Rule::requiredIf(fn () => $this->input('ownership_source') === 'new' && $this->input('type') === 'gold'), 'nullable', 'numeric', 'gt:0', 'decimal:0,8', 'max:999999999.99999999'],
            'purchase_status' => ['nullable', Rule::in(['pending', 'confirmed'])],
            'purchase_cash_account_id' => [Rule::requiredIf(fn () => $this->input('ownership_source') === 'new' && ($this->input('type') === 'gold' || $this->boolean('purchase_cash_debited', true))), 'nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)],
            'purchase_cash_debited' => ['nullable', 'boolean'],
            'purchase_fees' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2', 'max:9999999999999.99'],
            'purchase_date' => [Rule::requiredIf(fn () => $this->input('ownership_source') === 'new'), 'nullable', 'date'],
        ];
    }
}
