<?php

namespace App\Modules\Warroom\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Warroom\Queries\MyDayWorkQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MyDayController extends Controller
{
    /**
     * Show the signed-in technician's personal work queue for today.
     */
    public function __invoke(Request $request, MyDayWorkQuery $query): View
    {
        return view('warroom::Tech.my-day', [
            'myDay' => $query->forUser($request->user()),
        ]);
    }
}
