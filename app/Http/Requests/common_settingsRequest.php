<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class common_settingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required'],
            'type' => ['required'],
            'description' => ['nullable'],
            'value' => ['nullable', 'required_without:json'],
            'json' => ['nullable', 'required_without:value'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
