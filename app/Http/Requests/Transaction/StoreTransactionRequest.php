<?php

namespace App\Http\Requests\Transaction;

use App\Support\TransactionRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return TransactionRules::forUser($this->user(), $this->input('type'));
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
