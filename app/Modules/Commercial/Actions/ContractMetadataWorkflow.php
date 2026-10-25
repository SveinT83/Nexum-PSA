<?php

namespace App\Modules\Commercial\Actions;

use App\Models\Core\User;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Support\CommercialInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Metadata contract shared by the normal browser form and API. Actor evidence is server-owned. */
class ContractMetadataWorkflow
{
    public function store(Request $request): Contracts
    {
        $data = $this->validated($request);

        return Contracts::query()->create(array_merge($data, ['approval_status' => 'draft']))->refresh();
    }

    public function update(Request $request, Contracts $contract): ?Contracts
    {
        return DB::transaction(function () use ($request, $contract) {
            $locked = Contracts::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                return null;
            }
            $data = $this->validated($request, $locked);
            $locked->fill($data)->forceFill(['customer_document_snapshot' => null])->save();

            return $locked->refresh();
        });
    }

    private function validated(Request $request, ?Contracts $contract = null): array
    {
        $rules = $this->rules($request);
        $base = $contract ? $contract->only(array_keys($rules)) : ['created_by' => $request->user()->id];
        $data = array_merge($base, $request->all());
        foreach (['auto_renew', 'allow_indexing_during_binding', 'allow_decrease_during_binding', 'allow_license_additions', 'allow_license_increases', 'allow_license_decreases', 'allow_license_price_updates'] as $field) {
            if (! $request->is('api/*') || ! $contract) {
                $data[$field] = $request->boolean($field);
            }
        }
        if (! empty($data['end_date'])) {
            $rules['binding_end_date'][] = 'before_or_equal:end_date';
        }

        return CommercialInput::validate($request, $rules, $data, (new \App\Modules\Commercial\Requests\ContractsRequest)->messages());
    }

    public function rules(Request $request): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'sla_id' => ['nullable', 'exists:sla,id'],
            'created_by' => ['required', 'exists:'.(new User)->getTable().',id'],

            'description' => ['nullable', 'string'],

            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'binding_end_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
                Rule::when($request->filled('end_date'), ['before_or_equal:end_date']),
            ],

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
}
