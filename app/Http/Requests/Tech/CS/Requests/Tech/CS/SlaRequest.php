<?php

namespace App\Http\Requests\Tech\CS\Requests\Tech\CS;

use Illuminate\Foundation\Http\FormRequest;

class SlaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required'],
            'description' => ['required'],

            'low_firstResponse' => ['required', 'integer'],
            'low_firstResponse_type' => ['required'],
            'low_onsite' => ['required', 'integer'],
            'low_onsite_type' => ['required'],

            'medium_firstResponse' => ['required', 'integer'],
            'medium_firstResponse_type' => ['required'],
            'medium_onsite' => ['required', 'integer'],
            'medium_onsite_type' => ['required'],

            'high_firstResponse' => ['required', 'integer'],
            'high_firstResponse_type' => ['required'],
            'high_onsite' => ['required', 'integer'],
            'high_onsite_type' => ['required'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
