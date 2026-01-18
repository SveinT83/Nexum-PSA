<?php

namespace App\Http\Requests\Tech\Clients;

use Illuminate\Foundation\Http\FormRequest;

class SiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['nullable','exists:clients,id'],
            'name' => ['required','string','max:255'],
            'address' => ['nullable','string','max:255'],
            'co_address' => ['nullable','string','max:255'],
            'zip' => ['nullable','integer','min:0','max:9999'],
            'city' => ['nullable','string','max:255'],
            'county' => ['nullable','string','max:255'],
            'country' => ['nullable','string','max:255'],
            'is_default' => ['nullable','boolean'],
        ];
    }

}

