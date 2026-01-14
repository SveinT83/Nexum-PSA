<?php

namespace App\Http\Controllers\Tech\Admin\Settings\Economy;

use App\Http\Controllers\Controller;
use App\Models\common_settings;
use Illuminate\Http\Request;
use App\Http\Requests\common_settingsRequest;

class EconomyController extends Controller
{

    // ----------------------------------------------------------------------------------
    // INDEX
    // Show the Economy Dashboard
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
        // Fetch economy settings
        // -----------------------------------------
        $vat = trim(common_settings::where('type', 'economy')
            ->where('name', 'vat')
            ->value('value') ?? '') ?: 25;

        // -----------------------------------------
        // Return view - Economy settings overview
        // -----------------------------------------
        return view('tech.admin.settings.economy.index', [
            'vat' => $vat,
            'sidebarMenuItems' => $sidebarMenuItems,
        ]);
    }

    // ----------------------------------------------------------------------------------
    // UPDATE
    // Saves the update and return Economy Dashboard whit message
    // ----------------------------------------------------------------------------------
    public function update(Request $request)
    {
        if ($request->input('name') === 'vat') {

        }

        if ($request->input('name') === 'maxPrice') {

        }

    }
}
