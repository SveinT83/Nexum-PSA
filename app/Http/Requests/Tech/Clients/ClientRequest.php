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
        $tripletex = app(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class)->connection() !== null;
        $clientNumberRules = $tripletex ? ['nullable', 'string', 'regex:/^[0-9]{1,10}$/'] : ['required', 'string', 'regex:/^\d{5}$/'];
        if (! $tripletex && ! $this->usesUnchangedSuggestedClientNumber()) {
            $clientNumberRules[] = Rule::unique('clients', 'client_number');
        }

        return [
            // Client
            'name' => ['required', 'string', 'max:255'],
            'client_number' => $clientNumberRules,
            'suggested_client_number' => ['nullable', 'string', $tripletex ? 'regex:/^[0-9]{1,10}$/' : 'regex:/^\d{5}$/'],
            'tripletex_number_mode' => ['sometimes', 'boolean'],
            'tripletex_request_key' => ['nullable', 'uuid'],
            'tripletex_customer_id' => ['nullable', 'integer', 'min:1'],
            'org_no' => ['nullable', 'string', 'max:50'],
            'client_format_id' => ['nullable', 'exists:client_formats,id'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],

            // Default sites (minimal for now)
            'site_name' => ['required', 'string', 'max:255'],
            'site_address' => ['nullable', 'string', 'max:255'],
            'site_co_address' => ['nullable', 'string', 'max:255'],
            'site_zip' => ['nullable', 'string', 'max:20'],
            'site_city' => ['nullable', 'string', 'max:100'],
            'site_country' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],

            // Default sites user (minimal for now)
            'user_name' => ['required', 'string', 'max:255'],
            'user_email' => ['required', 'email', 'max:255'],
            'user_phone' => ['nullable', 'string', 'max:50'],
            'user_role' => ['nullable', 'string', 'max:100'],

            'create_in_rmm' => ['sometimes', 'boolean'],
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
