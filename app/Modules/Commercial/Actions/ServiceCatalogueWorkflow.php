<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\ServiceTimeRate;
use App\Modules\Commercial\Support\CommercialInput;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Authoritative Service persistence shared by browser forms and typed API. */
class ServiceCatalogueWorkflow
{
    public function store(Request $request)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            // Validate request via FormRequest
            $data = $this->validated($request, $service ?? null);

            // Save service
            $service = Services::query()->create([
                'sku' => $data['sku'],
                'name' => $data['name'],
                'unitId' => $data['unitId'],
                'customer_unit_singular' => $data['customer_unit_singular'] ?? null,
                'customer_unit_plural' => $data['customer_unit_plural'] ?? null,
                'sla_id' => $data['sla_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'status' => $data['status'] ?? 'Active',
                'icon' => $data['icon'] ?? null,
                // DB does not have sort_order or queue_default_id currently
                'availability_addon_of_service_id' => $data['availability_addon_of_service_id'] ?? null,
                'availability_audience' => $data['availability_audience'] ?? 'all',
                'orderable' => (bool) ($data['orderable'] ?? false),
                'taxable' => $data['taxable'] ?? 0,
                'setup_fee' => $data['setup_fee'] ?? null,
                'billing_cycle' => $data['billing_cycle'] ?? 'monthly',
                'price_including_tax' => $data['price_including_tax'] ?? 0.00,
                'price_ex_vat' => $data['price_ex_vat'] ?? 0.00,
                'one_time_fee' => $data['one_time_fee'] ?? 0.00,
                'one_time_fee_recurrence' => $data['one_time_fee_recurrence'] ?? null,
                'recurrence_value_x' => $data['recurrence_value_x'] ?? null,
                'default_discount_value' => $data['default_discount_value'] ?? 0.00,
                'default_discount_type' => $data['default_discount_type'] ?? null,
                'timebank_enabled' => (bool) ($data['timebank_enabled'] ?? false),
                'timebank_minutes' => $data['timebank_minutes'] ?? null,
                'timebank_interval' => $data['timebank_interval'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'long_description' => $data['long_description'] ?? null,
                'created_by_user_id' => $request->user()->id,
                'updated_by_user_id' => $request->user()->id,
                // published_at / archived_at not present in migration
            ]);

            app(ServiceComposition::class)->costs($service, $data['costs'] ?? []);
            app(ServiceComposition::class)->terms($service, $data['terms'] ?? []);
            $this->syncTimeRates($service, $data['time_rates'] ?? []);

            // Redirect back with success message
            return $service->refresh();

        }, 3);
    }

    public function update(Request $request, Services $service)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $service) {
            $service = $service->newQuery()->lockForUpdate()->findOrFail($service->id);

            $service->loadMissing('sourceIntegration');
            abort_if($service->isIntegrationManaged(), 403, 'This Service is owned by its active source Integration.');

            // Validate request via FormRequest
            $data = $this->validated($request, $service ?? null);

            // Save service
            $service->update([
                'sku' => $data['sku'],
                'name' => $data['name'],
                'unitId' => $data['unitId'],
                'customer_unit_singular' => $data['customer_unit_singular'] ?? null,
                'customer_unit_plural' => $data['customer_unit_plural'] ?? null,
                'sla_id' => $data['sla_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'status' => $data['status'] ?? 'Active',
                'icon' => $data['icon'] ?? null,
                // DB does not have sort_order or queue_default_id currently
                'availability_addon_of_service_id' => $data['availability_addon_of_service_id'] ?? null,
                'availability_audience' => $data['availability_audience'] ?? 'all',
                'orderable' => (bool) ($data['orderable'] ?? false),
                'taxable' => $data['taxable'] ?? 0,
                'setup_fee' => $data['setup_fee'] ?? null,
                'billing_cycle' => $data['billing_cycle'] ?? 'monthly',
                'price_including_tax' => $data['price_including_tax'] ?? 0.00,
                'price_ex_vat' => $data['price_ex_vat'] ?? 0.00,
                'one_time_fee' => $data['one_time_fee'] ?? 0.00,
                'one_time_fee_recurrence' => $data['one_time_fee_recurrence'] ?? null,
                'recurrence_value_x' => $data['recurrence_value_x'] ?? null,
                'default_discount_value' => $data['default_discount_value'] ?? 0.00,
                'default_discount_type' => $data['default_discount_type'] ?? null,
                'timebank_enabled' => (bool) ($data['timebank_enabled'] ?? false),
                'timebank_minutes' => $data['timebank_minutes'] ?? null,
                'timebank_interval' => $data['timebank_interval'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'long_description' => $data['long_description'] ?? null,
                'updated_by_user_id' => $request->user()->id,
                // published_at / archived_at not present in migration
            ]);

            if (isset($data['costs'])) {
                app(ServiceComposition::class)->costs($service, $data['costs']);
            }
            app(ServiceComposition::class)->terms($service, $data['terms'] ?? []);
            $this->syncTimeRates($service, $data['time_rates'] ?? []);

            // Redirect back with success message
            return $service->refresh();

        }, 3);
    }

    private function syncTimeRates(Services $service, array $rates): void
    {
        $keep = [];

        foreach ($rates as $timeRateId => $data) {
            if (empty($data['enabled'])) {
                continue;
            }

            $keep[] = (int) $timeRateId;

            ServiceTimeRate::query()->updateOrCreate(
                [
                    'service_id' => $service->id,
                    'time_rate_id' => (int) $timeRateId,
                ],
                [
                    'amount_ex_vat' => $data['amount_ex_vat'] !== '' ? $data['amount_ex_vat'] : null,
                    'is_active' => true,
                ]
            );
        }

        ServiceTimeRate::query()
            ->where('service_id', $service->id)
            ->when($keep !== [], fn ($query) => $query->whereNotIn('time_rate_id', $keep))
            ->when($keep === [], fn ($query) => $query)
            ->update(['is_active' => false]);
    }

    public function rules(Request $request): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:255',
                Rule::unique('services')->ignore($request->route('service')),
            ],
            'unitId' => ['required', 'integer', 'exists:units,id'],
            'customer_unit_singular' => ['nullable', 'string', 'max:255'],
            'customer_unit_plural' => ['nullable', 'string', 'max:255'],
            'sla_id' => ['nullable', 'exists:sla,id'],
            'category_id' => ['sometimes', 'nullable', 'exists:categories,id'],
            'status' => ['nullable', 'in:draft,published,archived,Active,Inactive'],
            'icon' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
            'queue_default_id' => ['nullable', 'integer'],
            'availability_addon_of_service_id' => ['nullable', 'integer'],
            'availability_audience' => ['nullable', 'in:all,business,private'],
            'orderable' => ['sometimes', 'boolean'],
            'taxable' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'setup_fee' => ['nullable', 'numeric', 'min:0'],
            'price_ex_vat' => ['required', 'numeric', 'min:0'],
            'price_including_tax' => ['nullable', 'numeric', 'min:0'],
            'costs' => ['nullable', 'array'],
            'costs.*' => ['exists:costs,id'],
            'billing_cycle' => ['required', 'in:monthly,yearly,one_time'],
            'unit_pricing' => ['nullable', 'in:none,per_user,per_device,per_server'],
            'one_time_fee' => ['nullable', 'numeric', 'min:0'],
            'one_time_fee_recurrence' => ['nullable', 'in:none,yearly,every_x_years,every_x_months'],
            'recurrence_value_x' => ['nullable', 'integer', 'min:1'],
            'default_discount_value' => ['nullable', 'numeric', 'min:0'],
            'default_discount_type' => ['nullable', 'in:amount,percent'],
            'timebank_enabled' => ['sometimes', 'boolean'],
            'timebank_minutes' => ['nullable', 'integer', 'min:0'],
            'timebank_interval' => ['nullable', 'in:monthly,yearly,one_time'],
            'short_description' => ['nullable', 'string'],
            'long_description' => ['nullable', 'string'],
            'terms' => ['nullable', 'array'],
            'terms.*' => ['exists:terms,id'],
            'time_rates' => ['nullable', 'array'],
            'time_rates.*.enabled' => ['nullable', 'boolean'],
            'time_rates.*.amount_ex_vat' => ['nullable', 'numeric', 'min:0'],
            'published_at' => ['nullable', 'date'],
            'archived_at' => ['nullable', 'date'],
        ];
    }

    public function validated(Request $request, ?Services $record = null): array
    {
        $rules = $this->rules($request);
        if ($request->is('api/*')) {
            foreach (['sort_order', 'queue_default_id', 'unit_pricing', 'published_at', 'archived_at'] as $field) {
                unset($rules[$field]);
            }
        }
        $base = $record ? $record->only(array_keys($rules)) : [];
        if ($record && $request->is('api/*')) {
            $base['costs'] = $record->costRelations()->pluck('costId')->all();
            $base['terms'] = $record->serviceTerms()->pluck('terms.id')->all();
            $base['time_rates'] = $record->serviceTimeRates()->get()->mapWithKeys(fn ($row) => [$row->time_rate_id => ['enabled' => $row->is_active, 'amount_ex_vat' => $row->amount_ex_vat]])->all();
        }
        if (! $request->is('api/*')) {
            $base['orderable'] = $request->boolean('orderable');
            $base['timebank_enabled'] = $request->boolean('timebank_enabled');
        }
        if ($request->has('timebank_minutes') && is_numeric(str_replace(',', '.', (string) $request->input('timebank_minutes')))) {
            $request->merge(['timebank_minutes' => (int) str_replace(',', '.', (string) $request->input('timebank_minutes'))]);
        }
        foreach ($request->input('time_rates', []) ?? [] as $id => $data) {
            abort_unless(ctype_digit((string) $id) && \App\Modules\Commercial\Models\TimeRate::query()->whereKey($id)->exists(), 422, 'Unknown time rate.');
        }
        $payload = array_merge($base, $request->all());

        return CommercialInput::validate($request, $rules, $payload);
    }
}
