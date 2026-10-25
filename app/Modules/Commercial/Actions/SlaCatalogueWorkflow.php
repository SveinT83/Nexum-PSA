<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Commercial\Support\CommercialInput;
use Illuminate\Http\Request;

/** Authoritative Sla persistence shared by browser forms and typed API. */
class SlaCatalogueWorkflow
{
    public function store(Request $request)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            Sla::query()->orderBy('id')->lockForUpdate()->get();

            // Validate request via FormRequest
            $data = $this->validated($request, $sla ?? null);

            $sla = Sla::create([
                'name' => $data['name'],
                'description' => $data['description'],
                'is_default' => (bool) ($data['is_default'] ?? false),

                'low_firstResponse' => $data['low_firstResponse'],
                'low_firstResponse_type' => $data['low_firstResponse_type'],
                'low_onsite' => $data['low_onsite'],
                'low_onsite_type' => $data['low_onsite_type'],

                'medium_firstResponse' => $data['medium_firstResponse'],
                'medium_firstResponse_type' => $data['medium_firstResponse_type'],
                'medium_onsite' => $data['medium_onsite'],
                'medium_onsite_type' => $data['medium_onsite_type'],

                'high_firstResponse' => $data['high_firstResponse'],
                'high_firstResponse_type' => $data['high_firstResponse_type'],
                'high_onsite' => $data['high_onsite'],
                'high_onsite_type' => $data['high_onsite_type'],

                'created_by_user_id' => $request->user()->id,
                'updated_by_user_id' => $request->user()->id,
            ]);

            $this->ensureSingleDefault($sla);

            return $sla->refresh();

        }, 3);
    }

    public function update(Request $request, Sla $sla)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $sla) {
            $sla = $sla->newQuery()->lockForUpdate()->findOrFail($sla->id);

            Sla::query()->orderBy('id')->lockForUpdate()->get();

            $data = $this->validated($request, $sla ?? null);

            $sla->update([
                'name' => $data['name'],
                'description' => $data['description'],
                'is_default' => (bool) ($data['is_default'] ?? false),

                'low_firstResponse' => $data['low_firstResponse'],
                'low_firstResponse_type' => $data['low_firstResponse_type'],
                'low_onsite' => $data['low_onsite'],
                'low_onsite_type' => $data['low_onsite_type'],

                'medium_firstResponse' => $data['medium_firstResponse'],
                'medium_firstResponse_type' => $data['medium_firstResponse_type'],
                'medium_onsite' => $data['medium_onsite'],
                'medium_onsite_type' => $data['medium_onsite_type'],

                'high_firstResponse' => $data['high_firstResponse'],
                'high_firstResponse_type' => $data['high_firstResponse_type'],
                'high_onsite' => $data['high_onsite'],
                'high_onsite_type' => $data['high_onsite_type'],

                'updated_by_user_id' => $request->user()->id,
            ]);

            $this->ensureSingleDefault($sla);

            return $sla->refresh();

        }, 3);
    }

    private function ensureSingleDefault(Sla $sla): void
    {
        if (! $sla->is_default) {
            if (! Sla::query()->where('is_default', true)->exists()) {
                $sla->forceFill(['is_default' => true])->save();
            }

            return;
        }

        Sla::query()
            ->whereKeyNot($sla->id)
            ->update(['is_default' => false]);
    }

    public function rules(Request $request): array
    {
        return [
            'name' => ['required'],
            'description' => ['required'],
            'is_default' => ['nullable', 'boolean'],

            'low_firstResponse' => ['required', 'integer'],
            'low_firstResponse_type' => ['required'],
            'low_onsite' => ['required', 'integer'],
            'low_onsite_type' => ['required'],

            'medium_firstResponse' => ['required', 'integer'],
            'medium_firstResponse_type' => ['required'],
            'medium_onsite' => ['required', 'integer'],
            'medium_onsite_type' => ['required'],

            'high_firstResponse' => ['required', 'integer'],
            'high_firstResponse_type' => ['required'],
            'high_onsite' => ['required', 'integer'],
            'high_onsite_type' => ['required'],
        ];
    }

    public function validated(Request $request, ?Sla $record = null): array
    {
        $rules = $this->rules($request);
        $base = $record ? $record->only(array_keys($rules)) : [];

        $payload = array_merge($base, $request->all());

        return CommercialInput::validate($request, $rules, $payload);
    }
}
