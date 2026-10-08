<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rule;

class TransactionRules
{
    public static function forUser(User $user, mixed $type): array
    {
        $type = is_string($type) ? $type : null;

        return [
            'type' => ['required', Rule::in(['income', 'expense', 'transfer'])],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $user->id)],
            'to_account_id' => ['exclude_unless:type,transfer', 'nullable', 'required_if:type,transfer', 'integer', 'different:account_id', Rule::exists('accounts', 'id')->where('user_id', $user->id)],
            'category_id' => ['exclude_if:type,transfer', 'nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $user->id)->where('type', $type)],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
