<?php

namespace App\Modules\Sales\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Sales\Actions\EnsureSalesDefaults;
use App\Modules\Sales\Actions\StoreSalesOpportunity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeadsController extends Controller
{
    public function index(Request $request, EnsureSalesDefaults $defaults): View
    {
        return view('sales::Tech.Sales.leads.index', app(\App\Modules\Sales\Actions\SalesLeadWorkflow::class)->index($request, $defaults));
    }

    public function updateClassification(Request $request, Client $lead): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesLeadWorkflow::class)->updateClassification($request, $lead);

        return back()->with($result->level, $result->message);
    }

    public function show(Client $lead): View
    {
        return view('sales::Tech.Sales.leads.show', [
            'lead' => $lead,
        ]);
    }

    public function start(Request $request, Client $lead, StoreSalesOpportunity $storeOpportunity): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesLeadWorkflow::class)->start($request, $lead, $storeOpportunity);

        return redirect()->route('tech.sales.show', \App\Modules\Sales\Models\SalesOpportunity::findOrFail($result->id))->with($result->level, $result->message);
    }
}
