<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Cost;
use App\Modules\Commercial\Support\CommercialInput;
use Illuminate\Http\Request;

/** Authoritative Cost persistence shared by browser forms and typed API. */
class CostCatalogueWorkflow
{
    public function store(Request $request)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            // Validate request via FormRequest
            $data = $this->validated($request, $cost ?? null);

            $cost = Cost::create([
                'name' => $data['name'],
                'cost' => $data['cost'],
                'unitId' => $data['unitId'],
                'recurrence' => $data['recurrence'],
                'vendor_id' => $data['vendor_id'],
                'note' => $data['note'] ?? '',
                'created_by_user_id' => $request->user()->id,
                'updated_by_user_id' => $request->user()->id,
            ]);

            return $cost->refresh();

        }, 3);
    }

    public function update(Request $request, Cost $cost)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $cost) {
            $cost = $cost->newQuery()->lockForUpdate()->findOrFail($cost->id);

            abort_if($cost->isIntegrationManaged(), 403, 'This Cost is owned by its active source Integration.');

            $data = $this->validated($request, $cost ?? null);

            $cost->update([
                'name' => $data['name'],
                'cost' => $data['cost'],
                'unitId' => $data['unitId'],
                'recurrence' => $data['recurrence'],
                'vendor_id' => $data['vendor_id'],
                'note' => $data['note'] ?? '',
                'updated_by_user_id' => $request->user()->id,
            ]);

            return $cost->refresh();

        }, 3);
    }

    public function rules(Request $request): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'cost' => ['required', 'numeric', 'min:0'],
            'unitId' => ['required', 'numeric'],
            'recurrence' => ['required', 'string', 'in:month,year,quarter,none'],
            'note' => ['nullable', 'string'],
            'vendor_id' => ['required', 'exists:vendors,id'],
        ];
    }

    public function validated(Request $request, ?Cost $record = null): array
    {
        $rules = $this->rules($request);
        $base = $record ? $record->only(array_keys($rules)) : [];

        $payload = array_merge($base, $request->all());

        return CommercialInput::validate($request, $rules, $payload);
    }
}
