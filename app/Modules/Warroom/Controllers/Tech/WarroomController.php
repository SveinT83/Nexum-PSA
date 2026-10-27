<?php

namespace App\Modules\Warroom\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Warroom\Queries\WarroomDashboardQuery;
use App\Modules\Warroom\Support\WarroomSettings;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WarroomController extends Controller
{
    /** The browser consumes the same source-authorized operations snapshot as the API. */
    public function __invoke(Request $request, WarroomDashboardQuery $query, WarroomSettings $settings): View
    {
        return view('warroom::Tech.dashboard', ['warroom' => $query->forUser($request->user()), 'settings' => $settings]);
    }
}
