<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Modules\Email\Models\EmailTicketCorrelationConflict;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Typed operator adapters over the same bounded, current-authority Actions as the browser. */
final class TicketCorrelationConflictApiController extends Controller
{
    public function index(Request $request, \App\Modules\Email\Services\EmailTicketConflictOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'index'], []);

        return response()->json(['data' => $project->value($data)]);
    }

    public function resolve(Request $request, EmailTicketCorrelationConflict $conflict, \App\Modules\Email\Services\EmailTicketConflictOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'resolve'], ['request' => $request, 'conflict' => $conflict]);

        return response()->json(['data' => $project->value($data)]);
    }
}
