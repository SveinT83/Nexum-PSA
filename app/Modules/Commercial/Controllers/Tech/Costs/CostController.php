<?php

namespace App\Modules\Commercial\Controllers\Tech\Costs;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Requests\StoreCostRequest;
use App\Modules\Commercial\Models\Cost;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Commercial\Models\Economy\Units;
use Illuminate\Http\Request;

class CostController extends Controller
{

    // -----------------------------------------
    // INDEX - Show a list of all costs
    // -----------------------------------------
    public function index(Request $request)
    {
        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';

        $allowed = [
            'name' => 'costs.name',
            'cost' => 'costs.cost',
            'recurrence' => 'costs.recurrence',
            'vendor' => 'vendors.name',
            'updated_at' => 'costs.updated_at',
        ];
        $sortColumn = $allowed[$sort] ?? $allowed['name'];

        $costs = Cost::query()
            ->leftJoin('vendors', 'vendors.id', '=', 'costs.vendor_id')
            ->select('costs.*')
            ->with(['creator', 'updater', 'vendor', 'unit'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = '%'.$request->string('q')->trim()->toString().'%';

                $query->where(function ($query) use ($search): void {
                    $query->where('costs.name', 'like', $search)
                        ->orWhere('costs.note', 'like', $search)
                        ->orWhere('costs.recurrence', 'like', $search)
                        ->orWhere('vendors.name', 'like', $search);
                });
            })
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('costs.vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('recurrence'), fn ($query) => $query->where('costs.recurrence', $request->input('recurrence')))
            ->orderBy($sortColumn, $direction)
            ->orderBy('costs.id')
            ->paginate(25)
            ->withQueryString();

        return view('commercial::Tech.cs.costs.index', [
            'costs' => $costs,
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
            'recurrences' => Cost::query()->distinct()->orderBy('recurrence')->pluck('recurrence')->filter()->values(),
            'sort' => $sort,
            'direction' => $direction,
            'filters' => $request->only(['q', 'vendor_id', 'recurrence', 'sort', 'direction']),
        ]);
    }

    // -----------------------------------------
    // SHOW - Show a single cost
    // -----------------------------------------
    public function show(Cost $cost)
    {

        $vendors = Vendor::orderBy('name')->get();

        //Return the view whit
        return view('commercial::Tech.cs.costs.show', [
            'cost' => $cost,
            'vendors' => $vendors,
        ]);
    }

    // -----------------------------------------
    // CREATE - Show form to create a new cost
    // -----------------------------------------
    public function create()
    {

        //Get all vendors fore a option
        $vendors = Vendor::orderBy('name')->get();

        //Get all Units for option
        $units = Units::orderBy('name')->get();

        //Render
        return view('commercial::Tech.cs.costs.form', [
            'vendors' => $vendors,
            'units' => $units,
        ]);
    }

    // -----------------------------------------
    // STORE - Store the cost from form
    // -----------------------------------------
    public function store(StoreCostRequest $request)
    {
        // Validate request via FormRequest
        $data = $request->validated();

        Cost::create([
            'name' => $data['name'],
            'cost' => $data['cost'],
            'unitId' => $data['unitId'],
            'recurrence' => $data['recurrence'],
            'vendor_id' => $data['vendor_id'],
            'note' => $data['note'] ?? '',
            'created_by_user_id' => auth()->id(),
            'updated_by_user_id' => auth()->id(),
        ]);

        return redirect()->route('tech.costs.index')->with('success', 'Cost created successfully');
    }

    // -----------------------------------------
    // EDIT - Show form to edit a cost
    // -----------------------------------------
    public function edit(Cost $cost)
    {
        $vendors = Vendor::orderBy('name')->get();

        //Get all Units for option
        $units = Units::orderBy('name')->get();

        return view('commercial::Tech.cs.costs.form', [
            'cost' => $cost,
            'vendors' => $vendors,
            'units' => $units,
        ]);
    }

    // -----------------------------------------
    // UPDATE - Update the cost from form
    // -----------------------------------------
    public function update(StoreCostRequest $request, Cost $cost)
    {
        $data = $request->validated();

        $cost->update([
            'name' => $data['name'],
            'cost' => $data['cost'],
            'unitId' => $data['unitId'],
            'recurrence' => $data['recurrence'],
            'vendor_id' => $data['vendor_id'],
            'note' => $data['note'] ?? '',
            'updated_by_user_id' => auth()->id(),
        ]);

        return redirect()->route('tech.costs.show', $cost)->with('success', 'Cost updated successfully');
    }

    // -----------------------------------------
    // DELETE - Delete a cost
    // -----------------------------------------
    public function delete(Cost $cost)
    {
        $cost->delete();

        return redirect()->route('tech.costs.index')->with('success', 'Cost deleted successfully');
    }
}
