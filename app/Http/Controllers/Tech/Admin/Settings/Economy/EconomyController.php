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
        // Fetch economy settings (can be empty list)
        // -----------------------------------------
        $economySettings = common_settings::where('type', 'economy')->get();

        // -----------------------------------------
        // Return view - Economy settings overview
        // -----------------------------------------
        return view('tech.admin.settings.economy.index', [
            'economySettings' => $economySettings,
            'sidebarMenuItems' => $sidebarMenuItems,
        ]);
    }
}
