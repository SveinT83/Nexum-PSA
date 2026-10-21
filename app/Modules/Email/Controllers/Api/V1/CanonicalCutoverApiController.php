<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Typed operator adapters over the same bounded, current-authority Actions as the browser. */
final class CanonicalCutoverApiController extends Controller
{
    public function index(Request $request, \App\Modules\Email\Services\EmailCanonicalCutoverOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'index'], ['request' => $request]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function backfill(Request $request, \App\Modules\Email\Services\EmailCanonicalCutoverOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'backfill'], ['request' => $request]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function audit(Request $request, \App\Modules\Email\Services\EmailCanonicalCutoverOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'audit'], ['request' => $request]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function merge(Request $request, \App\Modules\Email\Services\EmailCanonicalCutoverOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'merge'], ['request' => $request]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function mode(Request $request, \App\Modules\Email\Services\EmailCanonicalCutoverOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'mode'], ['request' => $request]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function show(Request $request, string $run, \App\Modules\Email\Services\EmailCanonicalCutoverOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'show'], ['request' => $request, 'run' => $run]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function apply(Request $request, string $run, \App\Modules\Email\Services\EmailCanonicalCutoverOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'apply'], ['request' => $request, 'run' => $run]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function rollback(Request $request, string $run, \App\Modules\Email\Services\EmailCanonicalCutoverOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'rollback'], ['request' => $request, 'run' => $run]);

        return response()->json(['data' => $project->value($data)]);
    }
}
