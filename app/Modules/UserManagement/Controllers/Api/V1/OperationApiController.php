<?php

namespace App\Modules\UserManagement\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Models\UserManagementApiCommand;
use App\Modules\UserManagement\Support\UserManagementApiAccess;
use Illuminate\Http\Request;

/** Exact actor/token-bound read-back; receipt possession never grants original authority. */
class OperationApiController extends Controller
{
    public function show(Request $request, string $operation)
    {
        $row = UserManagementApiCommand::whereKey($operation)->where('actor_id', $request->user()->id)
            ->where('token_hash', UserManagementApiAccess::tokenHash($request))->firstOrFail();
        UserManagementApiAccess::authorize($request->user(), $row->result['permissions']);
        abort_unless($request->user()->tokenCan($row->result['ability']), 403);

        return response()->json(['data' => ['id' => $row->id, 'operation' => $row->operation,
            'state' => $row->state, 'http_status' => $row->result['status'], 'result' => $row->result['body']]]);
    }
}
