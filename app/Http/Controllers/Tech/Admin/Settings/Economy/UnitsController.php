<?php

namespace App\Http\Controllers\Tech\Admin\Settings\Economy;

use App\Http\Controllers\Controller;
use App\Models\Economy\Units;
//Request unit
use Illuminate\Http\Request;

class UnitsController extends Controller
{
    // ----------------------------------------------------------------------------------
    // INDEX
    // Show the units form system
    // ----------------------------------------------------------------------------------
    public function index()
    {

        // -----------------------------------------
        // Array of sidebar menu items
        // -----------------------------------------
        $sidebarMenuItems = [
            ['name' => 'Dashboard', 'route' => 'tech.admin.settings.economy'],
            ['name' => 'Units', 'route' => 'tech.admin.settings.economy.units'],
        ];

        // -----------------------------------------
        // Fetch units from database
        // -----------------------------------------
        $units = Units::all();

        return view('tech.admin.settings.economy.units.index', compact('sidebarMenuItems', 'units'));
    }

    // ----------------------------------------------------------------------------------
    // STORE
    // Saves an emty row in units database fore editing
    // ----------------------------------------------------------------------------------
    public function store()
    {
        //Store an emty row in database, where name is xxx
        Units::create(['name' => 'xxx', 'short' => '']);

        //Redirect view to index.
        //Bug: The route do not work
        return redirect()->route('tech.admin.settings.economy.units');
    }

    // ----------------------------------------------------------------------------------
    // UPDATE
    // Updates or deletes an unit
    // ----------------------------------------------------------------------------------
    public function update(Request $request, Units $unit)
    {

        if ($request->input('action') === 'delete') {
            $unit->delete();
        } else {
            $unit->update($request->only(['name', 'short', 'code']));
        }

        return redirect()->route('tech.admin.settings.economy.units');
    }
}
