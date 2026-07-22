<?php

namespace App\Modules\Commercial\Requests;

use App\Models\Core\User;
use Illuminate\Foundation\Http\FormRequest;

class ContractsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'sla_id' => ['nullable', 'exists:sla,id'],
            'created_by' => ['required', 'exists:'.(new User)->getTable().',id'],

            'description' => ['nullable', 'string'],

            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'binding_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],

            'auto_renew' => ['boolean'],
            'renewal_months' => ['nullable', 'integer', 'min:1'],

            'allow_indexing_during_binding' => ['boolean'],
            'allow_decrease_during_binding' => ['boolean'],
            'allow_license_additions' => ['boolean'],
            'allow_license_increases' => ['boolean'],
            'allow_license_decreases' => ['boolean'],
            'allow_license_price_updates' => ['boolean'],

            'max_index_pct_binding' => ['nullable', 'numeric'],
            'post_binding_index_pct' => ['nullable', 'numeric'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
