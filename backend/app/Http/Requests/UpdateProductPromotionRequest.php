<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProductPromotionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
            'discount_type' => ['required', 'string', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'starts_at' => ['nullable', 'string', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{3}-05:00$/'],
            'ends_at' => ['nullable', 'string', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{3}-05:00$/'],
            'expected_revision' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
