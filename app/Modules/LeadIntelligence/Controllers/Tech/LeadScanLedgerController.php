<?php

namespace App\Modules\LeadIntelligence\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\LeadIntelligence\Models\LeadScanLedger;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeadScanLedgerController extends Controller
{
    public function index(Request $request): View
    {
        $query = LeadScanLedger::query()->latest();

        if ($request->boolean('due_only')) {
            $query->due();
        }

        if ($request->filled('domain')) {
            $query->where('domain', trim((string) $request->input('domain')));
        }

        if ($request->filled('status')) {
            $query->where('status', trim((string) $request->input('status')));
        }

        return view('leadintelligence::Tech.ledger.index', [
            'entries' => $query->paginate(25)->withQueryString(),
            'filters' => [
                'domain' => $request->input('domain'),
                'status' => $request->input('status'),
                'due_only' => $request->boolean('due_only'),
            ],
        ]);
    }
}

