<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Jobs\ProcessEmailProviderReconciliationAutomation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Support\Facades\DB;

/**
 * Release deferred live-Inbox automation only after account-wide evidence is
 * stable enough to distinguish a new delivery from a provider move or copy.
 */
final class EmailProviderReconciliationAutomationCorrelator
{
    public const BATCH_SIZE = 100;

    private const CANDIDATE_LIMIT = 3;

    private const AMBIGUOUS_LOCAL_STATUSES = [
        EmailProviderReconciliationFolder::STATUS_PENDING,
        EmailProviderReconciliationFolder::STATUS_SCANNING,
        EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
        EmailProviderReconciliationFolder::STATUS_COMPLETE,
        EmailProviderReconciliationFolder::STATUS_MISSING_CANDIDATE,
        EmailProviderReconciliationFolder::STATUS_STALE,
        EmailProviderReconciliationFolder::STATUS_BLOCKED,
        EmailProviderReconciliationFolder::STATUS_FAILED,
        EmailProviderReconciliationFolder::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly EmailProviderMessageIdentity $identities,
        private readonly EmailProviderRemoteOperationObserver $operations,
    ) {}

    /**
     * Advance at most one durable awaiting-correlation page.
     *
     * A committed PENDING row is the recovery authority. If the worker dies
     * before dispatch, the normal bounded finalizer recovery sweep queues it.
     */
    public function advance(EmailProviderReconciliationRun $run): bool
    {
        $result = DB::transaction(function () use ($run): array {
            $lockedRun = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($run->id);
            if (! $lockedRun || $lockedRun->terminal()
                || $lockedRun->status !== EmailProviderReconciliationRun::STATUS_RUNNING
                || $lockedRun->phase !== EmailProviderReconciliationRun::PHASE_DISCOVER_END
                || (int) $lockedRun->active_slot !== 1
                || $lockedRun->cancellation_requested_at !== null) {
                return ['processed' => false, 'pending_ids' => []];
            }

            $items = EmailProviderReconciliationItem::query()
                ->where('email_provider_reconciliation_run_id', $lockedRun->id)
                ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
                ->where('automation_required', true)
                ->where(
                    'automation_status',
                    EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION,
                )
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->lockForUpdate()
                ->get();
            if ($items->isEmpty()) {
                return ['processed' => false, 'pending_ids' => []];
            }

            $globallyStable = $this->globallyStable($lockedRun);
            $pendingIds = [];
            $now = now();
            foreach ($items as $item) {
                [$status, $code] = $globallyStable
                    ? $this->classify($lockedRun, $item)
                    : [
                        EmailProviderReconciliationItem::AUTOMATION_FAILED,
                        'provider_reconciliation_automation_scope_unstable',
                    ];
                $terminal = in_array($status, [
                    EmailProviderReconciliationItem::AUTOMATION_SUPPRESSED,
                    EmailProviderReconciliationItem::AUTOMATION_FAILED,
                ], true);
                $item->forceFill([
                    'automation_status' => $status,
                    'automation_claim_token' => null,
                    'automation_completed_at' => $terminal ? $now : null,
                    'automation_error_code' => $code,
                ])->save();
                if ($status === EmailProviderReconciliationItem::AUTOMATION_PENDING) {
                    $pendingIds[] = (int) $item->id;
                }
            }

            $lockedRun->forceFill(['last_progress_at' => $now])->save();

            return ['processed' => true, 'pending_ids' => $pendingIds];
        }, 3);

        foreach ($result['pending_ids'] as $itemId) {
            ProcessEmailProviderReconciliationAutomation::dispatch($itemId)->afterCommit();
        }

        return (bool) $result['processed'];
    }

    private function globallyStable(EmailProviderReconciliationRun $run): bool
    {
        if ($run->automation_scope_unsafe !== false
            || ! is_string($run->start_folder_scope_hash)
            || ! is_string($run->end_folder_scope_hash)
            || ! hash_equals($run->start_folder_scope_hash, $run->end_folder_scope_hash)
            || $run->local_folder_snapshot_status
                !== EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_COMPLETED) {
            return false;
        }

        $remoteFolders = EmailProviderReconciliationFolder::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->whereIn(
                'discovery_state',
                EmailProviderReconciliationFolder::REMOTE_DISCOVERY_STATES,
            );
        if ((clone $remoteFolders)->count() !== (int) $run->folder_count) {
            return false;
        }

        $unstableRemote = (clone $remoteFolders)
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('status', '!=', EmailProviderReconciliationFolder::STATUS_COMPLETE)
            ->exists();
        $ambiguousLocal = EmailProviderReconciliationFolder::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('discovery_state', EmailProviderReconciliationFolder::DISCOVERY_LOCAL_ONLY)
            ->whereIn('status', self::AMBIGUOUS_LOCAL_STATUSES)
            ->exists();
        $unsuccessfulImport = EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->whereIn('status', [
                EmailProviderReconciliationItem::STATUS_CONFLICT,
                EmailProviderReconciliationItem::STATUS_STALE,
                EmailProviderReconciliationItem::STATUS_FAILED,
                EmailProviderReconciliationItem::STATUS_CANCELLED,
            ])->exists();

        return ! $unstableRemote
            && ! $ambiguousLocal
            && ! $unsuccessfulImport;
    }

    /** @return array{string, string|null} */
    private function classify(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationItem $item,
    ): array {
        $placement = EmailMailboxPlacement::query()
            ->whereKey($item->result_placement_id)
            ->where('account_id', $run->account_id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->where('sync_status', EmailMailboxPlacement::SYNC_SYNCED)
            ->whereNull('provider_missing_at')
            ->lockForUpdate()
            ->first();
        $message = $placement
            ? EmailMessage::query()->withTrashed()->lockForUpdate()->find($placement->email_message_id)
            : null;
        $folderRun = $placement
            ? EmailProviderReconciliationFolder::query()
                ->where('email_provider_reconciliation_run_id', $run->id)
                ->where('account_id', $run->account_id)
                ->where('email_folder_id', $placement->email_folder_id)
                ->where('uid_namespace_id', $placement->uid_namespace_id)
                ->where('status', EmailProviderReconciliationFolder::STATUS_COMPLETE)
                ->first()
            : null;
        $folder = $placement
            ? EmailFolder::query()
                ->whereKey($placement->email_folder_id)
                ->where('account_id', $run->account_id)
                ->where('active_uid_namespace_id', $placement->uid_namespace_id)
                ->first()
            : null;
        $namespaceActive = $placement
            && EmailFolderUidNamespace::query()
                ->whereKey($placement->uid_namespace_id)
                ->where('account_id', $run->account_id)
                ->where('email_folder_id', $placement->email_folder_id)
                ->where('uid_validity', $placement->imap_uid_validity)
                ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
                ->exists();
        $identity = $message ? $this->identities->forMessage($message) : null;
        $frozenIdentity = $item->identity_hash;
        if (! $placement || ! $message || $message->trashed() || ! $folderRun || ! $folder
            || ! $namespaceActive
            || $item->status !== EmailProviderReconciliationItem::STATUS_PROJECTED
            || ! is_string($frozenIdentity)
            || strlen($frozenIdentity) !== 64
            || (int) $placement->last_provider_reconciliation_run_id !== (int) $run->id
            || $placement->last_provider_observed_sync_version === null
            || $placement->last_provider_observed_at === null
            || (int) $placement->last_provider_observed_sync_version
                !== (int) $placement->sync_version
            || ! is_string($placement->last_provider_observed_identity_hash)
            || ! hash_equals(
                $placement->last_provider_observed_identity_hash,
                $frozenIdentity,
            )
            || (int) $placement->sync_version !== (int) $item->placement_sync_version_after
            || (int) $placement->imap_uid_validity !== (int) $folderRun->expected_uid_validity
            || (int) $placement->imap_uid !== (int) $item->imap_uid
            || (string) $placement->folder_path !== (string) $folderRun->folder_path
            || (string) $placement->folder_path !== (string) $folder->path
            || (string) $message->mailbox !== (string) $placement->folder_path
            || (int) $message->account_id !== (int) $run->account_id
            || (int) $message->imap_uid_validity !== (int) $placement->imap_uid_validity
            || (int) $message->imap_uid !== (int) $placement->imap_uid
            || $folderRun->import_policy !== EmailProviderReconciliationFolder::IMPORT_LIVE
            || $folder->role !== EmailFolder::ROLE_INBOX
            || ! is_string($identity)
            || ! hash_equals($frozenIdentity, $identity)
            || $this->operations->hasUnresolvedForPlacement((int) $placement->id)) {
            return [
                EmailProviderReconciliationItem::AUTOMATION_FAILED,
                'provider_reconciliation_automation_scope_invalid',
            ];
        }

        $moveStatuses = EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_MOVE_CANDIDATE)
            ->where('target_placement_id', $placement->id)
            ->limit(2)
            ->pluck('status');
        if ($moveStatuses->count() === 1
            && $moveStatuses->first() === EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE) {
            return [
                EmailProviderReconciliationItem::AUTOMATION_SUPPRESSED,
                'provider_reconciliation_automation_existing_move',
            ];
        }
        if ($moveStatuses->isNotEmpty()) {
            return [
                EmailProviderReconciliationItem::AUTOMATION_FAILED,
                'provider_reconciliation_automation_move_ambiguous',
            ];
        }

        $messageId = (string) $message->message_id;
        $normalizedMessageId = $this->identities->normalizeMessageId($messageId);
        if ($normalizedMessageId === '') {
            return [
                EmailProviderReconciliationItem::AUTOMATION_FAILED,
                'provider_reconciliation_automation_identity_weak',
            ];
        }

        if ($this->hasCurrentRunPeer($run, $item, $placement)) {
            // Correlation begins only after every import is terminal, so this
            // symmetric query sees peers outside the current 100-row page as
            // well. No member of a same-cycle duplicate group may automate.
            return [
                EmailProviderReconciliationItem::AUTOMATION_FAILED,
                'provider_reconciliation_automation_current_run_duplicate',
            ];
        }

        $candidates = $this->preRunCandidates($run, $placement, $frozenIdentity);
        if ($candidates->count() > 1) {
            return [
                EmailProviderReconciliationItem::AUTOMATION_FAILED,
                'provider_reconciliation_automation_identity_ambiguous',
            ];
        }
        if ($candidates->count() === 1) {
            if (! $this->candidateCurrentlyStable(
                $run,
                $candidates->first(),
                $frozenIdentity,
                $normalizedMessageId,
            )) {
                return [
                    EmailProviderReconciliationItem::AUTOMATION_FAILED,
                    'provider_reconciliation_automation_copy_source_drift',
                ];
            }

            return [
                EmailProviderReconciliationItem::AUTOMATION_SUPPRESSED,
                'provider_reconciliation_automation_existing_copy',
            ];
        }

        return [EmailProviderReconciliationItem::AUTOMATION_PENDING, null];
    }

    private function hasCurrentRunPeer(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationItem $item,
        EmailMailboxPlacement $target,
    ): bool {
        return EmailProviderReconciliationItem::query()
            ->from('email_provider_reconciliation_items as peer_items')
            ->join('email_mailbox_placements as peer_placements', function ($join) use ($run): void {
                $join->on('peer_placements.id', '=', 'peer_items.result_placement_id')
                    ->where('peer_placements.account_id', '=', $run->account_id);
            })
            ->where('peer_items.email_provider_reconciliation_run_id', $run->id)
            ->where('peer_items.kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->whereIn('peer_items.status', [
                EmailProviderReconciliationItem::STATUS_PROJECTED,
                EmailProviderReconciliationItem::STATUS_ALREADY_PRESENT,
            ])
            ->where('peer_items.identity_hash', $item->identity_hash)
            ->where('peer_items.id', '!=', $item->id)
            ->where('peer_placements.id', '!=', $target->id)
            ->limit(1)
            ->exists();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, EmailMailboxPlacement> */
    private function preRunCandidates(
        EmailProviderReconciliationRun $run,
        EmailMailboxPlacement $target,
        string $identityHash,
    ) {
        return EmailMailboxPlacement::query()
            ->from('email_mailbox_placements as placements')
            ->select([
                'placements.*',
                'folder_runs.folder_path as reconciliation_folder_path',
            ])
            ->with(['message' => fn ($query) => $query->withTrashed()])
            ->join('email_provider_reconciliation_folders as folder_runs', function ($join) use ($run): void {
                $join->on('folder_runs.email_folder_id', '=', 'placements.email_folder_id')
                    ->on('folder_runs.uid_namespace_id', '=', 'placements.uid_namespace_id')
                    ->where('folder_runs.email_provider_reconciliation_run_id', '=', $run->id);
            })
            ->where('placements.account_id', $run->account_id)
            ->where('placements.id', '!=', $target->id)
            ->where('placements.last_provider_reconciliation_run_id', $run->id)
            ->where('placements.last_provider_observed_identity_hash', $identityHash)
            ->where('folder_runs.status', EmailProviderReconciliationFolder::STATUS_COMPLETE)
            ->whereColumn('placements.id', '<=', 'folder_runs.baseline_max_placement_id')
            ->whereNotExists(function ($imports) use ($run): void {
                $imports->selectRaw('1')
                    ->from('email_provider_reconciliation_items as imported_results')
                    ->whereColumn('imported_results.result_placement_id', 'placements.id')
                    ->where('imported_results.email_provider_reconciliation_run_id', $run->id)
                    ->where('imported_results.kind', EmailProviderReconciliationItem::KIND_IMPORT);
            })
            ->distinct()
            ->orderBy('placements.id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();
    }

    private function candidateCurrentlyStable(
        EmailProviderReconciliationRun $run,
        EmailMailboxPlacement $candidate,
        string $identityHash,
        string $normalizedMessageId,
    ): bool {
        $message = $candidate->message;
        $currentIdentity = $message ? $this->identities->forMessage($message) : null;
        $folderPath = (string) $candidate->getAttribute('reconciliation_folder_path');
        if (! $message || $message->trashed()
            || $candidate->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE
            || $candidate->sync_status !== EmailMailboxPlacement::SYNC_SYNCED
            || $candidate->provider_missing_at !== null
            || $candidate->last_provider_observed_sync_version === null
            || $candidate->last_provider_observed_at === null
            || (int) $candidate->last_provider_observed_sync_version
                !== (int) $candidate->sync_version
            || ! is_string($candidate->last_provider_observed_identity_hash)
            || ! hash_equals(
                $candidate->last_provider_observed_identity_hash,
                $identityHash,
            )
            || $this->identities->normalizeMessageId($message->message_id)
                !== $normalizedMessageId
            || ! is_string($currentIdentity)
            || ! hash_equals($identityHash, $currentIdentity)
            || $folderPath === ''
            || (string) $candidate->folder_path !== $folderPath
            || (int) $message->account_id !== (int) $run->account_id
            || (string) $message->mailbox !== (string) $candidate->folder_path
            || (int) $message->imap_uid_validity !== (int) $candidate->imap_uid_validity
            || (int) $message->imap_uid !== (int) $candidate->imap_uid
            || $this->operations->hasUnresolvedForPlacement((int) $candidate->id)) {
            return false;
        }

        $folderCurrent = EmailFolder::query()
            ->whereKey($candidate->email_folder_id)
            ->where('account_id', $run->account_id)
            ->where('active_uid_namespace_id', $candidate->uid_namespace_id)
            ->first();
        $namespaceCurrent = EmailFolderUidNamespace::query()
            ->whereKey($candidate->uid_namespace_id)
            ->where('account_id', $run->account_id)
            ->where('email_folder_id', $candidate->email_folder_id)
            ->where('uid_validity', $candidate->imap_uid_validity)
            ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
            ->exists();

        return $folderCurrent
            && (string) $folderCurrent->path === (string) $candidate->folder_path
            && (int) $folderCurrent->uid_validity === (int) $candidate->imap_uid_validity
            && $namespaceCurrent;
    }
}
