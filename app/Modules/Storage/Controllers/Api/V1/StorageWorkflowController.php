<?php

namespace App\Modules\Storage\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Storage\Actions\StorageApiWorkflow;
use App\Modules\Storage\Queries\StorageApiQuery;
use Illuminate\Http\Request;

/** Transport entry points only; domain orchestration and projections stay shared. */
class StorageWorkflowController extends Controller
{
    public function read(Request $request, StorageApiQuery $query)
    {
        return $query->handle($request);
    }

    public function write(Request $request, StorageApiWorkflow $workflow)
    {
        return $workflow->handle($request);
    }
}
