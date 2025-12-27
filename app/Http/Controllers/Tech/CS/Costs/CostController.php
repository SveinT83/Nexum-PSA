<?php

namespace App\Http\Controllers\Tech\CS\Costs;

use App\Http\Controllers\Controller;

class CostController extends Controller
{

    // -----------------------------------------
    // INDEX - Show a list of all costs
    // -----------------------------------------
    public function index()
    {
        return view('Tech.cs.costs.index');
    }

    // -----------------------------------------
    // CREATE - Show form to create a new cost
    // -----------------------------------------
    public function create()
    {
        return view('Tech.cs.costs.form');
    }
}
