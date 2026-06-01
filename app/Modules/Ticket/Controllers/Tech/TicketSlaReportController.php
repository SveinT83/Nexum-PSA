<?php

namespace App\Modules\Ticket\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Ticket\Queries\TicketSlaReportQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TicketSlaReportController extends Controller
{
    public function index(Request $request, TicketSlaReportQuery $report): View
    {
        $period = $request->input('period', '30');
        $from = match ($period) {
            '7' => now()->subDays(7),
            '90' => now()->subDays(90),
            'all' => null,
            default => now()->subDays(30),
        };

        return view('ticket::Tech.Reports.sla', [
            'period' => $period,
            'summary' => $report->summary($from),
            'overdueTickets' => $report->overdueTickets(),
        ]);
    }
}
