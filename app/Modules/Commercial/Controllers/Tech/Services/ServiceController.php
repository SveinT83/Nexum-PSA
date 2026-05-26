<?php

namespace App\Modules\Commercial\Controllers\Tech\Services;

//Use service
use App\Http\Controllers\Controller;
use App\Modules\Commercial\Models\CostRelations;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\ServiceTimeRate;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Commercial\Models\TimeRate;
use Illuminate\Http\Request;
use App\Modules\Commercial\Requests\ServiceStoreRequest;

class ServiceController extends Controller
{

    // -----------------------------------------
    // Show - Displays a single service
    // -----------------------------------------
    public function show(Services $service)
    {
        //Get all Units for option
        $units = Units::orderBy('name')->get();

        return view('commercial::Tech.cs.services.show', [
            'service' => $service,
            'units' => $units,
            'slas' => $this->availableSlas(),
            'timeRates' => TimeRate::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    // -----------------------------------------
    // Create - Show Create form
    // -----------------------------------------
    public function create()
    {

        //Get all Units for option
        $units = Units::orderBy('name')->get();

        return view('commercial::Tech.cs.services.create', [
            'service' => new Services(),
            'units' => $units,
            'slas' => $this->availableSlas(),
            'timeRates' => TimeRate::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    // -----------------------------------------
    // Edit - Show Edit form
    // -----------------------------------------
    public function edit(Services $service)
    {

        //Get all Units for option
        $units = Units::orderBy('name')->get();

        return view('commercial::Tech.cs.services.edit', [
            'service' => $service,
            'units' => $units,
            'slas' => $this->availableSlas(),
            'timeRates' => TimeRate::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    // -----------------------------------------
    // Store - Stores an new service
    // -----------------------------------------
    public function store(ServiceStoreRequest $request)
    {
        // Validate request via FormRequest
        $data = $request->validated();

        // Save service
        $service = Services::query()->create([
            'sku' => $data['sku'],
            'name' => $data['name'],
            'unitId' => $data['unitId'],
            'sla_id' => $data['sla_id'] ?? null,
            'status' => $data['status'] ?? 'Active',
            'icon' => $data['icon'] ?? null,
            // DB does not have sort_order or queue_default_id currently
            'availability_addon_of_service_id' => $data['availability_addon_of_service_id'] ?? null,
            'availability_audience' => $data['availability_audience'] ?? 'all',
            'orderable' => $request->boolean('orderable'),
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
            'timebank_enabled' => $request->boolean('timebank_enabled'),
            'timebank_minutes' => $data['timebank_minutes'] ?? null,
            'timebank_interval' => $data['timebank_interval'] ?? null,
            'short_description' => $data['short_description'] ?? null,
            'long_description' => $data['long_description'] ?? null,
            'created_by_user_id' => auth()->id(),
            'updated_by_user_id' => auth()->id(),
            // published_at / archived_at not present in migration
        ]);

        // Save costs relations
        if (!empty($data['costs'])) {
            foreach ($data['costs'] as $costId) {
                CostRelations::create([
                    'serviceId' => $service->id,
                    'costId' => $costId,
                ]);
            }
        }

        // Save terms (Legal)
        $service->serviceTerms()->attach($data['terms'] ?? []);
        $this->syncTimeRates($service, $data['time_rates'] ?? []);

        // Redirect back with success message
        return redirect()->route('tech.services.index')->with('success', 'Service created successfully.');

    }

    // -----------------------------------------
    // UPDATE - Updates an new service
    // -----------------------------------------
    public function update(ServiceStoreRequest $request, Services $service)
    {
        // Validate request via FormRequest
        $data = $request->validated();

        // Save service
        $service->update([
            'sku' => $data['sku'],
            'name' => $data['name'],
            'unitId' => $data['unitId'],
            'sla_id' => $data['sla_id'] ?? null,
            'status' => $data['status'] ?? 'Active',
            'icon' => $data['icon'] ?? null,
            // DB does not have sort_order or queue_default_id currently
            'availability_addon_of_service_id' => $data['availability_addon_of_service_id'] ?? null,
            'availability_audience' => $data['availability_audience'] ?? 'all',
            'orderable' => $request->boolean('orderable'),
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
            'timebank_enabled' => $request->boolean('timebank_enabled'),
            'timebank_minutes' => $data['timebank_minutes'] ?? null,
            'timebank_interval' => $data['timebank_interval'] ?? null,
            'short_description' => $data['short_description'] ?? null,
            'long_description' => $data['long_description'] ?? null,
            'updated_by_user_id' => auth()->id(),
            // published_at / archived_at not present in migration
        ]);

        // Sync costs relations
        if (isset($data['costs'])) {
            $keep = $data['costs'];

            // Delete removed
            CostRelations::where('serviceId', $service->id)
                ->whereNotIn('costId', $keep)
                ->delete();

            // Find existing
            $existing = CostRelations::where('serviceId', $service->id)
                ->pluck('costId')
                ->all();

            // Insert new
            $toInsert = array_diff($keep, $existing);
            foreach ($toInsert as $costId) {
                CostRelations::create([
                    'serviceId' => $service->id,
                    'costId' => $costId,
                ]);
            }
        }

        // Sync terms (Legal)
        $service->serviceTerms()->sync($data['terms'] ?? []);
        $this->syncTimeRates($service, $data['time_rates'] ?? []);

        // Redirect back with success message
        return redirect()
            ->route('tech.services.show', $service)
            ->with('success', 'Service updated successfully.');

    }

    // -----------------------------------------
    // Index - Lists all services
    // -----------------------------------------
    public function index(Request $request)
    {
        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $sortableColumns = ['sku', 'name', 'price', 'billing_cycle', 'status', 'updated_at'];

        if (! in_array($sort, $sortableColumns, true)) {
            $sort = 'name';
        }

        $query = Services::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = '%'.$request->string('q')->trim()->toString().'%';

                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', $search)
                        ->orWhere('sku', 'like', $search)
                        ->orWhere('short_description', 'like', $search)
                        ->orWhere('status', 'like', $search);
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('billing_cycle'), fn ($query) => $query->where('billing_cycle', $request->input('billing_cycle')))
            ->when($request->filled('audience'), fn ($query) => $query->where('availability_audience', $request->input('audience')))
            ->when($request->filled('orderable'), fn ($query) => $query->where('orderable', $request->input('orderable') === 'yes'));

        if ($sort === 'price') {
            $query->orderBy('price_ex_vat', $direction)->orderBy('name');
        } else {
            $query->orderBy($sort, $direction)->orderBy('name');
        }

        $services = $query->paginate(25)->withQueryString();

        return view('commercial::Tech.cs.services.index', [
            'services' => $services,
            'statuses' => Services::query()->distinct()->orderBy('status')->pluck('status')->filter()->values(),
            'billingCycles' => Services::query()->distinct()->orderBy('billing_cycle')->pluck('billing_cycle')->filter()->values(),
            'audiences' => Services::query()->distinct()->orderBy('availability_audience')->pluck('availability_audience')->filter()->values(),
            'filters' => $request->only(['q', 'status', 'billing_cycle', 'audience', 'orderable', 'sort', 'direction']),
        ]);
    }

    // -----------------------------------------
    // DELETE - Delete an service and the related poviot
    // -----------------------------------------
    public function destroy(Services $service)
    {
        // Relatertet rows in poviot tables Will be deleted from databasen migrations files.

        //Delete the service
        $service->delete();

        return redirect()
            ->route('tech.services.index')
            ->with('success', 'Service deleted successfully.');
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

    private function availableSlas()
    {
        return Sla::query()->orderByDesc('is_default')->orderBy('name')->get();
    }
}
