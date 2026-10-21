<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailHistoricalImportRun;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Typed operator adapters over the same bounded, current-authority Actions as the browser. */
final class MailboxMaintenanceApiController extends Controller
{
    public function index(Request $request, EmailAccount $account, \App\Modules\Email\Services\EmailMailboxMaintenanceOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'index'], ['request' => $request, 'account' => $account]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function previewHistoricalImport(Request $request, EmailAccount $account, \App\Modules\Email\Services\EmailMailboxMaintenanceOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'previewHistoricalImport'], ['request' => $request, 'account' => $account]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function startHistoricalImport(Request $request, EmailAccount $account, \App\Modules\Email\Services\EmailMailboxMaintenanceOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'startHistoricalImport'], ['request' => $request, 'account' => $account]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function cancelHistoricalImport(Request $request, EmailAccount $account, EmailHistoricalImportRun $run, \App\Modules\Email\Services\EmailMailboxMaintenanceOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'cancelHistoricalImport'], ['request' => $request, 'account' => $account, 'run' => $run]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function startProviderReconciliation(Request $request, EmailAccount $account, \App\Modules\Email\Services\EmailMailboxMaintenanceOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'startProviderReconciliation'], ['request' => $request, 'account' => $account]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function cancelProviderReconciliation(Request $request, EmailAccount $account, EmailProviderReconciliationRun $run, \App\Modules\Email\Services\EmailMailboxMaintenanceOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'cancelProviderReconciliation'], ['request' => $request, 'account' => $account, 'run' => $run]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function previewCursorRebaseline(Request $request, EmailAccount $account, EmailFolder $folder, \App\Modules\Email\Services\EmailMailboxMaintenanceOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'previewCursorRebaseline'], ['request' => $request, 'account' => $account, 'folder' => $folder]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function applyCursorRebaseline(Request $request, EmailAccount $account, EmailFolder $folder, \App\Modules\Email\Services\EmailMailboxMaintenanceOperations $operations, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $access->actor($request);
        $data = app()->call([$operations, 'applyCursorRebaseline'], ['request' => $request, 'account' => $account, 'folder' => $folder]);

        return response()->json(['data' => $project->value($data)]);
    }

    public function historicalStatus(Request $request, EmailAccount $account, \App\Modules\Email\Models\EmailHistoricalImportRun $run, \App\Modules\Email\Services\EmailMailboxMaintenanceAuthorization $authorization, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $authorization->authorize($access->actor($request), $account);
        abort_unless((int) $run->account_id === (int) $account->id, 404);

        return response()->json(['data' => $project->value($run)]);
    }

    public function reconciliationStatus(Request $request, EmailAccount $account, \App\Modules\Email\Models\EmailProviderReconciliationRun $run, \App\Modules\Email\Services\EmailMailboxMaintenanceAuthorization $authorization, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $authorization->authorize($access->actor($request), $account);
        abort_unless((int) $run->account_id === (int) $account->id, 404);

        return response()->json(['data' => $project->value($run)]);
    }

    public function rebaselineStatus(Request $request, EmailAccount $account, \App\Modules\Email\Models\EmailCursorRebaselineRun $run, \App\Modules\Email\Services\EmailMailboxMaintenanceAuthorization $authorization, \App\Modules\Email\Support\EmailApiAccess $access, \App\Modules\Email\Support\EmailApiProjection $project)
    {
        $authorization->authorize($access->actor($request), $account);
        abort_unless((int) $run->account_id === (int) $account->id, 404);

        return response()->json(['data' => $project->value($run)]);
    }
}
