<?php

namespace App\Modules\Integration\Requests\Admin;

use App\Modules\Integration\Support\RmmAlertRuleDefinition;
use App\Modules\Integration\Support\RmmAlertSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRmmAlertRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'revision' => [$this->isMethod('PUT') ? 'required' : 'nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'priority' => ['required', 'integer', 'min:0', 'max:100000'],
            'stop_processing' => ['nullable', 'boolean'],
            'conditions' => ['required', 'array'],
            'conditions.subject_contains' => ['nullable', 'string', 'max:255'],
            'conditions.severities' => ['nullable', 'array', 'max:3'],
            'conditions.severities.*' => ['string', Rule::in(RmmAlertSeverity::LEVELS)],
            'conditions.asset_id' => ['nullable', 'integer'],
            'conditions.client_id' => ['nullable', 'integer'],
            'conditions.fingerprint' => ['nullable', 'string', 'max:255'],
            'conditions.integration_types' => ['nullable', 'array', 'max:2'],
            'conditions.integration_types.*' => ['string', Rule::in(['tactical', 'nable'])],
            'actions' => ['required', 'array', 'min:1', 'max:10'],
            'actions.*.type' => ['required', 'string', Rule::in(array_keys(RmmAlertRuleDefinition::ACTION_LABELS))],
            'actions.*.subject' => ['nullable', 'string', 'max:255'],
            'actions.*.title' => ['nullable', 'string', 'max:255'],
            'actions.*.description' => ['nullable', 'string', 'max:2000'],
            'actions.*.queue_id' => ['nullable', 'integer'],
            'actions.*.ticket_type_id' => ['nullable', 'integer'],
            'actions.*.priority_id' => ['nullable', 'integer'],
            'actions.*.category_id' => ['nullable', 'integer'],
            'actions.*.owner_id' => ['nullable', 'integer'],
            'actions.*.assigned_to' => ['nullable', 'integer'],
            'actions.*.due_minutes_from_now' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'actions.*.estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'actions.*.reopen_status_id' => ['nullable', 'integer'],
            'actions.*.signal_type' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/'],
            'actions.*.severity' => ['nullable', 'string', Rule::in(RmmAlertSeverity::LEVELS)],
            'actions.*.summary' => ['nullable', 'string', 'max:500'],
        ];
    }
}
