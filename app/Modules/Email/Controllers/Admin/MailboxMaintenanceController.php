<?php

namespace App\Modules\Email\Controllers\Admin;

use App\Modules\Email\Actions\ApplyEmailCursorRebaseline;
use App\Modules\Email\Actions\CancelEmailHistoricalImport;
use App\Modules\Email\Actions\CancelEmailProviderReconciliation;
use App\Modules\Email\Actions\PreviewEmailCursorRebaseline;
use App\Modules\Email\Actions\PreviewEmailHistoricalImport;
use App\Modules\Email\Actions\StartEmailHistoricalImport;
use App\Modules\Email\Actions\StartEmailProviderReconciliation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailCursorRebaselineRun;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailHistoricalImportRun;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Queries\EmailMailboxMaintenanceQuery;
use App\Modules\Email\Services\EmailMailboxMaintenanceAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class MailboxMaintenanceController extends Controller
{
    public function index(
        EmailAccount $account,
        Request $request,
        EmailMailboxMaintenanceQuery $query,
    ): View {
        $data = app()->call([app(\App\Modules\Email\Services\EmailMailboxMaintenanceOperations::class), 'index'], ['request' => $request, 'account' => $account]);

        return view('email::Admin.Accounts.mailbox-maintenance', $data);
    }

    public function previewHistoricalImport(
        EmailAccount $account,
        Request $request,
        PreviewEmailHistoricalImport $preview,
    ): RedirectResponse {
        $data = app()->call([app(\App\Modules\Email\Services\EmailMailboxMaintenanceOperations::class), 'previewHistoricalImport'], ['request' => $request, 'account' => $account]);
        $run = $data['run'];

        return redirect()
            ->route('tech.admin.settings.email.accounts.mailbox-maintenance', $account)
            ->with('success', "Historical import preview #{$run->id} is ready for confirmation.");
    }

    public function startHistoricalImport(
        EmailAccount $account,
        Request $request,
        StartEmailHistoricalImport $start,
    ): RedirectResponse {
        $data = app()->call([app(\App\Modules\Email\Services\EmailMailboxMaintenanceOperations::class), 'startHistoricalImport'], ['request' => $request, 'account' => $account]);
        $queued = $data['queued'];

        return redirect()
            ->route('tech.admin.settings.email.accounts.mailbox-maintenance', $account)
            ->with('success', "Historical import #{$queued->id} is {$queued->status}.");
    }

    public function cancelHistoricalImport(
        EmailAccount $account,
        EmailHistoricalImportRun $run,
        Request $request,
        CancelEmailHistoricalImport $cancel,
    ): RedirectResponse {
        $data = app()->call([app(\App\Modules\Email\Services\EmailMailboxMaintenanceOperations::class), 'cancelHistoricalImport'], ['request' => $request, 'account' => $account, 'run' => $run]);
        $cancelled = $data['cancelled'];

        return redirect()
            ->route('tech.admin.settings.email.accounts.mailbox-maintenance', $account)
            ->with('success', "Historical import #{$cancelled->id} will stop between batches.");
    }

    public function startProviderReconciliation(
        EmailAccount $account,
        Request $request,
        EmailMailboxMaintenanceAuthorization $authorization,
        StartEmailProviderReconciliation $start,
    ): RedirectResponse {
        $data = app()->call([app(\App\Modules\Email\Services\EmailMailboxMaintenanceOperations::class), 'startProviderReconciliation'], ['request' => $request, 'account' => $account]);
        $run = $data['run'];

        return redirect()
            ->route('tech.admin.settings.email.accounts.mailbox-maintenance', $account)
            ->with('success', "Provider reconciliation #{$run->id} is {$run->status}.");
    }

    public function cancelProviderReconciliation(
        EmailAccount $account,
        EmailProviderReconciliationRun $run,
        Request $request,
        EmailMailboxMaintenanceAuthorization $authorization,
        CancelEmailProviderReconciliation $cancel,
    ): RedirectResponse {
        $data = app()->call([app(\App\Modules\Email\Services\EmailMailboxMaintenanceOperations::class), 'cancelProviderReconciliation'], ['request' => $request, 'account' => $account, 'run' => $run]);
        $cancelled = $data['cancelled'];

        return redirect()
            ->route('tech.admin.settings.email.accounts.mailbox-maintenance', $account)
            ->with('success', "Provider reconciliation #{$cancelled->id} will stop between bounded batches.");
    }

    public function previewCursorRebaseline(
        EmailAccount $account,
        EmailFolder $folder,
        Request $request,
        PreviewEmailCursorRebaseline $preview,
    ): RedirectResponse {
        $data = app()->call([app(\App\Modules\Email\Services\EmailMailboxMaintenanceOperations::class), 'previewCursorRebaseline'], ['request' => $request, 'account' => $account, 'folder' => $folder]);
        $run = $data['run'];

        return redirect()
            ->route('tech.admin.settings.email.accounts.mailbox-maintenance', $account)
            ->with(
                $run->status === EmailCursorRebaselineRun::STATUS_PREVIEWED ? 'success' : 'warning',
                "Cursor re-baseline preview #{$run->id} is {$run->status}.",
            );
    }

    public function applyCursorRebaseline(
        EmailAccount $account,
        EmailFolder $folder,
        Request $request,
        ApplyEmailCursorRebaseline $apply,
    ): RedirectResponse {
        $data = app()->call([app(\App\Modules\Email\Services\EmailMailboxMaintenanceOperations::class), 'applyCursorRebaseline'], ['request' => $request, 'account' => $account, 'folder' => $folder]);
        $completed = $data['completed'];

        return redirect()
            ->route('tech.admin.settings.email.accounts.mailbox-maintenance', $account)
            ->with('success', "Cursor re-baseline #{$completed->id} is {$completed->status}.");
    }
}
