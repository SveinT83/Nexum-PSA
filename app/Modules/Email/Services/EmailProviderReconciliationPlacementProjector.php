<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\DTOs\EmailProviderReconciliationMessageMetadata;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Support\Facades\DB;

final class EmailProviderReconciliationPlacementProjector
{
    public function __construct(
        private readonly EmailProviderMessageIdentity $identities,
        private readonly EmailProviderRemoteOperationObserver $operations,
        private readonly EmailConversationProjector $conversations,
    ) {}

    /**
     * @return array{new_observation: bool, flag_changed: bool, import_item_id: int|null, evidence_drift: bool, scope_stale: bool}
     */
    public function observe(
        EmailProviderReconciliationFolder $folderRun,
        EmailProviderReconciliationMessageMetadata $metadata,
        int $expectedAfterUid,
        int $expectedCompleteThroughUid,
    ): array {
        return DB::transaction(function () use (
            $expectedAfterUid,
            $expectedCompleteThroughUid,
            $folderRun,
            $metadata,
        ): array {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($folderRun->email_provider_reconciliation_run_id);
            $lockedFolder = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($folderRun->id);
            if (! $this->activeScanScope(
                $run,
                $lockedFolder,
                $expectedAfterUid,
                $expectedCompleteThroughUid,
            ) || $metadata->uid <= $expectedAfterUid
                || $metadata->uid > $expectedCompleteThroughUid) {
                return $this->staleObservationResult();
            }
            $existingObservation = EmailProviderReconciliationItem::query()
                ->where('email_provider_reconciliation_run_id', $run->id)
                ->where('email_provider_reconciliation_folder_id', $lockedFolder->id)
                ->where('uid_namespace_id', $lockedFolder->uid_namespace_id)
                ->where('imap_uid', $metadata->uid)
                ->where('kind', EmailProviderReconciliationItem::KIND_OBSERVATION)
                ->lockForUpdate()
                ->first();

            if ($existingObservation) {
                if ($existingObservation->source_placement_id) {
                    $knownSourceExact = EmailMailboxPlacement::query()
                        ->whereKey($existingObservation->source_placement_id)
                        ->where('account_id', $lockedFolder->account_id)
                        ->where('email_folder_id', $lockedFolder->email_folder_id)
                        ->where('uid_namespace_id', $lockedFolder->uid_namespace_id)
                        ->where('imap_uid', $metadata->uid)
                        ->exists();
                    if (! $knownSourceExact
                        || $this->canonicalIdentityHash($existingObservation->identity_hash) === null) {
                        $run->markAutomationScopeUnsafe();
                    }
                }
                if (! $this->sameEvidence($existingObservation, $metadata)) {
                    // A worker may die after committing evidence but before it
                    // advances the folder cursor. Exact redelivery is safe;
                    // changed redelivery invalidates this cycle instead of
                    // overwriting the first durable observation.
                    $lockedFolder->forceFill([
                        'status' => EmailProviderReconciliationFolder::STATUS_STALE,
                        'reason_code' => 'provider_observation_redelivery_drift',
                        'finished_at' => now(),
                        'last_progress_at' => now(),
                    ])->save();

                    return [
                        'new_observation' => false,
                        'flag_changed' => false,
                        'import_item_id' => null,
                        'evidence_drift' => true,
                        'scope_stale' => false,
                    ];
                }

                $importId = EmailProviderReconciliationItem::query()
                    ->where('email_provider_reconciliation_run_id', $run->id)
                    ->where('email_provider_reconciliation_folder_id', $lockedFolder->id)
                    ->where('uid_namespace_id', $lockedFolder->uid_namespace_id)
                    ->where('imap_uid', $metadata->uid)
                    ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
                    ->value('id');

                return [
                    'new_observation' => false,
                    'flag_changed' => false,
                    'import_item_id' => $importId ? (int) $importId : null,
                    'evidence_drift' => false,
                    'scope_stale' => false,
                ];
            }

            $placement = EmailMailboxPlacement::query()
                ->where('account_id', $lockedFolder->account_id)
                ->where('email_folder_id', $lockedFolder->email_folder_id)
                ->where('uid_namespace_id', $lockedFolder->uid_namespace_id)
                ->where('imap_uid', $metadata->uid)
                ->lockForUpdate()
                ->first();
            $message = $placement
                ? EmailMessage::query()->withTrashed()->find($placement->email_message_id)
                : null;
            $beforeVersion = $placement ? max(1, (int) $placement->sync_version) : null;
            $afterVersion = $beforeVersion;
            $changed = false;
            $identityHash = $message
                ? $this->identities->forMessage($message)
                : null;
            if ($placement && $this->canonicalIdentityHash($identityHash) === null) {
                $run->markAutomationScopeUnsafe();
            }
            $hasUnresolvedOperation = $placement
                && $this->operations->hasUnresolvedForPlacement((int) $placement->id);
            $reconciliationImportIncomplete = $placement
                && $this->reconciliationImportPending($placement);
            $shouldImport = $reconciliationImportIncomplete
                || (! $placement && $this->shouldImport($lockedFolder, $metadata->uid));

            if ($placement) {
                $normalizedFlags = $this->placementFlags($metadata);
                $changed = $placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE
                    || (bool) $placement->provider_seen !== $metadata->seen
                    || (bool) $placement->provider_answered !== $metadata->answered
                    || (bool) $placement->provider_flagged !== $metadata->flagged
                    || (bool) $placement->provider_deleted !== $metadata->deleted
                    || (bool) $placement->provider_draft !== $metadata->draft
                    || (int) ($placement->remote_modseq ?? 0) !== (int) ($metadata->modseq ?? 0)
                    || $this->normalizedStoredFlags($placement->flags_json ?? []) !== $normalizedFlags;

                $attributes = [
                    'last_provider_reconciliation_run_id' => $run->id,
                    'last_provider_observed_sync_version' => $beforeVersion,
                    'last_provider_observed_identity_hash' => $identityHash,
                    'last_provider_observed_at' => now(),
                ];

                // Known placements are projected only after the complete end
                // gate. Keeping sync_version unchanged during the scan makes
                // baseline drift distinguishable from our own provider flags.
                $placement->forceFill($attributes)->save();
            }

            // The folder inventory hash/count is the durable proof for routine
            // stable presence. Per-UID evidence is retained only when later
            // work or conflict resolution needs the exact scan-time flags.
            $observation = null;
            if ($changed || $shouldImport || $hasUnresolvedOperation) {
                $observation = EmailProviderReconciliationItem::query()->create([
                    'email_provider_reconciliation_run_id' => $run->id,
                    'email_provider_reconciliation_folder_id' => $lockedFolder->id,
                    'uid_namespace_id' => $lockedFolder->uid_namespace_id,
                    'imap_uid' => $metadata->uid,
                    'kind' => EmailProviderReconciliationItem::KIND_OBSERVATION,
                    'status' => EmailProviderReconciliationItem::STATUS_PENDING,
                    'source_placement_id' => $placement?->id,
                    'result_placement_id' => $placement?->id,
                    'identity_hash' => $identityHash,
                    'provider_modseq' => $metadata->modseq,
                    'provider_seen' => $metadata->seen,
                    'provider_answered' => $metadata->answered,
                    'provider_flagged' => $metadata->flagged,
                    'provider_deleted' => $metadata->deleted,
                    'provider_draft' => $metadata->draft,
                    'custom_flags_json' => $metadata->customFlags,
                    'custom_flags_hash' => $metadata->customFlagsHash,
                    'placement_sync_version_before' => $beforeVersion,
                    'placement_sync_version_after' => $afterVersion,
                ]);
            }
            $import = null;

            if ($shouldImport) {
                $import = EmailProviderReconciliationItem::query()->create([
                    'email_provider_reconciliation_run_id' => $run->id,
                    'email_provider_reconciliation_folder_id' => $lockedFolder->id,
                    'uid_namespace_id' => $lockedFolder->uid_namespace_id,
                    'imap_uid' => $metadata->uid,
                    'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
                    'status' => EmailProviderReconciliationItem::STATUS_PENDING,
                    'attempt_count' => 0,
                ]);
            }

            if ($hasUnresolvedOperation) {
                $operation = $this->operations->oldestUnresolvedForPlacement((int) $placement->id);
                if ($operation) {
                    EmailProviderReconciliationItem::query()->create([
                        'email_provider_reconciliation_run_id' => $run->id,
                        'email_provider_reconciliation_folder_id' => $lockedFolder->id,
                        'uid_namespace_id' => $lockedFolder->uid_namespace_id,
                        'imap_uid' => $metadata->uid,
                        'kind' => EmailProviderReconciliationItem::KIND_OPERATION_CONFLICT,
                        'status' => EmailProviderReconciliationItem::STATUS_PENDING,
                        'source_placement_id' => $placement->id,
                        'email_remote_operation_id' => $operation->id,
                        'placement_sync_version_before' => $beforeVersion,
                    ]);
                }
            }

            $lockedFolder->forceFill([
                'import_count' => (int) $lockedFolder->import_count + ($import ? 1 : 0),
                'flag_change_count' => (int) $lockedFolder->flag_change_count + ($changed ? 1 : 0),
                'last_progress_at' => now(),
            ])->save();
            $run->forceFill([
                'import_count' => (int) $run->import_count + ($import ? 1 : 0),
                'flag_change_count' => (int) $run->flag_change_count + ($changed ? 1 : 0),
                'last_progress_at' => now(),
            ])->save();

            return [
                'new_observation' => $observation !== null,
                'flag_changed' => $changed,
                'import_item_id' => $import?->id ? (int) $import->id : null,
                'evidence_drift' => false,
                'scope_stale' => false,
            ];
        }, 3);

    }

    private function sameEvidence(
        EmailProviderReconciliationItem $observation,
        EmailProviderReconciliationMessageMetadata $metadata,
    ): bool {
        return (int) $observation->imap_uid === $metadata->uid
            && ($observation->provider_modseq === null
                ? $metadata->modseq === null
                : (int) $observation->provider_modseq === $metadata->modseq)
            && (bool) $observation->provider_seen === $metadata->seen
            && (bool) $observation->provider_answered === $metadata->answered
            && (bool) $observation->provider_flagged === $metadata->flagged
            && (bool) $observation->provider_deleted === $metadata->deleted
            && (bool) $observation->provider_draft === $metadata->draft
            && is_string($observation->custom_flags_hash)
            && hash_equals($observation->custom_flags_hash, $metadata->customFlagsHash)
            && ($observation->custom_flags_json ?? []) === $metadata->customFlags;
    }

    /**
     * Apply one already-audited observation after the folder's complete
     * inventory and end tuple have proved stable. Known placement flags are
     * never changed during the scan, which keeps the negative-evidence drift
     * gate exact even when no local remote operation exists.
     */
    public function applyStableObservation(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationItem $observation,
    ): bool {
        return DB::transaction(function () use ($observation, $run): bool {
            $lockedRun = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($run->id);
            $folder = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($observation->email_provider_reconciliation_folder_id);
            $item = EmailProviderReconciliationItem::query()
                ->lockForUpdate()
                ->find($observation->id);
            if (! $this->activeProjectionRun($lockedRun)
                || ! $folder
                || ! $item
                || (int) $folder->email_provider_reconciliation_run_id !== (int) $lockedRun->id
                || $folder->status !== EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS
                || $folder->reason_code !== 'stable_operation_projection'
                || (int) $item->email_provider_reconciliation_run_id !== (int) $lockedRun->id
                || (int) $item->email_provider_reconciliation_folder_id !== (int) $folder->id
                || $item->kind !== EmailProviderReconciliationItem::KIND_OBSERVATION
                || ! $item->source_placement_id) {
                return false;
            }

            $placement = EmailMailboxPlacement::query()
                ->whereKey($item->source_placement_id)
                ->where('uid_namespace_id', $item->uid_namespace_id)
                ->where('imap_uid', $item->imap_uid)
                ->lockForUpdate()
                ->first();
            if (! $placement
                || (int) $placement->sync_version !== (int) $item->placement_sync_version_after
                || $this->reconciliationImportPending($placement)) {
                return false;
            }
            $import = EmailProviderReconciliationItem::query()
                ->where('email_provider_reconciliation_run_id', $item->email_provider_reconciliation_run_id)
                ->where('email_provider_reconciliation_folder_id', $item->email_provider_reconciliation_folder_id)
                ->where('uid_namespace_id', $item->uid_namespace_id)
                ->where('imap_uid', $item->imap_uid)
                ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
                ->where('result_placement_id', $placement->id)
                ->lockForUpdate()
                ->first();
            if ($import && (int) $import->placement_sync_version_after !== (int) $placement->sync_version) {
                return false;
            }
            $message = EmailMessage::query()->withTrashed()->find($placement->email_message_id);
            if (! $message) {
                return false;
            }
            // Identity is correlation-only and may legitimately be weak. Keep
            // the provider-observation hash frozen at scan time; recomputing
            // it here would let later local/message-fact drift erase the
            // durable copy evidence before correlation runs.
            $identityHash = $this->canonicalIdentityHash($item->identity_hash)
                ?? $this->canonicalIdentityHash($placement->last_provider_observed_identity_hash);
            if ($identityHash === null) {
                $lockedRun->markAutomationScopeUnsafe();
            }

            $metadata = new EmailProviderReconciliationMessageMetadata(
                uid: (int) $item->imap_uid,
                modseq: $item->provider_modseq,
                seen: (bool) $item->provider_seen,
                answered: (bool) $item->provider_answered,
                flagged: (bool) $item->provider_flagged,
                deleted: (bool) $item->provider_deleted,
                draft: (bool) $item->provider_draft,
                customFlags: $item->custom_flags_json ?? [],
            );
            $normalizedFlags = $this->placementFlags($metadata);
            $changed = $placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE
                || (bool) $placement->provider_seen !== $metadata->seen
                || (bool) $placement->provider_answered !== $metadata->answered
                || (bool) $placement->provider_flagged !== $metadata->flagged
                || (bool) $placement->provider_deleted !== $metadata->deleted
                || (bool) $placement->provider_draft !== $metadata->draft
                || (int) ($placement->remote_modseq ?? 0) !== (int) ($metadata->modseq ?? 0)
                || $this->normalizedStoredFlags($placement->flags_json ?? []) !== $normalizedFlags;
            $nextVersion = $changed
                ? max(1, (int) $placement->sync_version) + 1
                : max(1, (int) $placement->sync_version);

            $placement->forceFill([
                'remote_modseq' => $metadata->modseq,
                'provider_seen' => $metadata->seen,
                'provider_answered' => $metadata->answered,
                'provider_flagged' => $metadata->flagged,
                'provider_deleted' => $metadata->deleted,
                'provider_draft' => $metadata->draft,
                'flags_json' => $normalizedFlags,
                'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
                'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
                'sync_version' => $nextVersion,
                'provider_missing_at' => null,
                'last_reconciled_at' => now(),
                'last_provider_reconciliation_run_id' => $item->email_provider_reconciliation_run_id,
                'last_provider_observed_sync_version' => $nextVersion,
                'last_provider_observed_identity_hash' => $identityHash,
                'last_provider_observed_at' => now(),
                'sync_error_code' => null,
                'sync_error_message' => null,
            ])->save();
            $item->forceFill([
                'status' => $changed
                    ? EmailProviderReconciliationItem::STATUS_PROJECTED
                    : EmailProviderReconciliationItem::STATUS_ALREADY_PRESENT,
                'placement_sync_version_after' => $nextVersion,
                'completed_at' => now(),
            ])->save();
            if ($import) {
                // The import row is the correlator's frozen target evidence.
                // Advance it only inside this exact same-run observation CAS;
                // ordinary local operations never receive this authority.
                $import->forceFill([
                    'placement_sync_version_after' => $nextVersion,
                ])->save();
            }

            if ($message?->trashed()) {
                $message->restore();
            }
            if ($placement->email_conversation_id) {
                $this->conversations->refreshConversation(
                    EmailConversation::query()->find($placement->email_conversation_id),
                );
            }

            return true;
        }, 3);
    }

    /**
     * Recompute one durable deviation during ordinary-IMAP pass A.
     *
     * This method runs inside the Scanner's page transaction, creates no
     * imports, and never projects provider flags or personal state. Pass B
     * must match the complete pass-A hash before a pending observation can be
     * applied. Exact redelivery is idempotent; scope/version drift aborts the
     * page so its cursor cannot advance.
     */
    public function refreshVerifiedObservation(
        EmailProviderReconciliationFolder $folderRun,
        EmailProviderReconciliationMessageMetadata $metadata,
    ): bool {
        $run = EmailProviderReconciliationRun::query()->lockForUpdate()->find(
            $folderRun->email_provider_reconciliation_run_id,
        );
        $lockedFolder = EmailProviderReconciliationFolder::query()
            ->lockForUpdate()
            ->find($folderRun->id);
        if (! $this->activeScanRun($run)
            || ! $lockedFolder
            || (int) $lockedFolder->email_provider_reconciliation_run_id !== (int) $run->id
            || $lockedFolder->status !== EmailProviderReconciliationFolder::STATUS_SCANNING
            || $lockedFolder->reason_code !== 'nomodseq_baseline_pending') {
            return false;
        }

        $folderRun = $lockedFolder;

        $observation = EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('email_provider_reconciliation_folder_id', $folderRun->id)
            ->where('uid_namespace_id', $folderRun->uid_namespace_id)
            ->where('imap_uid', $metadata->uid)
            ->where('kind', EmailProviderReconciliationItem::KIND_OBSERVATION)
            ->lockForUpdate()
            ->first();
        $import = EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('email_provider_reconciliation_folder_id', $folderRun->id)
            ->where('uid_namespace_id', $folderRun->uid_namespace_id)
            ->where('imap_uid', $metadata->uid)
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->first();
        if ($import && in_array($import->status, [
            EmailProviderReconciliationItem::STATUS_CONFLICT,
            EmailProviderReconciliationItem::STATUS_STALE,
            EmailProviderReconciliationItem::STATUS_FAILED,
            EmailProviderReconciliationItem::STATUS_CANCELLED,
        ], true)) {
            return true;
        }

        $placement = EmailMailboxPlacement::query()
            ->where('account_id', $folderRun->account_id)
            ->where('email_folder_id', $folderRun->email_folder_id)
            ->where('uid_namespace_id', $folderRun->uid_namespace_id)
            ->where('imap_uid_validity', $folderRun->expected_uid_validity)
            ->where('imap_uid', $metadata->uid)
            ->lockForUpdate()
            ->first();
        if (! $placement) {
            // Baseline-only unknown UIDs intentionally have no local row and
            // require neither an item nor a projection.
            return ! $import && ! $observation;
        }
        $version = max(1, (int) $placement->sync_version);
        if ($observation?->source_placement_id
            && ((int) $observation->source_placement_id !== (int) $placement->id
                || ($observation->placement_sync_version_after !== null
                    && (int) $observation->placement_sync_version_after !== $version))) {
            return false;
        }
        if ($observation && ! in_array($observation->status, [
            EmailProviderReconciliationItem::STATUS_PENDING,
            EmailProviderReconciliationItem::STATUS_ALREADY_PRESENT,
        ], true)) {
            return false;
        }

        $normalizedFlags = $this->placementFlags($metadata);
        $flagChanged = (bool) $placement->provider_seen !== $metadata->seen
            || (bool) $placement->provider_answered !== $metadata->answered
            || (bool) $placement->provider_flagged !== $metadata->flagged
            || (bool) $placement->provider_deleted !== $metadata->deleted
            || (bool) $placement->provider_draft !== $metadata->draft
            || (int) ($placement->remote_modseq ?? 0) !== 0
            || $this->normalizedStoredFlags($placement->flags_json ?? []) !== $normalizedFlags;
        $hasUnresolvedOperation = $this->operations->hasUnresolvedForPlacement(
            (int) $placement->id,
        );
        $needsEvidence = $flagChanged || $hasUnresolvedOperation;
        if (! $observation && ! $needsEvidence) {
            return true;
        }

        $identityHash = $this->canonicalIdentityHash($observation?->identity_hash)
            ?? $this->canonicalIdentityHash($placement->last_provider_observed_identity_hash);
        if ($identityHash === null) {
            $run->markAutomationScopeUnsafe();
        }

        $attributes = [
            'status' => $needsEvidence
                ? EmailProviderReconciliationItem::STATUS_PENDING
                : EmailProviderReconciliationItem::STATUS_ALREADY_PRESENT,
            'source_placement_id' => $placement->id,
            'result_placement_id' => $placement->id,
            'identity_hash' => $identityHash,
            'provider_modseq' => null,
            'provider_seen' => $metadata->seen,
            'provider_answered' => $metadata->answered,
            'provider_flagged' => $metadata->flagged,
            'provider_deleted' => $metadata->deleted,
            'provider_draft' => $metadata->draft,
            'custom_flags_json' => $metadata->customFlags,
            'custom_flags_hash' => $metadata->customFlagsHash,
            'placement_sync_version_before' => $version,
            'placement_sync_version_after' => $version,
            'error_code' => null,
            'completed_at' => $needsEvidence ? null : now(),
        ];
        if ($observation) {
            $observation->forceFill($attributes)->save();

            return true;
        }

        EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $folderRun->uid_namespace_id,
            'imap_uid' => $metadata->uid,
            'kind' => EmailProviderReconciliationItem::KIND_OBSERVATION,
            ...$attributes,
        ]);

        return true;
    }

    private function canonicalIdentityHash(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1
                ? $value
                : null;
    }

    private function activeScanScope(
        ?EmailProviderReconciliationRun $run,
        ?EmailProviderReconciliationFolder $folder,
        int $expectedAfterUid,
        int $expectedCompleteThroughUid,
    ): bool {
        return $this->activeScanRun($run)
            && $folder
            && (int) $folder->email_provider_reconciliation_run_id === (int) $run->id
            && $folder->status === EmailProviderReconciliationFolder::STATUS_SCANNING
            && $folder->reason_code === null
            && (int) $folder->next_uid - 1 === $expectedAfterUid
            && $expectedCompleteThroughUid > $expectedAfterUid
            && $expectedCompleteThroughUid <= (int) $folder->scan_through_uid;
    }

    private function activeScanRun(?EmailProviderReconciliationRun $run): bool
    {
        return $run
            && $run->status === EmailProviderReconciliationRun::STATUS_RUNNING
            && $run->phase === EmailProviderReconciliationRun::PHASE_SCAN
            && (int) $run->active_slot === 1
            && $run->cancellation_requested_at === null;
    }

    private function activeProjectionRun(?EmailProviderReconciliationRun $run): bool
    {
        return $run
            && $run->status === EmailProviderReconciliationRun::STATUS_RUNNING
            && $run->phase === EmailProviderReconciliationRun::PHASE_DISCOVER_END
            && (int) $run->active_slot === 1
            && $run->cancellation_requested_at === null
            && $run->final_summary_status === null;
    }

    /** @return array{new_observation: bool, flag_changed: bool, import_item_id: int|null, evidence_drift: bool, scope_stale: bool} */
    private function staleObservationResult(): array
    {
        return [
            'new_observation' => false,
            'flag_changed' => false,
            'import_item_id' => null,
            'evidence_drift' => false,
            'scope_stale' => true,
        ];
    }

    /** @return array<int, string> */
    private function placementFlags(EmailProviderReconciliationMessageMetadata $metadata): array
    {
        $flags = [];
        foreach ([
            '\\Seen' => $metadata->seen,
            '\\Answered' => $metadata->answered,
            '\\Flagged' => $metadata->flagged,
            '\\Deleted' => $metadata->deleted,
            '\\Draft' => $metadata->draft,
        ] as $flag => $enabled) {
            if ($enabled) {
                $flags[] = $flag;
            }
        }

        return [...$flags, ...$metadata->customFlags];
    }

    /**
     * @param  array<int, string>  $flags
     * @return array<int, string>
     */
    private function normalizedStoredFlags(array $flags): array
    {
        $lower = array_map(fn (string $flag): string => mb_strtolower(trim($flag)), $flags);
        $standard = [];
        foreach (['\\seen', '\\answered', '\\flagged', '\\deleted', '\\draft'] as $flag) {
            if (in_array($flag, $lower, true)) {
                $standard[] = match ($flag) {
                    '\\seen' => '\\Seen',
                    '\\answered' => '\\Answered',
                    '\\flagged' => '\\Flagged',
                    '\\deleted' => '\\Deleted',
                    '\\draft' => '\\Draft',
                };
            }
        }

        return [...$standard, ...EmailProviderReconciliationMessageMetadata::normalizeCustomFlags($flags)];
    }

    private function shouldImport(
        EmailProviderReconciliationFolder $folderRun,
        int $uid,
    ): bool {
        if ($folderRun->import_policy === EmailProviderReconciliationFolder::IMPORT_BASELINE_ONLY) {
            return false;
        }

        if ($folderRun->import_policy === EmailProviderReconciliationFolder::IMPORT_NEW_FOLDER_NO_RULES) {
            return true;
        }

        $liveStartUid = EmailFolder::query()
            ->whereKey($folderRun->email_folder_id)
            ->value('live_start_uid');

        return $liveStartUid !== null && $uid > (int) $liveStartUid;
    }

    private function reconciliationImportPending(EmailMailboxPlacement $placement): bool
    {
        return $placement->local_state === EmailMailboxPlacement::LOCAL_HIDDEN
            && $placement->sync_status === EmailMailboxPlacement::SYNC_PENDING
            && in_array($placement->sync_error_code, [
                EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
                EmailProviderReconciliationStore::STORE_PENDING_CODE,
            ], true);
    }
}
