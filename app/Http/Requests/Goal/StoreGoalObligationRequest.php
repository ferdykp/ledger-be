<?php

namespace App\Http\Requests\Goal;

use Illuminate\Foundation\Http\FormRequest;

class StoreGoalObligationRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'due_date' => ['nullable', 'date'],
            'payee' => ['nullable', 'string', 'max:160'],
            'status' => ['sometimes', 'in:planned,cancelled'],
        ];
    }
}
