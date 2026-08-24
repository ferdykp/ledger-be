<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    /**
     * Pastikan akun yang akan di-update milik user yang sedang login.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');
        return $account && $account->user_id === $this->user()->id;
    }

    /**
     * Aturan validasi pembaruan akun.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in(['cash', 'bank', 'ewallet', 'credit_card'])],
            'balance' => ['sometimes', 'numeric'],
            'color' => ['nullable', 'string', 'regex:/^#([a-fA-F0-9]{6})$/'],
            'icon' => ['nullable', 'string', 'max:255'],
            'is_archived' => ['nullable', 'boolean'],
        ];
    }
}
