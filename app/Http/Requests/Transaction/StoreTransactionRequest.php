<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['income', 'expense', 'transfer'])],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)],
            'to_account_id' => [
                'exclude_unless:type,transfer',
                'nullable',
                'required_if:type,transfer',
                'different:account_id',
                Rule::exists('accounts', 'id')->where('user_id', $this->user()->id),
            ],
            'category_id' => ['exclude_if:type,transfer', 'nullable', Rule::exists('categories', 'id')->where('user_id', $this->user()->id)->where('type', $this->input('type'))],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Aliaskan to_account_id dari Vue menjadi related_account_id untuk DB
        if ($this->has('to_account_id')) {
            $this->merge([
                'related_account_id' => $this->to_account_id,
            ]);
        }
    }
}
