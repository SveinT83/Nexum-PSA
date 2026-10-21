<?php

namespace App\Modules\Email\Controllers\Admin;

use App\Modules\Email\Actions\ResolveEmailTicketCorrelationConflict;
use App\Modules\Email\Models\EmailTicketCorrelationConflict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class TicketCorrelationConflictController extends Controller
{
    public function index(): View
    {
        $data = app()->call([app(\App\Modules\Email\Services\EmailTicketConflictOperations::class), 'index'], []);

        return view('email::Admin.TicketCorrelationConflicts.index', $data);
    }

    public function resolve(
        EmailTicketCorrelationConflict $conflict,
        Request $request,
        ResolveEmailTicketCorrelationConflict $resolve,
    ): RedirectResponse {
        $data = app()->call([app(\App\Modules\Email\Services\EmailTicketConflictOperations::class), 'resolve'], ['request' => $request, 'conflict' => $conflict]);
        $conflict = $data['conflict'];

        return redirect()
            ->route('tech.admin.settings.email.ticket-correlation-conflicts.index')
            ->with('status', 'The Email was linked to the selected Ticket and the decision was audited.');
    }
}
