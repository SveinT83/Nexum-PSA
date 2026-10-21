<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Typed operator adapters over the same bounded, current-authority Actions as the browser. */
final class CanonicalCorrelationApiController extends Controller
{
    public function index(Request $request, \App\Modules\Email\Services\EmailCanonicalCorrelationOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'index'], ['request' => $request]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function store(Request $request, \App\Modules\Email\Services\EmailCanonicalCorrelationOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'store'], ['request' => $request]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function show(Request $request, string $run, \App\Modules\Email\Services\EmailCanonicalCorrelationOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'show'], ['request' => $request, 'run' => $run]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function inspect(Request $request, string $candidate, \App\Modules\Email\Services\EmailCanonicalCorrelationOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'inspect'], ['request' => $request, 'candidate' => $candidate]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function review(Request $request, string $candidate, \App\Modules\Email\Services\EmailCanonicalCorrelationOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'review'], ['request' => $request, 'candidate' => $candidate]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function resume(Request $request, string $run, \App\Modules\Email\Services\EmailCanonicalCorrelationOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'resume'], ['request' => $request, 'run' => $run]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function cancel(Request $request, string $run, \App\Modules\Email\Services\EmailCanonicalCorrelationOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'cancel'], ['request' => $request, 'run' => $run]);

        return response()->json(['data' => $project->value($data)]);
    }
}
