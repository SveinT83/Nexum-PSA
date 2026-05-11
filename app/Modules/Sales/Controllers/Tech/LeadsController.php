<?php

namespace App\Modules\Sales\Controllers\Tech;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class LeadsController extends Controller
{
    public function index(): View
    {
        return view('sales::Tech.Sales.leads.index');
    }

    public function show(string $lead): View
    {
        return view('sales::Tech.Sales.leads.show', [
            'lead' => $lead,
        ]);
    }
}
