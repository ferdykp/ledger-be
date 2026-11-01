<?php

namespace App\Http\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetRefundRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'received_date' => ['required', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ];
    }
}
