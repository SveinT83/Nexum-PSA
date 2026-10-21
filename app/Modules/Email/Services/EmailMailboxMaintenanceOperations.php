<?php

namespace App\Modules\Email\Services;

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
use Illuminate\Http\Request;

final class EmailMailboxMaintenanceOperations
{
    public function index(
        EmailAccount $account,
        Request $request,
        EmailMailboxMaintenanceQuery $query,
    ): array {
        return $query->forAccount($account, $request->user());
    }

    public function previewHistoricalImport(
        EmailAccount $account,
        Request $request,
        PreviewEmailHistoricalImport $preview,
    ): array {
        $run = $preview->handle($account, $request->user(), $request->all());

        return ['run' => $run->fresh()];
    }

    public function startHistoricalImport(
        EmailAccount $account,
        Request $request,
        StartEmailHistoricalImport $start,
    ): array {
        $request->validate([
            'historical_import_run_id' => ['required', 'integer'],
            'preview_fingerprint' => ['required', 'string', 'size:64'],
        ]);
        $run = EmailHistoricalImportRun::query()
            ->whereKey($request->integer('historical_import_run_id'))
            ->where('account_id', $account->id)
            ->firstOrFail();
        $queued = $start->handle($account, $run, $request->user(), $request->string('preview_fingerprint')->toString());

        return ['queued' => $queued->fresh()];
    }

    public function cancelHistoricalImport(
        EmailAccount $account,
        EmailHistoricalImportRun $run,
        Request $request,
        CancelEmailHistoricalImport $cancel,
    ): array {
        abort_unless((int) $run->account_id === (int) $account->id, 404);
        $cancelled = $cancel->handle($account, $run, $request->user());

        return ['cancelled' => $cancelled->fresh()];
    }

    public function startProviderReconciliation(
        EmailAccount $account,
        Request $request,
        EmailMailboxMaintenanceAuthorization $authorization,
        StartEmailProviderReconciliation $start,
    ): array {
        $authorization->authorize($request->user(), $account);

        try {
            $run = $start->handle(
                $account,
                EmailProviderReconciliationRun::TRIGGER_MANUAL,
                $request->user(),
            );
        } catch (EmailProviderReconciliationReadException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['reconciliation' => $exception->safeCode]);
        }

        return ['run' => $run->fresh()];
    }

    public function cancelProviderReconciliation(
        EmailAccount $account,
        EmailProviderReconciliationRun $run,
        Request $request,
        EmailMailboxMaintenanceAuthorization $authorization,
        CancelEmailProviderReconciliation $cancel,
    ): array {
        abort_unless((int) $run->account_id === (int) $account->id, 404);
        $authorization->authorize($request->user(), $account);
        $cancelled = $cancel->handle($account, $run, $request->user());

        return ['cancelled' => $cancelled->fresh()];
    }

    public function previewCursorRebaseline(
        EmailAccount $account,
        EmailFolder $folder,
        Request $request,
        PreviewEmailCursorRebaseline $preview,
    ): array {
        abort_unless((int) $folder->account_id === (int) $account->id, 404);
        $run = $preview->handle($account, $folder, $request->user(), (string) $request->input('reason'));

        return ['run' => $run->fresh()];
    }

    public function applyCursorRebaseline(
        EmailAccount $account,
        EmailFolder $folder,
        Request $request,
        ApplyEmailCursorRebaseline $apply,
    ): array {
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

        return ['completed' => $completed->fresh()];
    }
}
