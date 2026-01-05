<?php

namespace App\Http\Controllers\Tech\CS\Contracts;

use App\Http\Controllers\Controller;

class ContractController extends Controller
{
    // -----------------------------------------
    // INDEX - Show a list of all contracts
    // -----------------------------------------
    public function index()
    {
        return view('tech.cs.contracts.index');
    }

    // -----------------------------------------
    // CREATE - Show Create form
    // -----------------------------------------
    public function create()
    {
        return view('tech.cs.contracts.create');
    }
}
