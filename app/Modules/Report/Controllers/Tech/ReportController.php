<?php

namespace App\Modules\Report\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Report\Support\ReportRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request, ReportRegistry $reports): View
    {
        return view('report::Tech.index', [
            'activeDomain' => $request->string('domain')->toString(),
            'domains' => $reports->domains(),
            'reports' => $reports->visibleFor($request->user(), $request->string('domain')->toString()),
            'totalReports' => $reports->visibleFor($request->user())->count(),
        ]);
    }
}
