<?php

namespace App\Http\Controllers\Tech\CS\Costs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tech\CS\StoreCostRequest;
use App\Models\CS\Cost;
use App\Models\Doc\Vendor;

class CostController extends Controller
{

    // -----------------------------------------
    // INDEX - Show a list of all costs
    // -----------------------------------------
    public function index()
    {
        $sort = request('sort', 'name');
        $dir  = request('dir', 'asc');

        $allowed = [
            'name' => 'costs.name',
            'cost' => 'costs.cost',
            'recurrence' => 'costs.recurrence',
            'vendor' => 'vendors.name',
        ];
        $sortColumn = $allowed[$sort] ?? $allowed['name'];
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        $costs = Cost::query()
            ->leftJoin('vendors', 'vendors.id', '=', 'costs.vendor_id')
            ->select('costs.*')
            ->with(['creator', 'updater', 'vendor'])
            ->orderBy($sortColumn, $dir)
            ->orderBy('costs.id') // stabil sortering
            ->get();

        return view('tech.cs.costs.index', [
            'costs' => $costs,
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    // -----------------------------------------
    // SHOW - Show a single cost
    // -----------------------------------------
    public function show(Cost $cost)
    {

        $vendors = Vendor::orderBy('name')->get();

        //Return the view whit
        return view('tech.cs.costs.show', [
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

        //Render
        return view('tech.cs.costs.form', [
            'vendors' => $vendors,
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
            'unit' => $data['unit'],
            'recurrence' => $data['recurrence'],
            'vendor_id' => $data['vendor_id'],
            'note' => $data['note'],
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

        return view('tech.cs.costs.form', [
            'cost' => $cost,
            'vendors' => $vendors,
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
            'unit' => $data['unit'],
            'recurrence' => $data['recurrence'],
            'vendor_id' => $data['vendor_id'],
            'note' => $data['note'],
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
