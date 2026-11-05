<?php

namespace App\Modules\Clients\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Clients\Actions\ExportClientTimeReport;
use App\Modules\Clients\Queries\ClientTimeReportQuery;
use App\Modules\Clients\Reports\ClientTimeReportDefinition;
use Illuminate\Http\Request;

/** Internal employee reporting only; no customer delivery or source mutations. */
class ClientTimeReportController extends Controller
{
    public function __invoke(Request $request, ClientTimeReportQuery $query, ExportClientTimeReport $export)
    {
        abort_unless(app(ClientTimeReportDefinition::class)->visibleTo($request->user()), 403);
        $input = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_filter(['nullable', 'date_format:Y-m-d', $request->filled('date_from') ? 'after_or_equal:date_from' : null]),
            'format' => ['nullable', 'in:xlsx,pdf'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $client = filled($input['client_id'] ?? null) ? Client::findOrFail($input['client_id']) : null;
        $report = $client ? $query->build($client, $request->user(), $input['date_from'] ?? null, $input['date_to'] ?? null) : null;
        if (filled($input['format'] ?? null)) {
            abort_unless($report, 422, 'Select a Client before exporting.');

            return $export->download($report, $input['format']);
        }
        // Search first rather than rendering an unbounded customer selector.
        $clients = collect();
        if (filled($input['q'] ?? null)) {
            $term = $input['q'];
            $clients = Client::query()->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('client_number', 'like', '%'.$term.'%')->orWhere('website', 'like', '%'.$term.'%'))
                ->orderBy('name')->limit(25)->get(['id', 'name', 'client_number']);
        }

        return response()->view('clients::Tech.time-report', compact('client', 'clients', 'report'))
            ->header('Cache-Control', 'private, no-store');
    }
}
