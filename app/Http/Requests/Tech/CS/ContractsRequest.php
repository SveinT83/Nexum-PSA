<?php

namespace App\Http\Requests\Tech\CS;

use Illuminate\Foundation\Http\FormRequest;

class ContractsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'created_by' => ['required', 'exists:users,id'],

            'description' => ['nullable', 'string'],

            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'binding_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],

            'auto_renew' => ['boolean'],
            'renewal_months' => ['nullable', 'integer', 'min:1'],

            'allow_indexing_during_binding' => ['boolean'],
            'allow_decrease_during_binding' => ['boolean'],

            'max_index_pct_binding' => ['nullable', 'numeric'],
            'post_binding_index_pct' => ['nullable', 'numeric'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
