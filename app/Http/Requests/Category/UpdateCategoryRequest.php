<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('category') && $this->route('category')->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in(['income', 'expense'])],
            'icon' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'regex:/^#([a-fA-F0-9]{6})$/'],
            'parent_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where('user_id', $this->user()->id),
                Rule::notIn([$this->route('category')?->id]),
            ],
        ];
    }
}
