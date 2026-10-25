<?php

namespace App\Modules\Commercial\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Models\Economy\Units;
// Request unit
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

        return view('commercial::Admin.economy.units.index', compact('sidebarMenuItems', 'units'));
    }

    // ----------------------------------------------------------------------------------
    // STORE
    // Saves an emty row in units database fore editing
    // ----------------------------------------------------------------------------------
    public function store()
    {
        app(\App\Modules\Commercial\Actions\UnitCatalogueWorkflow::class)->store(request());

        return redirect()->route('tech.admin.settings.economy.units');
    }

    // ----------------------------------------------------------------------------------
    // UPDATE
    // Updates or deletes an unit
    // ----------------------------------------------------------------------------------
    public function update(Request $request, Units $unit)
    {
        app(\App\Modules\Commercial\Actions\UnitCatalogueWorkflow::class)->update($request, $unit);

        return redirect()->route('tech.admin.settings.economy.units');
    }
}
