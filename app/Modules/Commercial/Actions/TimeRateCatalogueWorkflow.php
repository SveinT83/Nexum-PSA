<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Commercial\Support\CommercialInput;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Shared rate validation/persistence; accepted snapshots remain independent. */
class TimeRateCatalogueWorkflow
{
    public function store(Request $request): TimeRate
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            $data = $this->validated($request);
            $data['slug'] = Str::slug($data['code']);

            $rate = TimeRate::query()->create($data);

            return $rate->refresh();

        }, 3);
    }

    public function update(Request $request, TimeRate $rate): TimeRate
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $rate) {
            $rate = $rate->newQuery()->lockForUpdate()->findOrFail($rate->id);

            $data = $this->validated($request, $rate);
            $data['slug'] = Str::slug($data['code']);

            $rate->update($data);

            return $rate->refresh();

        }, 3);
    }

    private function validated(Request $request, ?TimeRate $rate = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:80', Rule::unique('time_rates', 'code')->ignore($rate)],
            'rate_type' => ['required', Rule::in(array_keys($this->rateTypes()))],
            'unit' => ['required', Rule::in(array_keys($this->units()))],
            'amount_ex_vat' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'description' => ['nullable', 'string'],
            'applies_without_contract' => ['nullable', 'boolean'],
            'applies_with_contract' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_customer_visible' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
        $base = $request->is('api/*') && $rate ? $rate->only(array_keys($rules)) : [];

        return CommercialInput::validate($request, $rules, array_merge($base, $request->all())) + [
            'applies_without_contract' => false,
            'applies_with_contract' => false,
            'is_active' => false,
            'is_customer_visible' => false,
            'sort_order' => 0,
        ];
    }

    private function rateTypes(): array
    {
        return [
            'labor' => 'Labor',
            'driving' => 'Driving',
            'travel' => 'Travel',
            'other' => 'Other',
        ];
    }

    private function units(): array
    {
        return [
            'hour' => 'Hour',
            'km' => 'Kilometer',
            'fixed' => 'Fixed',
        ];
    }
}
