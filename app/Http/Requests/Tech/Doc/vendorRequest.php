<?php

namespace App\Http\Requests\Tech\Doc;

use Illuminate\Foundation\Http\FormRequest;

class vendorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'Name' => ['required'],
            'url' => ['required'],
            'phone' => ['required'],
            'email' => ['required', 'email', 'max:254'],
            'note' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
