<?php

namespace App\Http\Controllers\Tech\CS\Services;

use App\Http\Controllers\Controller;
use App\Models\CS\CostRelations;
use App\Models\CS\Services\Services;
use Illuminate\Http\Request;

class ServiceController extends Controller
{

    // -----------------------------------------
    // Show - Displays a single service
    // -----------------------------------------
    public function show(\App\Models\CS\Services\Services $service)
    {
        return view('tech.cs.services.show', [
            'service' => $service,
        ]);
    }

    // -----------------------------------------
    // Create - Show Create form
    // -----------------------------------------
    public function create()
    {
        return view('tech.cs.services.create', [
            'service' => new Services(),
        ]);
    }

    // -----------------------------------------
    // Edit - Show Edit form
    // -----------------------------------------
    public function edit(\App\Models\CS\Services\Services $service)
    {
        return view('tech.cs.services.edit', [
            'service' => $service,
        ]);
    }

    // -----------------------------------------
    // Store - Stores an new service
    // -----------------------------------------
    public function store(\App\Http\Requests\Tech\CS\Requests\Tech\CS\ServiceStoreRequest $request)
    {

        // Validate request via FormRequest
        $data = $request->validated();

        // Save service
        $service = Services::query()->create([
            'name' => $data['name'],
            'sku' => $data['sku'],
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
            'terms' => $data['terms'] ?? '',
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

        // Redirect back with success message
        return redirect()->route('tech.services.index')->with('success', 'Service created successfully.');

    }

    // -----------------------------------------
    // UPDATE - Updates an new service
    // -----------------------------------------
    public function update(\App\Http\Requests\Tech\CS\Requests\Tech\CS\ServiceStoreRequest $request, \App\Models\CS\Services\Services $service)
    {
        // Validate request via FormRequest
        $data = $request->validated();

        // Save service
        $service->update([
            'name' => $data['name'],
            'sku' => $data['sku'],
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
            'terms' => $data['terms'] ?? '',
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
        $query = Services::query();

        if ($search = $request->get('search')) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%$search%");
                $q->orWhere('sku', 'like', "%$search%");
            });
        }

        $services = $query->orderBy('name')->paginate(25)->withQueryString();

        return view('tech.cs.services.index', [
            'services' => $services,
            'search' => $search,
        ]);
    }
}
