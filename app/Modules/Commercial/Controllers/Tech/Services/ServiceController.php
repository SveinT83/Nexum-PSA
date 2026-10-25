<?php

namespace App\Modules\Commercial\Controllers\Tech\Services;

// Use service
use App\Http\Controllers\Controller;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\ServiceTimeRate;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Commercial\Requests\ServiceStoreRequest;
use App\Modules\Documentation\Models\Vendor;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    // -----------------------------------------
    // Show - Displays a single service
    // -----------------------------------------
    public function show(Services $service)
    {
        $service->loadMissing('sourceIntegration');

        // Get all Units for option
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

        // Get all Units for option
        $units = Units::orderBy('name')->get();

        return view('commercial::Tech.cs.services.create', [
            'service' => new Services,
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
        $service->loadMissing('sourceIntegration');
        abort_if($service->isIntegrationManaged(), 403, 'This Service is owned by its active source Integration.');

        // Get all Units for option
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
        $service = app(\App\Modules\Commercial\Actions\ServiceCatalogueWorkflow::class)->store($request);

        return redirect()->route('tech.services.index')->with('success', 'Service created successfully.');

    }

    // -----------------------------------------
    // UPDATE - Updates an new service
    // -----------------------------------------
    public function update(ServiceStoreRequest $request, Services $service)
    {
        $service = app(\App\Modules\Commercial\Actions\ServiceCatalogueWorkflow::class)->update($request, $service);

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
        $sortableColumns = ['sku', 'name', 'vendor', 'source', 'price', 'billing_cycle', 'status', 'updated_at'];

        if (! in_array($sort, $sortableColumns, true)) {
            $sort = 'name';
        }

        $query = Services::query()
            ->with(['vendor', 'sourceIntegration'])
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
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('source'), fn ($query) => $query->where('source', $request->input('source')))
            ->when($request->filled('audience'), fn ($query) => $query->where('availability_audience', $request->input('audience')))
            ->when($request->filled('orderable'), fn ($query) => $query->where('orderable', $request->input('orderable') === 'yes'));

        if ($sort === 'price') {
            $query->orderBy('price_ex_vat', $direction)->orderBy('name');
        } elseif ($sort === 'vendor') {
            $query->orderBy(Vendor::query()->select('name')->whereColumn('vendors.id', 'services.vendor_id'), $direction)
                ->orderBy('name');
        } else {
            $query->orderBy($sort, $direction)->orderBy('name');
        }

        $services = $query->paginate(25)->withQueryString();

        return view('commercial::Tech.cs.services.index', [
            'services' => $services,
            'statuses' => Services::query()->distinct()->orderBy('status')->pluck('status')->filter()->values(),
            'billingCycles' => Services::query()->distinct()->orderBy('billing_cycle')->pluck('billing_cycle')->filter()->values(),
            'audiences' => Services::query()->distinct()->orderBy('availability_audience')->pluck('availability_audience')->filter()->values(),
            'vendors' => Vendor::query()->whereHas('services')->orderBy('name')->get(['id', 'name']),
            'sources' => Services::query()->distinct()->orderBy('source')->pluck('source')->filter()->values(),
            'filters' => $request->only(['q', 'status', 'billing_cycle', 'vendor_id', 'source', 'audience', 'orderable', 'sort', 'direction']),
        ]);
    }

    // -----------------------------------------
    // DELETE - Delete an service and the related poviot
    // -----------------------------------------
    public function destroy(Services $service)
    {
        $service->loadMissing('sourceIntegration');
        abort_if($service->isIntegrationManaged(), 403, 'This Service is owned by its active source Integration and cannot be deleted.');

        // Relatertet rows in poviot tables Will be deleted from databasen migrations files.

        // Delete the service
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
