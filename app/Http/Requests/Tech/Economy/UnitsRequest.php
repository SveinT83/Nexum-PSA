<?php

namespace App\Http\Requests\Tech\Economy;

use Illuminate\Foundation\Http\FormRequest;

class UnitsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'short' => ['nullable', 'string'],
            'common_code' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
