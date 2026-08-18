<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Contracts\EmailProviderReconciliationMessageStore;
use App\Modules\Email\Contracts\EmailProviderReconciliationReader;
use App\Modules\Email\DTOs\EmailPlacementCreateResult;
use App\Modules\Email\DTOs\EmailProviderReconciliationStoredMessage;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EmailProviderReconciliationImporter
{
    private const MAX_ATTEMPTS = 10;

    public const ABANDONED_CLAIM_SECONDS = 120;

    public function __construct(
        private readonly EmailProviderReconciliationBindingPolicy $bindings,
        private readonly EmailProviderRemoteOperationObserver $operations,
        private readonly EmailProviderMessageIdentity $identities,
        private readonly EmailProviderDraftSyncService $drafts,
        private readonly EmailSentReconciliationService $sent,
        private readonly EmailConversationProjector $conversations,
    ) {}

    /**
     * Fetch and locally store one exact UID with BODY.PEEK. The storage seam
     * has no provider-mutation option, so this flow cannot invoke APPEND,
     * STORE, MOVE, COPY, deleteByUid, or any folder write.
     */
    public function importOne(
        EmailProviderReconciliationItem $item,
        EmailProviderReconciliationReader $reader,
        EmailProviderReconciliationMessageStore $store,
    ): string {
        $claimed = $this->claim($item);
        if (! $claimed) {
            return $item->fresh()?->status ?? EmailProviderReconciliationItem::STATUS_STALE;
        }

        $folderRun = EmailProviderReconciliationFolder::query()->findOrFail(
            $claimed->email_provider_reconciliation_folder_id,
        );
        $run = EmailProviderReconciliationRun::query()->findOrFail(
            $claimed->email_provider_reconciliation_run_id,
        );

        try {
            $this->bindings->currentAccount($run);
            $runtime = $reader->binding((int) $run->account_id, (int) $run->provider_binding_version);
            $run = $this->bindings->recordResolvedRuntime($run, $runtime);
            $this->assertActiveNamespace($folderRun);

            $peeked = $reader->messageByUidPeek(
                (int) $run->account_id,
                (int) $run->provider_binding_version,
                (string) $folderRun->folder_path,
                (int) $folderRun->expected_uid_validity,
                (int) $claimed->imap_uid,
                (int) $run->provider_time_cap_seconds,
            );
            if ($peeked === null) {
                return $this->finishWithoutMessage($claimed, 'provider_uid_missing_during_import');
            }

            // The provider read is outside the DB transaction. Revalidate the
            // exact claim after it returns so cancellation or a newer orphan
            // reclaim wins before any storage side effect begins.
            if (! $this->claimStillActive($claimed, $folderRun)) {
                return (string) (EmailProviderReconciliationItem::query()
                    ->whereKey($claimed->id)
                    ->value('status') ?? EmailProviderReconciliationItem::STATUS_STALE);
            }

            $folder = EmailFolder::query()->findOrFail($folderRun->email_folder_id);
            $stored = $store->store(
                runId: (int) $run->id,
                itemId: (int) $claimed->id,
                claimAttempt: (int) $claimed->attempt_count,
                accountId: (int) $run->account_id,
                folderId: (int) $folder->id,
                uidNamespaceId: (int) $folderRun->uid_namespace_id,
                uidValidity: (int) $folderRun->expected_uid_validity,
                uid: (int) $claimed->imap_uid,
                peeked: $peeked,
                runInboundRules: $folderRun->import_policy === EmailProviderReconciliationFolder::IMPORT_LIVE
                    && $folder->role === EmailFolder::ROLE_INBOX,
            );

            return $this->acceptStoredMessage(
                $claimed,
                $folderRun,
                $stored,
            );
        } catch (Throwable $exception) {
            $this->releaseClaim($claimed, $exception);

            throw $exception;
        }
    }

    private function claim(EmailProviderReconciliationItem $item): ?EmailProviderReconciliationItem
    {
        $reference = EmailProviderReconciliationItem::query()
            ->select([
                'id',
                'email_provider_reconciliation_run_id',
                'email_provider_reconciliation_folder_id',
            ])
            ->find($item->id);
        if (! $reference) {
            return null;
        }

        return DB::transaction(function () use ($reference): ?EmailProviderReconciliationItem {
            // Keep the same run -> folder -> item lock order as finalization so
            // cancellation and abandoned-claim recovery cannot deadlock.
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($reference->email_provider_reconciliation_run_id);
            $folder = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($reference->email_provider_reconciliation_folder_id);
            $locked = EmailProviderReconciliationItem::query()
                ->lockForUpdate()
                ->find($reference->id);
            if (! $locked || $locked->terminal()
                || $locked->kind !== EmailProviderReconciliationItem::KIND_IMPORT
                || ! in_array($locked->status, [
                    EmailProviderReconciliationItem::STATUS_PENDING,
                    EmailProviderReconciliationItem::STATUS_RUNNING,
                ], true)) {
                return null;
            }

            if (! $run || ! $folder
                || (int) $folder->email_provider_reconciliation_run_id !== (int) $run->id) {
                if ($run && ($run->phase === EmailProviderReconciliationRun::PHASE_SUMMARY
                    || $run->final_summary_status !== null)) {
                    return null;
                }
                $locked->forceFill([
                    'status' => EmailProviderReconciliationItem::STATUS_STALE,
                    'error_code' => 'provider_import_scope_missing',
                    'completed_at' => now(),
                ])->save();
                $run?->markAutomationScopeUnsafe();

                return null;
            }

            $failedFolderOwnsImport = (int) $run->active_slot === 1
                && $run->cancellation_requested_at === null
                && $run->final_summary_status === null
                && $folder->item_summary_status === null
                && $folder->status === EmailProviderReconciliationFolder::STATUS_FAILED
                && (int) $locked->email_provider_reconciliation_run_id === (int) $run->id
                && (int) $locked->email_provider_reconciliation_folder_id === (int) $folder->id
                && (($run->status === EmailProviderReconciliationRun::STATUS_RUNNING
                        && $run->phase === EmailProviderReconciliationRun::PHASE_SCAN)
                    || ($run->status === EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS
                        && $run->phase === EmailProviderReconciliationRun::PHASE_IMPORTS));
            if ($failedFolderOwnsImport) {
                $locked->forceFill([
                    'status' => EmailProviderReconciliationItem::STATUS_FAILED,
                    'error_code' => 'provider_import_folder_failed',
                    'completed_at' => now(),
                ])->save();
                $run->markAutomationScopeUnsafe();

                return null;
            }

            if (in_array($run->status, [
                EmailProviderReconciliationRun::STATUS_CANCELLING,
                EmailProviderReconciliationRun::STATUS_CANCELLED,
            ], true) || $run->cancellation_requested_at !== null) {
                return null;
            }

            if (! $this->activeImportScope($run, $folder)) {
                return null;
            }

            if ($locked->status === EmailProviderReconciliationItem::STATUS_RUNNING
                && $locked->last_attempt_at?->gt(now()->subSeconds(self::ABANDONED_CLAIM_SECONDS))) {
                return null;
            }

            if ((int) $locked->attempt_count >= self::MAX_ATTEMPTS) {
                $locked->forceFill([
                    'status' => EmailProviderReconciliationItem::STATUS_FAILED,
                    'error_code' => 'provider_import_attempt_limit',
                    'completed_at' => now(),
                ])->save();
                $run->markAutomationScopeUnsafe();

                return null;
            }

            $locked->forceFill([
                'status' => EmailProviderReconciliationItem::STATUS_RUNNING,
                'attempt_count' => (int) $locked->attempt_count + 1,
                'first_attempt_at' => $locked->first_attempt_at ?? now(),
                'last_attempt_at' => now(),
                'error_code' => null,
            ])->save();

            return $locked->refresh();
        }, 3);
    }

    private function finishWithoutMessage(EmailProviderReconciliationItem $item, string $code): string
    {
        return DB::transaction(function () use ($code, $item): string {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($item->email_provider_reconciliation_run_id);
            $folder = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($item->email_provider_reconciliation_folder_id);
            $locked = EmailProviderReconciliationItem::query()
                ->lockForUpdate()
                ->find($item->id);
            if (! $this->activeClaim($run, $folder, $locked, $item)) {
                return (string) ($locked?->status ?? EmailProviderReconciliationItem::STATUS_STALE);
            }

            $locked->forceFill([
                'status' => EmailProviderReconciliationItem::STATUS_STALE,
                'error_code' => $code,
                'completed_at' => now(),
            ])->save();
            $run->markAutomationScopeUnsafe();

            return EmailProviderReconciliationItem::STATUS_STALE;
        }, 3);
    }

    private function releaseClaim(EmailProviderReconciliationItem $item, Throwable $exception): void
    {
        $code = $exception instanceof EmailProviderReconciliationReadException
            ? $exception->safeCode
            : 'provider_import_failed';

        DB::transaction(function () use ($code, $item): void {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($item->email_provider_reconciliation_run_id);
            $folder = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($item->email_provider_reconciliation_folder_id);
            $locked = EmailProviderReconciliationItem::query()
                ->lockForUpdate()
                ->find($item->id);
            if (! $this->activeClaim($run, $folder, $locked, $item)) {
                return;
            }

            $locked->forceFill([
                'status' => EmailProviderReconciliationItem::STATUS_PENDING,
                'error_code' => $code,
                'last_attempt_at' => now(),
            ])->save();
        }, 3);
    }

    private function claimStillActive(
        EmailProviderReconciliationItem $claimed,
        EmailProviderReconciliationFolder $folderRun,
    ): bool {
        return DB::transaction(function () use ($claimed, $folderRun): bool {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($claimed->email_provider_reconciliation_run_id);
            $folder = EmailProviderReconciliationFolder::query()
                ->whereKey($folderRun->id)
                ->lockForUpdate()
                ->first();
            $item = EmailProviderReconciliationItem::query()
                ->lockForUpdate()
                ->find($claimed->id);

            return $this->activeClaim($run, $folder, $item, $claimed);
        }, 3);
    }

    private function activeClaim(
        ?EmailProviderReconciliationRun $run,
        ?EmailProviderReconciliationFolder $folder,
        ?EmailProviderReconciliationItem $item,
        EmailProviderReconciliationItem $claimed,
    ): bool {
        return $run
            && $folder
            && $item
            && (int) $run->active_slot === 1
            && $run->cancellation_requested_at === null
            && $this->activeImportScope($run, $folder)
            && (int) $folder->email_provider_reconciliation_run_id === (int) $run->id
            && (int) $item->email_provider_reconciliation_run_id === (int) $run->id
            && (int) $item->email_provider_reconciliation_folder_id === (int) $folder->id
            && $item->kind === EmailProviderReconciliationItem::KIND_IMPORT
            && $item->status === EmailProviderReconciliationItem::STATUS_RUNNING
            && (int) $item->attempt_count === (int) $claimed->attempt_count;
    }

    private function activeImportScope(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationFolder $folder,
    ): bool {
        if ((int) $run->active_slot !== 1
            || $run->cancellation_requested_at !== null
            || $run->final_summary_status !== null
            || $folder->item_summary_status !== null) {
            return false;
        }

        return ($run->status === EmailProviderReconciliationRun::STATUS_RUNNING
                && $run->phase === EmailProviderReconciliationRun::PHASE_SCAN
                && $folder->status === EmailProviderReconciliationFolder::STATUS_SCANNING)
            || ($run->status === EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS
                && $run->phase === EmailProviderReconciliationRun::PHASE_IMPORTS
                && $folder->status
                    === EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS);
    }

    private function assertActiveNamespace(EmailProviderReconciliationFolder $folderRun): void
    {
        $active = EmailFolderUidNamespace::query()
            ->whereKey($folderRun->uid_namespace_id)
            ->where('account_id', $folderRun->account_id)
            ->where('email_folder_id', $folderRun->email_folder_id)
            ->where('uid_validity', $folderRun->expected_uid_validity)
            ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
            ->exists();
        $folderPointsToNamespace = EmailFolder::query()
            ->whereKey($folderRun->email_folder_id)
            ->where('active_uid_namespace_id', $folderRun->uid_namespace_id)
            ->exists();

        if (! $active || ! $folderPointsToNamespace) {
            throw new EmailProviderReconciliationReadException('provider_uid_namespace_stale');
        }
    }

    private function acceptStoredMessage(
        EmailProviderReconciliationItem $claimed,
        EmailProviderReconciliationFolder $folderRun,
        EmailProviderReconciliationStoredMessage $stored,
    ): string {
        try {
            return DB::transaction(function () use ($claimed, $folderRun, $stored): string {
                // Preserve the run -> folder-run -> item -> placement lock
                // order shared by cancellation and finalization.
                $run = EmailProviderReconciliationRun::query()
                    ->lockForUpdate()
                    ->findOrFail($claimed->email_provider_reconciliation_run_id);
                $lockedFolder = EmailProviderReconciliationFolder::query()
                    ->whereKey($folderRun->id)
                    ->where('email_provider_reconciliation_run_id', $run->id)
                    ->where('account_id', $run->account_id)
                    ->lockForUpdate()
                    ->first();
                $item = EmailProviderReconciliationItem::query()
                    ->whereKey($claimed->id)
                    ->where('email_provider_reconciliation_run_id', $run->id)
                    ->lockForUpdate()
                    ->first();
                if (! $lockedFolder || ! $item) {
                    throw new EmailProviderReconciliationReadException(
                        'reconciliation_store_scope_mismatch',
                    );
                }
                if ($item->terminal()) {
                    return (string) $item->status;
                }
                if ($item->status !== EmailProviderReconciliationItem::STATUS_RUNNING) {
                    return (string) $item->status;
                }
                if ((int) $item->attempt_count !== (int) $claimed->attempt_count) {
                    return (string) $item->status;
                }
                if (in_array($run->status, [
                    EmailProviderReconciliationRun::STATUS_CANCELLING,
                    EmailProviderReconciliationRun::STATUS_CANCELLED,
                ], true) || $run->cancellation_requested_at !== null) {
                    return (string) $item->status;
                }
                if (! $this->activeImportScope($run, $lockedFolder)) {
                    return (string) $item->status;
                }

                $folder = EmailFolder::query()
                    ->whereKey($lockedFolder->email_folder_id)
                    ->where('account_id', $run->account_id)
                    ->where('active_uid_namespace_id', $lockedFolder->uid_namespace_id)
                    ->where('uid_validity', $lockedFolder->expected_uid_validity)
                    ->lockForUpdate()
                    ->first();
                $namespaceActive = EmailFolderUidNamespace::query()
                    ->whereKey($lockedFolder->uid_namespace_id)
                    ->where('account_id', $run->account_id)
                    ->where('email_folder_id', $lockedFolder->email_folder_id)
                    ->where('uid_validity', $lockedFolder->expected_uid_validity)
                    ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
                    ->exists();
                $placement = EmailMailboxPlacement::query()
                    ->whereKey($stored->placementId)
                    ->where('email_message_id', $stored->messageId)
                    ->where('account_id', $run->account_id)
                    ->where('email_folder_id', $lockedFolder->email_folder_id)
                    ->where('uid_namespace_id', $lockedFolder->uid_namespace_id)
                    ->where('imap_uid_validity', $lockedFolder->expected_uid_validity)
                    ->where('imap_uid', $item->imap_uid)
                    ->lockForUpdate()
                    ->first();
                $message = EmailMessage::query()
                    ->withTrashed()
                    ->whereKey($stored->messageId)
                    ->where('account_id', $run->account_id)
                    ->where('mailbox', $lockedFolder->folder_path)
                    ->where('imap_uid_validity', $lockedFolder->expected_uid_validity)
                    ->where('imap_uid', $item->imap_uid)
                    ->lockForUpdate()
                    ->first();
                if (! $folder || ! $namespaceActive || ! $placement || ! $message
                    || $message->trashed()
                    || (string) $folder->path !== (string) $lockedFolder->folder_path
                    || (int) $placement->sync_version !== $stored->placementSyncVersion
                    || $this->identities->forMessage($message) !== $stored->identityHash) {
                    return $this->markConflict(
                        $run,
                        $item,
                        $placement,
                        'reconciliation_store_scope_drift',
                    );
                }

                if ($this->operations->hasUnresolvedForPlacement((int) $placement->id)) {
                    return $this->markConflict(
                        $run,
                        $item,
                        $placement,
                        'reconciliation_store_operation_drift',
                    );
                }

                if ($stored->identityHash === null) {
                    // PREEXISTING is included deliberately: an ordinary poll
                    // can win the placement race without stamping this run's
                    // frozen observation columns.
                    $run->markAutomationScopeUnsafe();
                }

                if ($stored->placementDisposition === EmailPlacementCreateResult::PREEXISTING) {
                    if ($stored->placementSyncVersion !== 1
                        || $placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE
                        || $placement->sync_status !== EmailMailboxPlacement::SYNC_SYNCED
                        || $placement->provider_missing_at !== null
                        || $placement->sync_error_code !== null) {
                        return $this->markConflict(
                            $run,
                            $item,
                            $placement,
                            'reconciliation_store_preexisting_drift',
                        );
                    }

                    $this->completeItem(
                        $item,
                        $placement,
                        $stored,
                        EmailProviderReconciliationItem::STATUS_ALREADY_PRESENT,
                        historicalBaselineRequired: false,
                        automationRequired: false,
                    );

                    return EmailProviderReconciliationItem::STATUS_ALREADY_PRESENT;
                }

                $historicalBaselineRequired = $this->requiresHistoricalBaseline($placement);
                $storePending = $this->isStorePending($placement);
                if ($stored->placementSyncVersion !== 1
                    || $placement->local_state !== EmailMailboxPlacement::LOCAL_HIDDEN
                    || $placement->sync_status !== EmailMailboxPlacement::SYNC_PENDING
                    || $placement->provider_missing_at !== null
                    || (! $historicalBaselineRequired && ! $storePending)) {
                    return $this->markConflict(
                        $run,
                        $item,
                        $placement,
                        'reconciliation_store_pending_drift',
                    );
                }

                $automationRequired = ! $historicalBaselineRequired
                    && $lockedFolder->import_policy === EmailProviderReconciliationFolder::IMPORT_LIVE
                    && $folder->role === EmailFolder::ROLE_INBOX;
                if (! $historicalBaselineRequired) {
                    $now = now();
                    $placement->forceFill([
                        'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
                        'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
                        'provider_missing_at' => null,
                        'last_provider_reconciliation_run_id' => $run->id,
                        'last_provider_observed_sync_version' => max(1, (int) $placement->sync_version),
                        'last_provider_observed_identity_hash' => $stored->identityHash,
                        'last_provider_observed_at' => $now,
                        'last_reconciled_at' => $now,
                        'sync_error_code' => null,
                        'sync_error_message' => null,
                    ])->save();
                    $this->projectLocalDraftOrSent(
                        $run,
                        $lockedFolder,
                        $folder,
                        $placement->refresh(),
                    );
                    if (! $this->conversations->refreshForPlacement($placement->refresh())) {
                        throw new EmailProviderReconciliationReadException(
                            'reconciliation_conversation_projection_failed',
                        );
                    }
                } else {
                    $placement->forceFill([
                        'last_provider_reconciliation_run_id' => $run->id,
                        'last_provider_observed_sync_version' => max(1, (int) $placement->sync_version),
                        'last_provider_observed_identity_hash' => $stored->identityHash,
                        'last_provider_observed_at' => now(),
                        'last_reconciled_at' => now(),
                    ])->save();
                }

                $status = $historicalBaselineRequired
                    ? EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE
                    : EmailProviderReconciliationItem::STATUS_PROJECTED;
                $this->completeItem(
                    $item,
                    $placement->refresh(),
                    $stored,
                    $status,
                    $historicalBaselineRequired,
                    $automationRequired,
                );

                return $status;
            }, 3);
        } catch (EmailProviderReconciliationReadException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_local_projection_failed',
            );
        }
    }

    private function completeItem(
        EmailProviderReconciliationItem $item,
        EmailMailboxPlacement $placement,
        EmailProviderReconciliationStoredMessage $stored,
        string $status,
        bool $historicalBaselineRequired,
        bool $automationRequired,
    ): void {
        $now = now();
        $historicalBaselineMaxId = $historicalBaselineRequired
            ? (int) EmailAccountUserReadBaseline::query()
                ->where('email_account_id', $placement->account_id)
                ->max('id')
            : 0;
        $item->forceFill([
            'status' => $status,
            'result_placement_id' => $placement->id,
            'identity_hash' => $stored->identityHash,
            'placement_sync_version_before' => $stored->placementSyncVersion,
            'placement_sync_version_after' => max(1, (int) $placement->sync_version),
            'completed_at' => $historicalBaselineRequired ? null : $now,
            'error_code' => null,
            'historical_baseline_required' => $historicalBaselineRequired,
            'historical_baseline_status' => $historicalBaselineRequired
                ? EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING
                : null,
            'historical_baseline_max_id' => $historicalBaselineMaxId,
            'historical_baseline_cursor_id' => 0,
            'historical_baseline_claim_token' => null,
            'historical_baseline_attempt_count' => 0,
            'historical_baseline_frozen_at' => $historicalBaselineRequired ? $now : null,
            'historical_baseline_first_attempt_at' => null,
            'historical_baseline_last_attempt_at' => null,
            'historical_baseline_completed_at' => null,
            'historical_baseline_error_code' => null,
            'automation_required' => $automationRequired,
            'automation_status' => $automationRequired
                ? EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION
                : null,
            'automation_claim_token' => null,
            'automation_attempt_count' => 0,
            'automation_last_attempt_at' => null,
            'automation_completed_at' => null,
            'automation_error_code' => null,
            'automation_rule_attempt_floor_id' => null,
        ])->save();

        EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $item->email_provider_reconciliation_run_id)
            ->where('email_provider_reconciliation_folder_id', $item->email_provider_reconciliation_folder_id)
            ->where('uid_namespace_id', $item->uid_namespace_id)
            ->where('imap_uid', $item->imap_uid)
            ->where('kind', EmailProviderReconciliationItem::KIND_OBSERVATION)
            ->update([
                'source_placement_id' => $placement->id,
                'result_placement_id' => $placement->id,
                'identity_hash' => $stored->identityHash,
                'placement_sync_version_before' => max(1, (int) $placement->sync_version),
                'placement_sync_version_after' => max(1, (int) $placement->sync_version),
                'updated_at' => $now,
            ]);
    }

    private function markConflict(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationItem $item,
        ?EmailMailboxPlacement $placement,
        string $code,
    ): string {
        $item->forceFill([
            'status' => EmailProviderReconciliationItem::STATUS_CONFLICT,
            'result_placement_id' => $placement?->id,
            'error_code' => $code,
            'completed_at' => now(),
            'automation_required' => false,
            'automation_status' => null,
            'automation_claim_token' => null,
        ])->save();
        $run->markAutomationScopeUnsafe();

        return EmailProviderReconciliationItem::STATUS_CONFLICT;
    }

    private function projectLocalDraftOrSent(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationFolder $folderRun,
        EmailFolder $folder,
        EmailMailboxPlacement $placement,
    ): void {
        $arguments = [
            $placement,
            (int) $run->account_id,
            (int) $folderRun->email_folder_id,
            (int) $folderRun->uid_namespace_id,
            (int) $folderRun->expected_uid_validity,
            (int) $placement->imap_uid,
            (int) $run->provider_binding_version,
        ];
        if ($folder->role === EmailFolder::ROLE_DRAFTS) {
            $this->drafts->reconcileObservedPlacementLocally(...$arguments);
        }
        if ($folder->role === EmailFolder::ROLE_SENT) {
            $this->sent->reconcileObservedPlacementLocally(...$arguments);
        }
    }

    private function requiresHistoricalBaseline(EmailMailboxPlacement $placement): bool
    {
        return $placement->local_state === EmailMailboxPlacement::LOCAL_HIDDEN
            && $placement->sync_status === EmailMailboxPlacement::SYNC_PENDING
            && $placement->sync_error_code === EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE;
    }

    private function isStorePending(EmailMailboxPlacement $placement): bool
    {
        return $placement->local_state === EmailMailboxPlacement::LOCAL_HIDDEN
            && $placement->sync_status === EmailMailboxPlacement::SYNC_PENDING
            && $placement->sync_error_code === EmailProviderReconciliationStore::STORE_PENDING_CODE;
    }
}
