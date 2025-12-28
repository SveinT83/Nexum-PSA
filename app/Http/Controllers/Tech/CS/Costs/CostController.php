<?php

namespace App\Http\Controllers\Tech\CS\Costs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tech\CS\StoreCostRequest;
use App\Models\CS\Cost;

class CostController extends Controller
{

    // -----------------------------------------
    // INDEX - Show a list of all costs
    // -----------------------------------------
    public function index()
    {
        //Henter alle cost rader
        $costs = Cost::with(['creator', 'updater'])->orderBy('name')->get();

        return view('Tech.cs.costs.index', [
            'costs' => $costs,
        ]);
    }

    // -----------------------------------------
    // CREATE - Show form to create a new cost
    // -----------------------------------------
    public function create()
    {

        //Visningsfil
        return view('Tech.cs.costs.form');
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
            'created_by_user_id' => auth()->id(),
            'updated_by_user_id' => auth()->id(),
        ]);

        return redirect()->route('tech.costs.index')->with('success', 'Cost created successfully');
    }
}
