<?php

namespace App\Modules\Report\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Report\Actions\ExportTimeReport;
use App\Modules\Report\Queries\TimeReport;
use App\Modules\Report\Queries\TimeReportLookups;
use App\Modules\Report\Support\TimeReportAccess;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Ticket\Models\TicketStatus;
use Illuminate\Http\Request;

/** Browser and personal API call the same authorized query and export implementation. */
class TimeReportController extends Controller
{
    public function index(Request $request, TimeReportAccess $access, TimeReport $query, TimeReportLookups $lookups)
    {
        $actor = $access->authorize($request);
        $report = $query->build($actor, $request->query());
        if ($report['filters']['format']) {
            if ($request->routeIs('api.*')) {
                abort_unless($actor->tokenCan('reports.time.export'), 403);
            }

            return app(ExportTimeReport::class)->download($report, $report['filters']['format']);
        }
        $result = $query->page($report);
        if ($request->routeIs('api.*')) {
            return response()->json($result)->header('Cache-Control', 'private, no-store');
        }
        $selections = [];
        foreach (['employee', 'client', 'ticket', 'task'] as $kind) {
            $selections[$kind] = $lookups->selected($actor, $kind, $report['filters'][$kind.'_id']);
        }
        $statuses = collect(['ticket' => TicketStatus::query()->orderBy('name')->get(['id', 'name']),
            'task' => TaskStatus::query()->orderBy('name')->get(['id', 'name'])]);

        return response()->view('report::Tech.time', compact('result', 'selections', 'statuses'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function lookup(Request $request, string $kind, TimeReportAccess $access, TimeReportLookups $lookups)
    {
        $actor = $access->authorize($request);

        return response()->json($lookups->read($actor, $kind, $request->query()))->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, TimeReportAccess $access, TimeReport $query)
    {
        $actor = $access->authorize($request);
        $request->validate(['format' => ['required', 'in:xlsx,pdf']]);
        $report = $query->build($actor, $request->query());

        return app(ExportTimeReport::class)->download($report, $report['filters']['format']);
    }
}
