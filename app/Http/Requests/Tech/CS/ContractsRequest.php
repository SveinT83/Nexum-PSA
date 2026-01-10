<?php

namespace App\Http\Requests\Tech\CS;

use Illuminate\Foundation\Http\FormRequest;

class ContractsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'client_id' => ['required'],
            'description' => ['required'],
            'approval_status' => ['boolean'],
            'approval_sent_at' => ['required'],
            'approval_expires_at' => ['required'],
            'approval_approved_at' => ['required', 'date'],
            'approval_approved_by' => ['required'],
            'approval_metadata' => ['required'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required'],
            'binding_end_date' => ['required'],
            'auto_renew' => ['required'],
            'renewal_months' => ['required'],
            'allow_indexing_during_binding' => ['boolean'],
            'max_index_pct_binding' => ['required'],
            'post_binding_index_pct' => ['required'],
            'allow_decrease_during_binding' => ['boolean'],
            'billing_interval' => ['required'],
            'total_monthly_amount' => ['required'],
            'last_indexed_at' => ['required', 'date'],
            'created_by' => ['required'],
            'services' => ['required'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
