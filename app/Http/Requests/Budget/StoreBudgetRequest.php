<?php

namespace App\Http\Requests\Budget;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')->where('user_id', $this->user()->id),
            ],
            'amount_limit' => ['required', 'numeric', 'gt:0'],
            'period' => ['nullable', Rule::in(['monthly', 'yearly'])],
            'start_date' => ['required', 'date'],
        ];
    }
}
