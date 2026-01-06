<?php

namespace App\Http\Requests\Tech\CS\Requests\Tech\Clients;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Client
            'name' => ['required','string','max:255'],
            'client_number' => ['required','string','regex:/^\d{5}$/','unique:clients,client_number'],
            'org_no' => ['nullable','string','max:50'],
            'billing_email' => ['nullable','email','max:255'],
            'notes' => ['nullable','string'],
            'active' => ['sometimes','boolean'],

            // Default site (minimal for now)
            'site_name' => ['required','string','max:255'],

            // Default site user (minimal for now)
            'user_name' => ['required','string','max:255'],
            'user_email' => ['required','email','max:255'],
            'user_phone' => ['nullable','string','max:50'],
            'user_role' => ['nullable','string','max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_number.regex' => 'Client number must be exactly 5 digits.',
        ];
    }
}
