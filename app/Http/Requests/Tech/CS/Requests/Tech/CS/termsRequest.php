<?php

namespace App\Http\Requests\Tech\CS\Requests\Tech\CS;

use Illuminate\Foundation\Http\FormRequest;

class termsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'  => ['required'],
            'term'  => ['nullable', 'required_without:legal'],
            'legal' => ['nullable', 'required_without:term'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
