<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailHistoricalImportRun;
use App\Modules\Email\Models\EmailProviderInventoryRun;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailSentReconciliation;
use Illuminate\Database\Eloquent\Builder;

class EmailCursorRebaselineBlockers
{
    /** @return array<int, string> */
    public function forFolder(EmailFolder $folder): array
    {
        $codes = [];

        $activeImport = EmailHistoricalImportRun::query()
            ->where('account_id', $folder->account_id)
            ->whereIn('status', [
                EmailHistoricalImportRun::STATUS_QUEUED,
                EmailHistoricalImportRun::STATUS_RUNNING,
                EmailHistoricalImportRun::STATUS_CANCELLING,
            ])
            ->get(['folder_scope_json'])
            ->contains(fn (EmailHistoricalImportRun $run): bool => collect($run->folder_scope_json ?? [])
                ->contains(fn (array $scope): bool => (int) ($scope['folder_id'] ?? 0) === (int) $folder->id));
        if ($activeImport) {
            $codes[] = 'ACTIVE_HISTORICAL_IMPORT';
        }

        $unresolvedOperation = EmailRemoteOperation::query()
            ->where('account_id', $folder->account_id)
            ->where(function (Builder $scope) use ($folder): void {
                $scope->where('email_folder_id', $folder->id)
                    ->orWhereHas('placement', fn (Builder $placement) => $placement
                        ->where('email_folder_id', $folder->id));
            })
            ->where(function (Builder $status): void {
                $status->whereIn('status', [
                    EmailRemoteOperation::STATUS_PENDING,
                    EmailRemoteOperation::STATUS_RUNNING,
                ])->orWhere(function (Builder $failed): void {
                    $failed->where('status', EmailRemoteOperation::STATUS_FAILED)
                        ->where(function (Builder $uncertain): void {
                            $uncertain->where('failure_classification', EmailRemoteOperation::FAILURE_AMBIGUOUS)
                                ->orWhere(function (Builder $reconciliation): void {
                                    $reconciliation->whereNotNull('reconciliation_required_at')
                                        ->whereNull('reconciled_at');
                                });
                        });
                });
            })
            ->exists();
        if ($unresolvedOperation) {
            $codes[] = 'UNRESOLVED_REMOTE_OPERATION';
        }

        $unresolvedDraft = EmailComposerDraft::query()
            ->where('email_account_id', $folder->account_id)
            ->where('provider_draft_folder_path', $folder->path)
            ->where(function (Builder $status): void {
                $status->whereIn('provider_draft_status', [
                    EmailComposerDraft::PROVIDER_DRAFT_PENDING,
                    EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVED,
                    EmailComposerDraft::PROVIDER_DRAFT_APPEND_STARTED,
                ])->orWhere(function (Builder $ambiguous): void {
                    $ambiguous->where('provider_draft_status', EmailComposerDraft::PROVIDER_DRAFT_ERROR)
                        ->where('provider_draft_error_code', EmailComposerDraft::PROVIDER_DRAFT_APPEND_OUTCOME_UNRESOLVED);
                });
            })
            ->exists();
        if ($unresolvedDraft) {
            $codes[] = 'UNRESOLVED_PROVIDER_DRAFT';
        }

        $unresolvedSent = EmailSentReconciliation::query()
            ->where('account_id', $folder->account_id)
            ->where('sent_email_folder_id', $folder->id)
            ->where('status', '!=', EmailSentReconciliation::STATUS_RECONCILED)
            ->exists();
        if ($unresolvedSent) {
            $codes[] = 'UNRESOLVED_SENT_RECONCILIATION';
        }

        if (EmailProviderInventoryRun::query()
            ->where('account_id', $folder->account_id)
            ->where('status', EmailProviderInventoryRun::STATUS_RUNNING)
            ->exists()) {
            $codes[] = 'ACTIVE_PROVIDER_INVENTORY';
        }

        sort($codes);

        return array_values(array_unique($codes));
    }
}
