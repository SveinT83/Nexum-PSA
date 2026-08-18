<?php

namespace App\Modules\Email\Controllers\Admin;

use App\Modules\Email\Actions\ApplyEmailCursorRebaseline;
use App\Modules\Email\Actions\CancelEmailProviderReconciliation;
use App\Modules\Email\Actions\CancelEmailHistoricalImport;
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
use App\Modules\Email\Services\EmailProviderReconciliationReadException;
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
        return view('email::Admin.Accounts.mailbox-maintenance', $query->forAccount($account, $request->user()));
    }

    public function previewHistoricalImport(
        EmailAccount $account,
        Request $request,
        PreviewEmailHistoricalImport $preview,
    ): RedirectResponse {
        $run = $preview->handle($account, $request->user(), $request->all());

        return redirect()
            ->route('tech.admin.settings.email.accounts.mailbox-maintenance', $account)
            ->with('success', "Historical import preview #{$run->id} is ready for confirmation.");
    }

    public function startHistoricalImport(
        EmailAccount $account,
        Request $request,
        StartEmailHistoricalImport $start,
    ): RedirectResponse {
        $request->validate([
            'historical_import_run_id' => ['required', 'integer'],
            'preview_fingerprint' => ['required', 'string', 'size:64'],
        ]);
        $run = EmailHistoricalImportRun::query()
            ->whereKey($request->integer('historical_import_run_id'))
            ->where('account_id', $account->id)
            ->firstOrFail();
        $queued = $start->handle($account, $run, $request->user(), $request->string('preview_fingerprint')->toString());

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
        abort_unless((int) $run->account_id === (int) $account->id, 404);
        $cancelled = $cancel->handle($account, $run, $request->user());

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
        $authorization->authorize($request->user(), $account);

        try {
            $run = $start->handle(
                $account,
                EmailProviderReconciliationRun::TRIGGER_MANUAL,
                $request->user(),
            );
        } catch (EmailProviderReconciliationReadException $exception) {
            return redirect()
                ->route('tech.admin.settings.email.accounts.mailbox-maintenance', $account)
                ->withErrors(['reconciliation' => $exception->safeCode]);
        }

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
        abort_unless((int) $run->account_id === (int) $account->id, 404);
        $authorization->authorize($request->user(), $account);
        $cancelled = $cancel->handle($account, $run, $request->user());

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
        abort_unless((int) $folder->account_id === (int) $account->id, 404);
        $run = $preview->handle($account, $folder, $request->user(), (string) $request->input('reason'));

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
        abort_unless((int) $folder->account_id === (int) $account->id, 404);
        $request->validate([
            'cursor_rebaseline_run_id' => ['required', 'integer'],
            'preview_fingerprint' => ['required', 'string', 'size:64'],
            'old_uid_validity' => ['required', 'integer', 'min:0'],
            'observed_uid_validity' => ['required', 'integer', 'min:1'],
            'observed_uid_next' => ['required', 'integer', 'min:1'],
        ]);
        $run = EmailCursorRebaselineRun::query()
            ->whereKey($request->integer('cursor_rebaseline_run_id'))
            ->where('account_id', $account->id)
            ->where('email_folder_id', $folder->id)
            ->firstOrFail();
        $completed = $apply->handle(
            $account,
            $folder,
            $run,
            $request->user(),
            $request->string('preview_fingerprint')->toString(),
            $request->only(['old_uid_validity', 'observed_uid_validity', 'observed_uid_next']),
        );

        return redirect()
            ->route('tech.admin.settings.email.accounts.mailbox-maintenance', $account)
            ->with('success', "Cursor re-baseline #{$completed->id} is {$completed->status}.");
    }
}
