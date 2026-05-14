<?php

namespace App\Modules\Commercial\Requests;

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
