<?php

namespace App\Http\Requests\Tech\CS;

use Illuminate\Foundation\Http\FormRequest;

class termsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'  => ['required'],
            'type' => ['required'],
            'content'  => ['required'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
