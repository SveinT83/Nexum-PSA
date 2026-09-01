<?php

namespace App\Http\Requests\Tech\Clients;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $clientNumberRules = ['required', 'string', 'regex:/^\d{5}$/'];
        if (! $this->usesUnchangedSuggestedClientNumber()) {
            $clientNumberRules[] = Rule::unique('clients', 'client_number');
        }

        return [
            // Client
            'name' => ['required','string','max:255'],
            'client_number' => $clientNumberRules,
            'suggested_client_number' => ['nullable', 'string', 'regex:/^\d{5}$/'],
            'org_no' => ['nullable','string','max:50'],
            'client_format_id' => ['nullable','exists:client_formats,id'],
            'billing_email' => ['nullable','email','max:255'],
            'notes' => ['nullable','string'],
            'active' => ['sometimes','boolean'],

            // Default sites (minimal for now)
            'site_name' => ['required','string','max:255'],

            // Default sites user (minimal for now)
            'user_name' => ['required','string','max:255'],
            'user_email' => ['required','email','max:255'],
            'user_phone' => ['nullable','string','max:50'],
            'user_role' => ['nullable','string','max:100'],

            'create_in_rmm' => ['sometimes','boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_number.regex' => 'Client number must be exactly 5 digits.',
            'client_number.unique' => 'This client number is already in use.',
        ];
    }

    public function usesUnchangedSuggestedClientNumber(): bool
    {
        $suggested = trim((string) $this->input('suggested_client_number', ''));
        $submitted = trim((string) $this->input('client_number', ''));

        return $suggested !== ''
            && $submitted !== ''
            && hash_equals($suggested, $submitted);
    }
}
