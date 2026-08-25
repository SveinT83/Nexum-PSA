<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Contracts\EmailProviderReconciliationReader;
use App\Modules\Email\DTOs\EmailProviderReconciliationFolderDescriptor;
use App\Modules\Email\DTOs\EmailProviderReconciliationFolderState;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EmailProviderReconciliationFinalizer
{
    private const DB_BATCH_SIZE = 100;

    private const PLACEMENT_SNAPSHOT_PENDING = 'placement_snapshot_pending';

    public function __construct(
        private readonly EmailProviderReconciliationBindingPolicy $bindings,
        private readonly EmailProviderReconciliationFingerprint $fingerprints,
        private readonly EmailProviderReconciliationPlacementSnapshot $placementSnapshots,
        private readonly EmailProviderReconciliationPlacementProjector $placements,
        private readonly EmailProviderRemoteOperationObserver $operations,
        private readonly EmailProviderAbsenceProjector $absences,
        private readonly EmailProviderReconciliationAutomationCorrelator $automationCorrelator,
        private readonly EmailConversationProjector $conversations,
    ) {}

    /**
     * Advance one durable finalization step. The caller should queue another
     * invocation when false is returned. Each invocation performs at most one
     * provider read or one bounded database projection batch.
     */
    public function finalizeOneStep(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationReader $reader,
    ): bool {
        $run = $run->fresh();
        if ($run->terminal()) {
            return true;
        }
        if ($run->cancellation_requested_at !== null
            && $run->status !== EmailProviderReconciliationRun::STATUS_CANCELLING) {
            // Cancellation intent is already a write barrier. The transition
            // job owns the provider-lease-serialized status change.
            return false;
        }
        if ($run->status === EmailProviderReconciliationRun::STATUS_CANCELLING) {
            return $this->finishCancelled($run);
        }
        if ($run->phase === EmailProviderReconciliationRun::PHASE_DISCOVER_LOCAL
            || ($run->start_folder_scope_hash !== null
                && $run->local_folder_snapshot_status
                    !== EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_COMPLETED)) {
            // A stale finalize job cannot bypass an unfinished account-local
            // folder inventory and turn missing local folders into evidence.
            return false;
        }

        if ($run->final_summary_status !== null) {
            return $this->finishRun($run);
        }

        if ($run->folders()->whereIn('status', [
            EmailProviderReconciliationFolder::STATUS_PENDING,
            EmailProviderReconciliationFolder::STATUS_SCANNING,
        ])->whereIn(
            'discovery_state',
            EmailProviderReconciliationFolder::REMOTE_DISCOVERY_STATES,
        )->exists()) {
            return false;
        }

        if ($run->items()
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->whereIn('status', [
                EmailProviderReconciliationItem::STATUS_PENDING,
                EmailProviderReconciliationItem::STATUS_RUNNING,
            ])->exists()) {
            $this->markWaitingForImports($run);

            return false;
        }

        if ($run->items()
            ->where('historical_baseline_required', true)
            ->whereIn('historical_baseline_status', [
                EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
                EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING,
            ])->exists()) {
            // New-folder history stays hidden while the DB-only read-for-me
            // cursor advances. No provider end-state, flag, absence, move, or
            // operation evidence may project before that exact cursor closes.
            $this->markWaitingForImports($run);

            return false;
        }

        if ($run->items()
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->where('automation_required', true)
            ->whereIn('automation_status', [
                EmailProviderReconciliationItem::AUTOMATION_PENDING,
                EmailProviderReconciliationItem::AUTOMATION_RUNNING,
                EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT,
            ])->exists()) {
            $this->markWaitingForImports($run);

            return false;
        }

        if (in_array($run->phase, [
            EmailProviderReconciliationRun::PHASE_SCAN,
            EmailProviderReconciliationRun::PHASE_IMPORTS,
        ], true)) {
            $this->enterFinalizationPhase($run);

            return false;
        }

        if ($run->end_folder_scope_hash === null) {
            return $this->discoverEnd($run, $reader);
        }

        $folderNeedingEndState = $run->folders()
            ->whereIn(
                'discovery_state',
                EmailProviderReconciliationFolder::REMOTE_DISCOVERY_STATES,
            )
            ->where('status', EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS)
            ->whereNull('end_uid_validity')
            ->orderBy('id')
            ->first();
        if ($folderNeedingEndState) {
            $this->captureEndState($run, $folderNeedingEndState, $reader);

            return false;
        }

        // Validate every remote folder before projecting any folder. Source
        // absence and remote-operation evidence may refer to a target folder,
        // so a per-folder validate-and-project order can bless a move whose
        // target tuple later proves stale.
        $folderNeedingValidation = $run->folders()
            ->whereIn(
                'discovery_state',
                EmailProviderReconciliationFolder::REMOTE_DISCOVERY_STATES,
            )
            ->where('status', EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS)
            ->where(function (Builder $query): void {
                $query->whereNull('reason_code')
                    ->orWhereNotIn(
                        'reason_code',
                        EmailProviderReconciliationFolder::STABLE_EVIDENCE_REASON_CODES,
                    );
            })
            ->orderBy('id')
            ->first();
        if ($folderNeedingValidation) {
            $this->withActiveFinalizationRun(
                $run,
                fn (EmailProviderReconciliationRun $lockedRun) => $this->validateRemoteFolder(
                    $folderNeedingValidation,
                ),
            );

            return false;
        }

        $remoteFolder = $run->folders()
            ->whereIn(
                'discovery_state',
                EmailProviderReconciliationFolder::REMOTE_DISCOVERY_STATES,
            )
            ->where('status', EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS)
            ->orderBy('id')
            ->first();
        if ($remoteFolder) {
            $this->withActiveFinalizationRun(
                $run,
                fn (EmailProviderReconciliationRun $lockedRun) => $this->finalizeRemoteFolder(
                    $lockedRun,
                    $remoteFolder,
                ),
            );

            return false;
        }

        $localOnly = $run->folders()
            ->where('discovery_state', EmailProviderReconciliationFolder::DISCOVERY_LOCAL_ONLY)
            ->where('status', EmailProviderReconciliationFolder::STATUS_PENDING)
            ->orderBy('id')
            ->first();
        if ($localOnly) {
            $this->withActiveFinalizationRun(
                $run,
                fn (EmailProviderReconciliationRun $lockedRun) => $this->finalizeLocalOnlyFolder(
                    $lockedRun,
                    $localOnly,
                ),
            );

            return false;
        }

        if ($run->items()
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->where('automation_required', true)
            ->where(
                'automation_status',
                EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION,
            )->exists()) {
            $this->withActiveFinalizationRun(
                $run,
                fn (EmailProviderReconciliationRun $lockedRun) => $this->automationCorrelator->advance(
                    $lockedRun,
                ),
            );

            return false;
        }

        return $this->finishRun($run);
    }

    /** Seal scan/import writers before the first provider end-state read. */
    private function enterFinalizationPhase(EmailProviderReconciliationRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $locked = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($run->id);
            if (! $locked || $locked->terminal()
                || (int) $locked->active_slot !== 1
                || $locked->cancellation_requested_at !== null
                || $locked->final_summary_status !== null
                || ! in_array($locked->status, [
                    EmailProviderReconciliationRun::STATUS_RUNNING,
                    EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
                ], true)
                || ! in_array($locked->phase, [
                    EmailProviderReconciliationRun::PHASE_SCAN,
                    EmailProviderReconciliationRun::PHASE_IMPORTS,
                ], true)) {
                return;
            }

            $remoteScanPending = $locked->folders()
                ->whereIn(
                    'discovery_state',
                    EmailProviderReconciliationFolder::REMOTE_DISCOVERY_STATES,
                )
                ->whereIn('status', [
                    EmailProviderReconciliationFolder::STATUS_PENDING,
                    EmailProviderReconciliationFolder::STATUS_SCANNING,
                ])->exists();
            $importPending = $locked->items()
                ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
                ->whereIn('status', [
                    EmailProviderReconciliationItem::STATUS_PENDING,
                    EmailProviderReconciliationItem::STATUS_RUNNING,
                ])->exists();
            $baselinePending = $locked->items()
                ->where('historical_baseline_required', true)
                ->whereIn('historical_baseline_status', [
                    EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
                    EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING,
                ])->exists();
            $automationRunning = $locked->items()
                ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
                ->where('automation_required', true)
                ->whereIn('automation_status', [
                    EmailProviderReconciliationItem::AUTOMATION_PENDING,
                    EmailProviderReconciliationItem::AUTOMATION_RUNNING,
                    EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT,
                ])->exists();
            if ($remoteScanPending || $importPending || $baselinePending || $automationRunning) {
                return;
            }

            $locked->forceFill([
                'status' => EmailProviderReconciliationRun::STATUS_RUNNING,
                // An automation/import wait may occur after provider end
                // discovery is already frozen. Resume that durable phase
                // directly; otherwise FINALIZE would skip discoverEnd while
                // still being ineligible for the bounded run summary.
                'phase' => $locked->end_folder_scope_hash === null
                    ? EmailProviderReconciliationRun::PHASE_FINALIZE
                    : EmailProviderReconciliationRun::PHASE_DISCOVER_END,
                'last_progress_at' => now(),
            ])->save();
        }, 3);
    }

    /** Preserve cancellation and advance progress only when the barrier changes. */
    private function markWaitingForImports(EmailProviderReconciliationRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            $locked = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->findOrFail($run->id);
            if ($locked->terminal()
                || $locked->status === EmailProviderReconciliationRun::STATUS_CANCELLING
                || $locked->cancellation_requested_at !== null
                || (int) $locked->active_slot !== 1) {
                return false;
            }

            $locked->forceFill([
                'status' => EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
                'phase' => EmailProviderReconciliationRun::PHASE_IMPORTS,
            ]);
            if (! $locked->isDirty(['status', 'phase'])) {
                return false;
            }

            $locked->forceFill(['last_progress_at' => now()])->save();

            return true;
        }, 3);
    }

    /**
     * End discovery is complete only when the reader returns a typed complete
     * folder list. Exceptions and timeouts never become an empty scope.
     */
    private function discoverEnd(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationReader $reader,
    ): bool {
        $this->bindings->currentAccount($run);
        $runtime = $reader->binding((int) $run->account_id, (int) $run->provider_binding_version);
        $this->bindings->recordResolvedRuntime($run, $runtime);
        $discovered = $reader->discoverFolders(
            (int) $run->account_id,
            (int) $run->provider_binding_version,
            (int) $run->provider_time_cap_seconds,
        );
        $scope = $this->validatedScope($run, $discovered);
        $endHash = $this->fingerprints->folderScope($scope);

        DB::transaction(function () use ($run, $endHash): void {
            $locked = EmailProviderReconciliationRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->terminal()
                || $locked->status === EmailProviderReconciliationRun::STATUS_CANCELLING
                || $locked->cancellation_requested_at !== null
                || (int) $locked->active_slot !== 1
                || ! in_array($locked->status, [
                    EmailProviderReconciliationRun::STATUS_RUNNING,
                    EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
                ], true)
                || ! in_array($locked->phase, [
                    EmailProviderReconciliationRun::PHASE_IMPORTS,
                    EmailProviderReconciliationRun::PHASE_FINALIZE,
                    EmailProviderReconciliationRun::PHASE_DISCOVER_END,
                ], true)
                || $locked->end_folder_scope_hash !== null) {
                return;
            }

            $locked->forceFill([
                'status' => EmailProviderReconciliationRun::STATUS_RUNNING,
                'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_END,
                'end_folder_scope_hash' => $endHash,
                'last_progress_at' => now(),
            ])->save();

            if (! hash_equals((string) $locked->start_folder_scope_hash, $endHash)) {
                $locked->folders()
                    ->whereIn('status', [
                        EmailProviderReconciliationFolder::STATUS_PENDING,
                        EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
                    ])
                    ->update([
                        'status' => EmailProviderReconciliationFolder::STATUS_STALE,
                        'reason_code' => 'folder_scope_drift',
                        'finished_at' => now(),
                        'updated_at' => now(),
                    ]);
                $locked->forceFill([
                    // Keep the active run recoverable while the bounded
                    // correlator terminalizes any awaiting automation. No
                    // item from a drifted account scope may be promoted.
                    'status' => EmailProviderReconciliationRun::STATUS_RUNNING,
                    'failure_code' => 'provider_folder_scope_drift',
                ])->save();
            }
        }, 3);

        return $run->fresh()->terminal();
    }

    private function captureEndState(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationFolder $folderRun,
        EmailProviderReconciliationReader $reader,
    ): void {
        $this->bindings->currentAccount($run);
        $runtime = $reader->binding((int) $run->account_id, (int) $run->provider_binding_version);
        $this->bindings->recordResolvedRuntime($run, $runtime);
        $state = $reader->folderState(
            (int) $run->account_id,
            (int) $run->provider_binding_version,
            (string) $folderRun->folder_path,
            (int) $run->provider_time_cap_seconds,
        );

        DB::transaction(function () use ($folderRun, $run, $state): void {
            $lockedRun = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($run->id);
            $lockedFolder = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($folderRun->id);
            if (! $this->activeFinalizationRun($lockedRun)
                || ! $lockedFolder
                || (int) $lockedFolder->email_provider_reconciliation_run_id
                    !== (int) $lockedRun->id
                || $lockedFolder->status
                    !== EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS
                || $lockedFolder->end_uid_validity !== null) {
                return;
            }

            $progressAt = now();
            $lockedFolder->forceFill([
                'end_uid_validity' => $state->uidValidity,
                'end_uid_next' => $state->uidNext,
                'end_exists_count' => $state->existsCount,
                'end_highest_modseq' => $state->supportsModseq ? $state->highestModseq : null,
                'end_supports_modseq' => $state->supportsModseq,
                'last_progress_at' => $progressAt,
            ])->save();
            $lockedRun->forceFill(['last_progress_at' => $progressAt])->save();
        }, 3);
    }

    private function finalizeRemoteFolder(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationFolder $folderRun,
    ): bool {
        $folderRun = $folderRun->fresh();
        if (! $folderRun
            || (int) $folderRun->email_provider_reconciliation_run_id !== (int) $run->id
            || ! in_array(
                $folderRun->discovery_state,
                EmailProviderReconciliationFolder::REMOTE_DISCOVERY_STATES,
                true,
            )
            || $folderRun->status
                !== EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS
            || ! in_array(
                $folderRun->reason_code,
                EmailProviderReconciliationFolder::STABLE_EVIDENCE_REASON_CODES,
                true,
            )) {
            return false;
        }

        if ($folderRun->expected_uid_validity !== null
            && (int) $folderRun->end_uid_validity !== (int) $folderRun->expected_uid_validity) {
            $this->blockFolderForUidValidityChange($folderRun);

            return true;
        }

        if ($this->hasUnsuccessfulImport($folderRun)) {
            $run->markAutomationScopeUnsafe();
            $this->markFolderStale($folderRun, 'import_incomplete');

            return true;
        }

        if (! in_array($folderRun->reason_code, [
            'stable_absence_freeze',
            'stable_operation_projection',
            'stable_absence_projection',
        ], true)) {
            $reason = $this->stabilityFailure($folderRun);
            if ($reason === self::PLACEMENT_SNAPSHOT_PENDING) {
                return true;
            }
            if ($reason !== null) {
                $this->markFolderStale($folderRun, $reason);

                return true;
            }

            // Freeze all potential absence evidence before changing any
            // placement version ourselves. This preserves the exact scan-time
            // sync version used by the eventual destructive local projection.
            $folderRun->forceFill([
                'reason_code' => 'stable_absence_freeze',
                'last_progress_at' => now(),
            ])->save();

            return true;
        }

        if ($folderRun->reason_code === 'stable_absence_freeze') {
            // Candidate writes are bounded and do not touch placements. Once
            // all candidates exist, one exact post-freeze snapshot catches
            // any concurrent version change before projection begins. This
            // avoids an O(n²) re-hash for very large missing inventories.
            if ($this->createAbsenceCandidates($run, $folderRun)) {
                return true;
            }
            $snapshotMatches = $this->placementSnapshotMatchesScan(
                $folderRun,
                EmailProviderReconciliationFolder::SNAPSHOT_REMOTE_PROJECTION,
            );
            if ($snapshotMatches === null) {
                return true;
            }
            if (! $snapshotMatches) {
                $this->markFolderStale($folderRun, 'placement_version_drift');

                return true;
            }

            $folderRun->forceFill([
                'reason_code' => 'stable_operation_projection',
                'last_progress_at' => now(),
            ])->save();

            return true;
        }

        if ($folderRun->reason_code === 'stable_operation_projection') {
            $observations = EmailProviderReconciliationItem::query()
                ->where('email_provider_reconciliation_folder_id', $folderRun->id)
                ->where('kind', EmailProviderReconciliationItem::KIND_OBSERVATION)
                ->where('status', EmailProviderReconciliationItem::STATUS_PENDING)
                ->whereNotNull('source_placement_id')
                ->orderBy('id')
                ->limit(self::DB_BATCH_SIZE)
                ->get();
            if ($observations->isNotEmpty()) {
                foreach ($observations as $observation) {
                    if (! $this->placements->applyStableObservation($run, $observation)) {
                        $this->markFolderStale($folderRun, 'placement_version_drift');

                        return true;
                    }
                }

                return true;
            }

            $conflicts = EmailProviderReconciliationItem::query()
                ->where('email_provider_reconciliation_folder_id', $folderRun->id)
                ->where('kind', EmailProviderReconciliationItem::KIND_OPERATION_CONFLICT)
                ->where('status', EmailProviderReconciliationItem::STATUS_PENDING)
                ->orderBy('id')
                ->limit(self::DB_BATCH_SIZE)
                ->get();
            if ($conflicts->isNotEmpty()) {
                foreach ($conflicts as $conflict) {
                    if (! $this->finalizeObservedOperation($run, $conflict)) {
                        $this->markFolderStale($folderRun, 'placement_version_drift');

                        return true;
                    }
                }

                return true;
            }

            $folderRun->forceFill([
                'reason_code' => 'stable_absence_projection',
                'last_progress_at' => now(),
            ])->save();

            return true;
        }

        $pending = EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_folder_id', $folderRun->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE)
            ->where('status', EmailProviderReconciliationItem::STATUS_PENDING)
            ->orderBy('id')
            ->limit(self::DB_BATCH_SIZE)
            ->get();
        if ($pending->isNotEmpty()) {
            foreach ($pending as $item) {
                $this->projectAbsence($run, $item);
            }

            return true;
        }

        if ($this->hasStaleAbsence($folderRun)) {
            $this->markFolderStale($folderRun, 'placement_version_drift');

            return true;
        }

        $summary = $this->advanceFolderItemSummary($run, $folderRun);
        if (! $summary['complete']) {
            return $summary['progressed'];
        }
        $folderRun = $folderRun->fresh();
        if ($folderRun->item_summary_nonterminal) {
            $this->markFolderStale($folderRun, 'folder_item_summary_nonterminal');

            return true;
        }

        return $this->completeRemoteFolder($run, $folderRun);
    }

    private function validateRemoteFolder(EmailProviderReconciliationFolder $folderRun): bool
    {
        $folderRun = $folderRun->fresh();
        if (! $folderRun
            || ! in_array(
                $folderRun->discovery_state,
                EmailProviderReconciliationFolder::REMOTE_DISCOVERY_STATES,
                true,
            )
            || $folderRun->status
                !== EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS
            || $folderRun->end_uid_validity === null
            || in_array(
                $folderRun->reason_code,
                EmailProviderReconciliationFolder::STABLE_EVIDENCE_REASON_CODES,
                true,
            )) {
            return false;
        }

        if ($folderRun->expected_uid_validity !== null
            && (int) $folderRun->end_uid_validity !== (int) $folderRun->expected_uid_validity) {
            $this->blockFolderForUidValidityChange($folderRun);

            return true;
        }
        if ($this->hasUnsuccessfulImport($folderRun)) {
            $this->markFolderStale($folderRun, 'import_incomplete');

            return true;
        }

        $reason = $this->stabilityFailure($folderRun);
        if ($reason === self::PLACEMENT_SNAPSHOT_PENDING) {
            return true;
        }
        if ($reason !== null) {
            $this->markFolderStale($folderRun, $reason);

            return true;
        }

        $folderRun->forceFill([
            'reason_code' => 'stable_end_validated',
            'last_progress_at' => now(),
        ])->save();

        return true;
    }

    private function finalizeObservedOperation(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationItem $conflict,
    ): bool {
        $observation = EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $conflict->email_provider_reconciliation_run_id)
            ->where('email_provider_reconciliation_folder_id', $conflict->email_provider_reconciliation_folder_id)
            ->where('uid_namespace_id', $conflict->uid_namespace_id)
            ->where('imap_uid', $conflict->imap_uid)
            ->where('kind', EmailProviderReconciliationItem::KIND_OBSERVATION)
            ->first();
        if (! $observation || ! $this->placements->applyStableObservation($run, $observation)) {
            return false;
        }

        $this->operations->reconcileStableFlagObservation($observation->fresh());
        $this->operations->reconcileStableSourcePresence($observation->fresh());
        $unresolved = $observation->source_placement_id
            ? $this->operations->hasUnresolvedForPlacement((int) $observation->source_placement_id)
            : false;
        $conflict->forceFill([
            'status' => $unresolved
                ? EmailProviderReconciliationItem::STATUS_CONFLICT
                : EmailProviderReconciliationItem::STATUS_PROJECTED,
            'error_code' => $unresolved ? 'provider_operation_ambiguous' : null,
            'placement_sync_version_after' => $observation->fresh()->placement_sync_version_after,
            'completed_at' => now(),
        ])->save();

        return true;
    }

    private function stabilityFailure(EmailProviderReconciliationFolder $folderRun): ?string
    {
        $start = new EmailProviderReconciliationFolderState(
            uidValidity: (int) $folderRun->start_uid_validity,
            uidNext: (int) $folderRun->start_uid_next,
            existsCount: (int) $folderRun->start_exists_count,
            supportsModseq: (bool) $folderRun->supports_modseq,
            highestModseq: $folderRun->supports_modseq
                ? (int) $folderRun->start_highest_modseq
                : null,
        );
        $end = new EmailProviderReconciliationFolderState(
            uidValidity: (int) $folderRun->end_uid_validity,
            uidNext: (int) $folderRun->end_uid_next,
            existsCount: (int) $folderRun->end_exists_count,
            supportsModseq: (bool) $folderRun->end_supports_modseq,
            highestModseq: $folderRun->end_supports_modseq
                ? (int) $folderRun->end_highest_modseq
                : null,
        );
        if (! $start->stableWith($end)) {
            return 'provider_tuple_drift';
        }
        if ((int) $folderRun->observed_count !== $start->existsCount) {
            return 'provider_inventory_count_mismatch';
        }
        // The scan terminal may precede import completion, so the capability
        // frozen at EXAMINE—not mutable item/source timing—owns this gate.
        $requiresMetadataVerification = ! $start->supportsModseq;
        if ($requiresMetadataVerification
            && ($folderRun->metadata_verification_status
                    !== EmailProviderReconciliationFolder::METADATA_VERIFICATION_COMPLETED
                || ! is_string($folderRun->metadata_verification_hash)
                || ! is_string($folderRun->inventory_hash)
                || ! hash_equals(
                    $folderRun->inventory_hash,
                    $folderRun->metadata_verification_hash,
                )
                || (int) $folderRun->metadata_verification_count
                    !== (int) $folderRun->observed_count)) {
            return 'provider_nomodseq_inventory_drift';
        }
        if (! is_string($folderRun->placement_baseline_hash)
            || ! is_string($folderRun->placement_scan_hash)
            || ! hash_equals($folderRun->placement_baseline_hash, $folderRun->placement_scan_hash)) {
            return 'placement_version_drift';
        }

        $namespaceActive = EmailFolderUidNamespace::query()
            ->whereKey($folderRun->uid_namespace_id)
            ->where('email_folder_id', $folderRun->email_folder_id)
            ->where('uid_validity', $folderRun->expected_uid_validity)
            ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
            ->exists();
        $folderPointsToNamespace = EmailFolder::query()
            ->whereKey($folderRun->email_folder_id)
            ->where('active_uid_namespace_id', $folderRun->uid_namespace_id)
            ->exists();
        if (! $namespaceActive || ! $folderPointsToNamespace) {
            return 'uid_namespace_not_active';
        }

        $snapshotMatches = $this->placementSnapshotMatchesScan(
            $folderRun,
            EmailProviderReconciliationFolder::SNAPSHOT_REMOTE_END,
        );
        if ($snapshotMatches === null) {
            return self::PLACEMENT_SNAPSHOT_PENDING;
        }
        if (! $snapshotMatches) {
            return 'placement_version_drift';
        }

        return null;
    }

    private function placementSnapshotMatchesScan(
        EmailProviderReconciliationFolder $folderRun,
        string $purpose,
    ): ?bool {
        if (! is_string($folderRun->placement_scan_hash)) {
            return false;
        }

        $snapshot = $this->placementSnapshots->advance(
            $folderRun,
            $purpose,
            (int) $folderRun->baseline_max_placement_id,
        );
        if (! $snapshot['complete']) {
            return null;
        }

        return hash_equals($folderRun->placement_scan_hash, $snapshot['hash']);
    }

    private function hasStaleAbsence(EmailProviderReconciliationFolder $folderRun): bool
    {
        return EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_folder_id', $folderRun->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE)
            ->where('status', EmailProviderReconciliationItem::STATUS_STALE)
            ->exists();
    }

    /** Return true when a batch was created. */
    private function createAbsenceCandidates(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationFolder $folderRun,
    ): bool {
        $placements = EmailMailboxPlacement::query()
            ->where('account_id', $folderRun->account_id)
            ->where('email_folder_id', $folderRun->email_folder_id)
            ->where('uid_namespace_id', $folderRun->uid_namespace_id)
            ->where('id', '<=', $folderRun->baseline_max_placement_id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->where(function (Builder $query) use ($folderRun): void {
                $query->whereNull('last_provider_reconciliation_run_id')
                    ->orWhere('last_provider_reconciliation_run_id', '!=', $folderRun->email_provider_reconciliation_run_id);
            })
            ->whereNotExists(function ($query) use ($folderRun): void {
                $query->selectRaw('1')
                    ->from('email_provider_reconciliation_items as evidence')
                    ->whereColumn('evidence.source_placement_id', 'email_mailbox_placements.id')
                    ->where('evidence.email_provider_reconciliation_run_id', $folderRun->email_provider_reconciliation_run_id)
                    ->where('evidence.kind', EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE);
            })
            ->orderBy('id')
            ->limit(self::DB_BATCH_SIZE)
            ->get();
        if ($placements->isEmpty()) {
            return false;
        }

        foreach ($placements as $placement) {
            $identityHash = is_string($placement->last_provider_observed_identity_hash)
                && preg_match(
                    '/\A[0-9a-f]{64}\z/D',
                    $placement->last_provider_observed_identity_hash,
                ) === 1
                    ? $placement->last_provider_observed_identity_hash
                    : null;
            $observedVersion = $placement->last_provider_observed_sync_version === null
                ? null
                : (int) $placement->last_provider_observed_sync_version;
            if ($identityHash === null
                || $observedVersion === null
                || $observedVersion < 1
                || $observedVersion !== (int) $placement->sync_version
                || $placement->last_provider_observed_at === null) {
                $run->markAutomationScopeUnsafe();
            }
            EmailProviderReconciliationItem::query()->firstOrCreate([
                'email_provider_reconciliation_run_id' => $folderRun->email_provider_reconciliation_run_id,
                'email_provider_reconciliation_folder_id' => $folderRun->id,
                'uid_namespace_id' => $folderRun->uid_namespace_id,
                'imap_uid' => $placement->imap_uid,
                'kind' => EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE,
            ], [
                'status' => EmailProviderReconciliationItem::STATUS_PENDING,
                'source_placement_id' => $placement->id,
                'identity_hash' => $identityHash,
                // Destructive projection remains authorized by the current
                // post-freeze placement version. Weak prior provider evidence
                // blocks account automation via the monotonic run bit but
                // does not prevent a stable provider absence from hiding it.
                'placement_sync_version_before' => max(1, (int) $placement->sync_version),
            ]);
        }

        return true;
    }

    private function projectAbsence(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationItem $item,
    ): void {
        $operationTargetId = $this->operations->reconcileStableSourceAbsence($item);
        if ($item->source_placement_id
            && $this->operations->hasUnresolvedForPlacement((int) $item->source_placement_id)) {
            $operation = $this->operations->oldestUnresolvedForPlacement(
                (int) $item->source_placement_id,
            );
            EmailProviderReconciliationItem::query()->firstOrCreate([
                'email_provider_reconciliation_run_id' => $item->email_provider_reconciliation_run_id,
                'email_provider_reconciliation_folder_id' => $item->email_provider_reconciliation_folder_id,
                'uid_namespace_id' => $item->uid_namespace_id,
                'imap_uid' => $item->imap_uid,
                'kind' => EmailProviderReconciliationItem::KIND_OPERATION_CONFLICT,
            ], [
                'status' => EmailProviderReconciliationItem::STATUS_CONFLICT,
                'source_placement_id' => $item->source_placement_id,
                'email_remote_operation_id' => $operation?->id,
                'placement_sync_version_before' => $item->placement_sync_version_before,
                'error_code' => 'provider_absence_operation_ambiguous',
                'completed_at' => now(),
            ]);
            $item->forceFill([
                'status' => EmailProviderReconciliationItem::STATUS_CONFLICT,
                'email_remote_operation_id' => $operation?->id,
                'error_code' => 'provider_absence_operation_ambiguous',
                'completed_at' => now(),
            ])->save();
            $run->markAutomationScopeUnsafe();

            return;
        }

        $targets = $operationTargetId
            ? [$operationTargetId]
            : $this->moveTargets($item);
        if (count($targets) === 1) {
            $targetId = $targets[0];
            DB::transaction(function () use ($item, $run, $targetId): void {
                $lockedRun = EmailProviderReconciliationRun::query()
                    ->lockForUpdate()
                    ->find($run->id);
                $folder = EmailProviderReconciliationFolder::query()
                    ->lockForUpdate()
                    ->find($item->email_provider_reconciliation_folder_id);
                $lockedAbsence = EmailProviderReconciliationItem::query()
                    ->lockForUpdate()
                    ->find($item->id);
                if (! $this->activeFinalizationRun($lockedRun)
                    || ! $folder
                    || ! $lockedAbsence
                    || (int) $folder->email_provider_reconciliation_run_id
                        !== (int) $lockedRun->id
                    || (int) $lockedAbsence->email_provider_reconciliation_run_id
                        !== (int) $lockedRun->id
                    || $lockedAbsence->terminal()) {
                    return;
                }
                $move = EmailProviderReconciliationItem::query()->firstOrCreate([
                    'email_provider_reconciliation_run_id' => $lockedAbsence->email_provider_reconciliation_run_id,
                    'email_provider_reconciliation_folder_id' => $lockedAbsence->email_provider_reconciliation_folder_id,
                    'uid_namespace_id' => $lockedAbsence->uid_namespace_id,
                    'imap_uid' => $lockedAbsence->imap_uid,
                    'kind' => EmailProviderReconciliationItem::KIND_MOVE_CANDIDATE,
                ], [
                    'status' => EmailProviderReconciliationItem::STATUS_PENDING,
                    'source_placement_id' => $lockedAbsence->source_placement_id,
                    'target_placement_id' => $targetId,
                    'email_remote_operation_id' => $lockedAbsence->email_remote_operation_id,
                    'identity_hash' => $lockedAbsence->identity_hash,
                ]);
                $move = EmailProviderReconciliationItem::query()
                    ->lockForUpdate()
                    ->findOrFail($move->id);
                $lockedAbsence->forceFill(['target_placement_id' => $targetId])->save();
                $this->absences->confirmMissing(
                    $lockedRun,
                    $lockedAbsence,
                    EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE,
                );

                $absenceResult = $lockedAbsence->fresh();
                $moveStatus = in_array($absenceResult->status, [
                    EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE,
                    EmailProviderReconciliationItem::STATUS_CONFLICT,
                    EmailProviderReconciliationItem::STATUS_STALE,
                ], true)
                    ? $absenceResult->status
                    : EmailProviderReconciliationItem::STATUS_STALE;
                $move->forceFill([
                    'status' => $moveStatus,
                    'target_placement_id' => $targetId,
                    'email_remote_operation_id' => $absenceResult->email_remote_operation_id,
                    'error_code' => $moveStatus === EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE
                        ? null
                        : ($absenceResult->error_code ?? 'move_source_changed'),
                    'completed_at' => now(),
                ])->save();
            }, 3);

            return;
        }

        if (count($targets) > 1) {
            EmailProviderReconciliationItem::query()->firstOrCreate([
                'email_provider_reconciliation_run_id' => $item->email_provider_reconciliation_run_id,
                'email_provider_reconciliation_folder_id' => $item->email_provider_reconciliation_folder_id,
                'uid_namespace_id' => $item->uid_namespace_id,
                'imap_uid' => $item->imap_uid,
                'kind' => EmailProviderReconciliationItem::KIND_MOVE_CANDIDATE,
            ], [
                'status' => EmailProviderReconciliationItem::STATUS_CONFLICT,
                'source_placement_id' => $item->source_placement_id,
                'identity_hash' => $item->identity_hash,
                'error_code' => 'provider_move_identity_ambiguous',
                'completed_at' => now(),
            ]);
            $run->markAutomationScopeUnsafe();
        }

        $this->absences->confirmMissing($run, $item);
    }

    /** @return array<int, int> */
    private function moveTargets(EmailProviderReconciliationItem $item): array
    {
        if (! is_string($item->identity_hash)
            || preg_match('/\A[0-9a-f]{64}\z/D', $item->identity_hash) !== 1) {
            return [];
        }

        $accountId = (int) $item->run()->value('account_id');
        $candidates = EmailMailboxPlacement::query()
            ->select([
                'email_mailbox_placements.*',
                'target_folders.folder_path as reconciliation_target_folder_path',
            ])
            ->join(
                'email_provider_reconciliation_folders as target_folders',
                function ($join) use ($item): void {
                    $join->on(
                        'target_folders.email_folder_id',
                        '=',
                        'email_mailbox_placements.email_folder_id',
                    )->on(
                        'target_folders.uid_namespace_id',
                        '=',
                        'email_mailbox_placements.uid_namespace_id',
                    )->where(
                        'target_folders.email_provider_reconciliation_run_id',
                        '=',
                        $item->email_provider_reconciliation_run_id,
                    );
                },
            )
            ->where(function (Builder $query): void {
                $query->where(
                    'target_folders.status',
                    EmailProviderReconciliationFolder::STATUS_COMPLETE,
                )->orWhere(function (Builder $waiting): void {
                    $waiting->where(
                        'target_folders.status',
                        EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
                    )->whereIn(
                        'target_folders.reason_code',
                        EmailProviderReconciliationFolder::STABLE_EVIDENCE_REASON_CODES,
                    );
                });
            })
            ->whereColumn(
                'target_folders.expected_uid_validity',
                'email_mailbox_placements.imap_uid_validity',
            )
            ->whereColumn(
                'target_folders.start_uid_validity',
                'email_mailbox_placements.imap_uid_validity',
            )
            ->whereColumn(
                'target_folders.end_uid_validity',
                'email_mailbox_placements.imap_uid_validity',
            )
            ->where('email_mailbox_placements.account_id', $accountId)
            ->where('email_mailbox_placements.last_provider_reconciliation_run_id', $item->email_provider_reconciliation_run_id)
            ->where('email_mailbox_placements.last_provider_observed_identity_hash', $item->identity_hash)
            ->where('email_mailbox_placements.id', '!=', $item->source_placement_id)
            ->distinct()
            ->orderBy('email_mailbox_placements.id')
            ->limit(3)
            ->get();

        return $candidates
            ->take(2)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function completeRemoteFolder(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationFolder $folderRun,
    ): bool {
        return DB::transaction(function () use ($folderRun, $run): bool {
            $lockedRun = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($run->id);
            $locked = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($folderRun->id);
            if (! $this->activeFinalizationRun($lockedRun)
                || ! $locked
                || (int) $locked->email_provider_reconciliation_run_id !== (int) $lockedRun->id
                || $locked->status !== EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS
                || $locked->reason_code !== 'stable_absence_projection'
                || $locked->item_summary_status
                    !== EmailProviderReconciliationFolder::ITEM_SUMMARY_SEALED
                || $locked->item_summary_nonterminal) {
                return false;
            }

            $locked->forceFill([
                'status' => EmailProviderReconciliationFolder::STATUS_COMPLETE,
                'reason_code' => null,
                'missing_count' => $locked->item_summary_missing_count,
                'conflict_count' => $locked->item_summary_conflict_count,
                'finished_at' => now(),
                'last_progress_at' => now(),
            ])->save();

            EmailFolder::query()->whereKey($locked->email_folder_id)->update([
                'uid_validity' => $locked->end_uid_validity,
                'uid_next' => $locked->end_uid_next,
                'highest_modseq' => $locked->supports_modseq ? $locked->end_highest_modseq : null,
                'exists_count' => $locked->end_exists_count,
                'sync_status' => $locked->import_policy === EmailProviderReconciliationFolder::IMPORT_BASELINE_ONLY
                    ? EmailFolder::SYNC_BASELINED
                    : EmailFolder::SYNC_SYNCED,
                'last_synced_at' => now(),
                'sync_error_code' => null,
                'sync_error_message' => null,
                'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }

    private function finalizeLocalOnlyFolder(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationFolder $folderRun,
    ): bool {
        $folderRun = $folderRun->fresh();
        if (! $folderRun
            || (int) $folderRun->email_provider_reconciliation_run_id !== (int) $run->id
            || $folderRun->discovery_state
                !== EmailProviderReconciliationFolder::DISCOVERY_LOCAL_ONLY
            || $folderRun->status !== EmailProviderReconciliationFolder::STATUS_PENDING) {
            return false;
        }

        $completedHistory = EmailProviderReconciliationFolder::query()
            ->join('email_provider_reconciliation_runs as history_runs', 'history_runs.id', '=', 'email_provider_reconciliation_folders.email_provider_reconciliation_run_id')
            ->where('email_provider_reconciliation_folders.email_folder_id', $folderRun->email_folder_id)
            ->where('email_provider_reconciliation_folders.id', '!=', $folderRun->id)
            ->whereIn('history_runs.status', [
                EmailProviderReconciliationRun::STATUS_COMPLETED,
                EmailProviderReconciliationRun::STATUS_COMPLETED_WITH_CONFLICTS,
            ]);
        $latestCompletedPresenceAt = (clone $completedHistory)
            ->where('email_provider_reconciliation_folders.status', EmailProviderReconciliationFolder::STATUS_COMPLETE)
            ->max('email_provider_reconciliation_folders.finished_at');
        $priorQuery = (clone $completedHistory)
            ->where('email_provider_reconciliation_folders.status', EmailProviderReconciliationFolder::STATUS_MISSING_CANDIDATE)
            ->where('email_provider_reconciliation_folders.finished_at', '<=', $run->started_at->copy()->subSeconds((int) $run->normal_interval_seconds))
            ->when($latestCompletedPresenceAt !== null, function (Builder $query) use ($latestCompletedPresenceAt): void {
                // A complete provider reappearance resets the two-cycle
                // absence chain. Stale/partial cycles prove neither state.
                $query->where(
                    'email_provider_reconciliation_folders.finished_at',
                    '>',
                    $latestCompletedPresenceAt,
                );
            });
        $prior = $priorQuery->orderByDesc('email_provider_reconciliation_folders.finished_at')
            ->select('email_provider_reconciliation_folders.*')
            ->first();

        if (! $prior) {
            $folderRun->forceFill([
                'status' => EmailProviderReconciliationFolder::STATUS_MISSING_CANDIDATE,
                'reason_code' => 'provider_folder_missing_first_cycle',
                'finished_at' => now(),
            ])->save();

            return true;
        }

        if ($this->operations->hasUnresolvedForFolder((int) $folderRun->email_folder_id)) {
            $folderRun->forceFill([
                'status' => EmailProviderReconciliationFolder::STATUS_BLOCKED,
                'reason_code' => 'provider_folder_operation_ambiguous',
                'conflict_count' => 1,
                'finished_at' => now(),
            ])->save();

            return true;
        }

        if (! in_array($folderRun->reason_code, [
            'stable_folder_absence_freeze',
            'stable_folder_absence_projection',
        ], true)) {
            $activeNamespaceId = EmailFolder::query()
                ->whereKey($folderRun->email_folder_id)
                ->value('active_uid_namespace_id');
            if (! $activeNamespaceId || ! EmailFolderUidNamespace::query()
                ->whereKey($activeNamespaceId)
                ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
                ->exists()) {
                $folderRun->forceFill([
                    'status' => EmailProviderReconciliationFolder::STATUS_BLOCKED,
                    'reason_code' => 'uid_namespace_not_active',
                    'finished_at' => now(),
                ])->save();

                return true;
            }

            $baseline = $this->placementSnapshots->advance(
                $folderRun,
                EmailProviderReconciliationFolder::SNAPSHOT_LOCAL_FREEZE,
            );
            if (! $baseline['complete']) {
                return true;
            }
            $folderRun->forceFill([
                'uid_namespace_id' => $activeNamespaceId,
                'baseline_max_placement_id' => $baseline['through_id'],
                'baseline_placement_count' => $baseline['count'],
                'placement_baseline_hash' => $baseline['hash'],
                'placement_scan_hash' => $baseline['hash'],
                'reason_code' => 'stable_folder_absence_freeze',
                'last_progress_at' => now(),
            ])->save();

            return true;
        }

        if ($folderRun->reason_code === 'stable_folder_absence_freeze') {
            if ($this->createAbsenceCandidates($run, $folderRun->fresh())) {
                return true;
            }
            $snapshotMatches = $this->placementSnapshotMatchesScan(
                $folderRun,
                EmailProviderReconciliationFolder::SNAPSHOT_LOCAL_PROJECTION,
            );
            if ($snapshotMatches === null) {
                return true;
            }
            if (! $snapshotMatches) {
                $this->markFolderStale($folderRun, 'placement_version_drift');

                return true;
            }

            $folderRun->forceFill([
                'reason_code' => 'stable_folder_absence_projection',
                'last_progress_at' => now(),
            ])->save();

            return true;
        }

        $pending = EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_folder_id', $folderRun->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE)
            ->where('status', EmailProviderReconciliationItem::STATUS_PENDING)
            ->orderBy('id')
            ->limit(self::DB_BATCH_SIZE)
            ->get();
        if ($pending->isNotEmpty()) {
            foreach ($pending as $item) {
                $this->projectAbsence($run, $item);
            }

            return true;
        }

        if ($this->hasStaleAbsence($folderRun)) {
            $this->markFolderStale($folderRun, 'placement_version_drift');

            return true;
        }

        $summary = $this->advanceFolderItemSummary($run, $folderRun);
        if (! $summary['complete']) {
            return $summary['progressed'];
        }
        $folderRun = $folderRun->fresh();
        if ($folderRun->item_summary_nonterminal) {
            $this->markFolderStale($folderRun, 'folder_item_summary_nonterminal');

            return true;
        }

        EmailFolder::query()->whereKey($folderRun->email_folder_id)->update([
            'is_selectable' => false,
            'sync_enabled' => false,
            'sync_status' => EmailFolder::SYNC_SHADOW,
            'last_synced_at' => now(),
            'sync_error_code' => 'PROVIDER_FOLDER_MISSING_CONFIRMED',
            'sync_error_message' => 'Two complete provider reconciliation cycles confirmed that this folder is absent.',
            'updated_at' => now(),
        ]);
        $folderRun->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_MISSING_CONFIRMED,
            'reason_code' => 'provider_folder_missing_confirmed',
            'missing_count' => $folderRun->item_summary_missing_count,
            'conflict_count' => $folderRun->item_summary_conflict_count,
            'finished_at' => now(),
        ])->save();

        return true;
    }

    private function hasUnsuccessfulImport(EmailProviderReconciliationFolder $folderRun): bool
    {
        return EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_folder_id', $folderRun->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->whereIn('status', [
                EmailProviderReconciliationItem::STATUS_CONFLICT,
                EmailProviderReconciliationItem::STATUS_STALE,
                EmailProviderReconciliationItem::STATUS_FAILED,
                EmailProviderReconciliationItem::STATUS_CANCELLED,
            ])->exists();
    }

    private function markFolderStale(EmailProviderReconciliationFolder $folderRun, string $reason): void
    {
        $folderRun->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_STALE,
            'reason_code' => $reason,
            'finished_at' => now(),
            'last_progress_at' => now(),
        ])->save();
    }

    /**
     * A UIDVALIDITY reset invalidates every UID observation from this cycle.
     * Surface the existing explicit rebaseline contract instead of treating
     * the reset as ordinary tuple drift or projecting any frozen evidence.
     */
    private function blockFolderForUidValidityChange(
        EmailProviderReconciliationFolder $folderRun,
    ): void {
        DB::transaction(function () use ($folderRun): void {
            $locked = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->findOrFail($folderRun->id);
            if ($locked->terminal()
                || $locked->expected_uid_validity === null
                || (int) $locked->end_uid_validity === (int) $locked->expected_uid_validity) {
                return;
            }

            $folder = EmailFolder::query()
                ->whereKey($locked->email_folder_id)
                ->where('account_id', $locked->account_id)
                ->lockForUpdate()
                ->first();
            $folder?->forceFill([
                'sync_status' => EmailFolder::SYNC_ERROR,
                'sync_error_code' => 'IMAP_UIDVALIDITY_CHANGED',
                'sync_error_message' => 'The provider UID namespace changed and requires explicit re-baselining.',
            ])->save();

            $locked->forceFill([
                'status' => EmailProviderReconciliationFolder::STATUS_BLOCKED,
                'reason_code' => 'uidvalidity_changed',
                'finished_at' => now(),
                'last_progress_at' => now(),
            ])->save();
        }, 3);
    }

    /**
     * Freeze and consume one folder-item page. The caller must return after a
     * page; only a later invocation may publish the sealed folder outcome.
     *
     * @return array{complete: bool, progressed: bool}
     */
    private function advanceFolderItemSummary(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationFolder $folderRun,
    ): array {
        return DB::transaction(function () use ($folderRun, $run): array {
            $lockedRun = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($run->id);
            $lockedFolder = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($folderRun->id);
            if (! $this->activeFinalizationRun($lockedRun)
                || ! $lockedFolder
                || (int) $lockedFolder->email_provider_reconciliation_run_id
                    !== (int) $lockedRun->id
                || ! $this->folderReadyForItemSummary($lockedFolder)) {
                return ['complete' => false, 'progressed' => false];
            }

            if ($lockedFolder->item_summary_status === null) {
                $throughId = (int) (EmailProviderReconciliationItem::query()
                    ->where('email_provider_reconciliation_folder_id', $lockedFolder->id)
                    ->max('id') ?? 0);
                $lockedFolder->forceFill([
                    'item_summary_status' => $throughId === 0
                        ? EmailProviderReconciliationFolder::ITEM_SUMMARY_SEALED
                        : EmailProviderReconciliationFolder::ITEM_SUMMARY_RUNNING,
                    'item_summary_through_id' => $throughId,
                    'item_summary_cursor_id' => 0,
                    'item_summary_started_at' => now(),
                    'item_summary_completed_at' => $throughId === 0 ? now() : null,
                    'last_progress_at' => now(),
                ])->save();

                return ['complete' => false, 'progressed' => true];
            }

            if ($lockedFolder->item_summary_status
                === EmailProviderReconciliationFolder::ITEM_SUMMARY_SEALED) {
                $latestId = (int) (EmailProviderReconciliationItem::query()
                    ->where('email_provider_reconciliation_folder_id', $lockedFolder->id)
                    ->max('id') ?? 0);
                if ($latestId > (int) $lockedFolder->item_summary_through_id) {
                    $lockedRun->markAutomationScopeUnsafe();
                    $lockedFolder->forceFill([
                        'item_summary_status' => EmailProviderReconciliationFolder::ITEM_SUMMARY_RUNNING,
                        'item_summary_through_id' => $latestId,
                        'item_summary_nonterminal' => true,
                        'item_summary_completed_at' => null,
                        'last_progress_at' => now(),
                    ])->save();

                    return ['complete' => false, 'progressed' => true];
                }

                return ['complete' => true, 'progressed' => false];
            }

            if ($lockedFolder->item_summary_status
                !== EmailProviderReconciliationFolder::ITEM_SUMMARY_RUNNING) {
                return ['complete' => false, 'progressed' => false];
            }

            $items = EmailProviderReconciliationItem::query()
                ->select([
                    'id',
                    'kind',
                    'status',
                    'historical_baseline_required',
                    'historical_baseline_status',
                ])
                ->where('email_provider_reconciliation_folder_id', $lockedFolder->id)
                ->where('id', '>', $lockedFolder->item_summary_cursor_id)
                ->where('id', '<=', $lockedFolder->item_summary_through_id)
                ->orderBy('id')
                ->limit(self::DB_BATCH_SIZE)
                ->lockForUpdate()
                ->get();
            $lastId = (int) ($items->last()?->id ?? $lockedFolder->item_summary_through_id);
            $exhausted = $items->count() < self::DB_BATCH_SIZE
                || $lastId >= (int) $lockedFolder->item_summary_through_id;
            $missing = $items->where(
                'status',
                EmailProviderReconciliationItem::STATUS_CONFIRMED_MISSING,
            )->count();
            $moves = $items->filter(fn (EmailProviderReconciliationItem $item): bool => $item->kind === EmailProviderReconciliationItem::KIND_MOVE_CANDIDATE
                && $item->status === EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE
            )->count();
            $conflicts = $items->where(
                'status',
                EmailProviderReconciliationItem::STATUS_CONFLICT,
            )->count();
            $nonterminal = $items->contains(
                fn (EmailProviderReconciliationItem $item): bool => ! $item->terminal()
                    || ($item->historical_baseline_required
                        && ! $item->historicalBaselineTerminal()),
            );
            $lockedFolder->forceFill([
                'item_summary_status' => $exhausted
                    ? EmailProviderReconciliationFolder::ITEM_SUMMARY_SEALED
                    : EmailProviderReconciliationFolder::ITEM_SUMMARY_RUNNING,
                'item_summary_cursor_id' => $exhausted
                    ? $lockedFolder->item_summary_through_id
                    : $lastId,
                'item_summary_missing_count' => (int) $lockedFolder->item_summary_missing_count + $missing,
                'item_summary_move_count' => (int) $lockedFolder->item_summary_move_count + $moves,
                'item_summary_conflict_count' => (int) $lockedFolder->item_summary_conflict_count + $conflicts,
                'item_summary_nonterminal' => (bool) $lockedFolder->item_summary_nonterminal || $nonterminal,
                'item_summary_batch_count' => (int) $lockedFolder->item_summary_batch_count + 1,
                'item_summary_completed_at' => $exhausted ? now() : null,
                'last_progress_at' => now(),
            ])->save();

            return ['complete' => false, 'progressed' => true];
        }, 3);
    }

    private function folderReadyForItemSummary(
        EmailProviderReconciliationFolder $folder,
    ): bool {
        return ($folder->status === EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS
                && $folder->reason_code === 'stable_absence_projection')
            || ($folder->status === EmailProviderReconciliationFolder::STATUS_PENDING
                && $folder->discovery_state
                    === EmailProviderReconciliationFolder::DISCOVERY_LOCAL_ONLY
                && $folder->reason_code === 'stable_folder_absence_projection');
    }

    /**
     * Serialize every DB-only projection against HTTP cancellation. The run
     * lock remains held through nested folder/item/placement transactions, so
     * either the bounded projection commits first or cancellation intent wins
     * and the callback performs no write. A callback must report whether it
     * made durable progress; a stale/no-op callback never refreshes liveness.
     */
    private function withActiveFinalizationRun(
        EmailProviderReconciliationRun $run,
        callable $callback,
    ): bool {
        return DB::transaction(function () use ($callback, $run): bool {
            $locked = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($run->id);
            if (! $this->activeFinalizationRun($locked)) {
                return false;
            }

            $progressed = (bool) $callback($locked);
            if (! $progressed) {
                return false;
            }

            // The callback and this heartbeat share the cancellation-owned
            // run lock. Exceptions roll both back, while a cancellation that
            // wins the lock prevents both the bounded step and its progress.
            $locked->forceFill(['last_progress_at' => now()])->save();

            return true;
        }, 3);
    }

    private function activeFinalizationRun(?EmailProviderReconciliationRun $run): bool
    {
        return $run
            && $run->status === EmailProviderReconciliationRun::STATUS_RUNNING
            && $run->phase === EmailProviderReconciliationRun::PHASE_DISCOVER_END
            && (int) $run->active_slot === 1
            && $run->cancellation_requested_at === null
            && $run->final_summary_status === null;
    }

    private function finishRun(EmailProviderReconciliationRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            $locked = EmailProviderReconciliationRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->terminal()) {
                return true;
            }
            if (! $this->activeSummaryRun($locked)) {
                return false;
            }

            if ($locked->final_summary_status === null) {
                return $this->initializeFinalSummary($locked);
            }
            if ($locked->final_summary_status
                === EmailProviderReconciliationRun::FINAL_SUMMARY_FOLDERS) {
                $this->advanceFinalFolderSummary($locked);

                return false;
            }
            if ($locked->final_summary_status
                === EmailProviderReconciliationRun::FINAL_SUMMARY_ITEMS) {
                $this->advanceFinalItemSummary($locked);

                return false;
            }
            if ($locked->final_summary_status
                !== EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED) {
                return false;
            }

            return $this->publishFinalSummary($locked);
        }, 3);
    }

    private function activeSummaryRun(EmailProviderReconciliationRun $run): bool
    {
        return $run->status === EmailProviderReconciliationRun::STATUS_RUNNING
            && in_array($run->phase, [
                EmailProviderReconciliationRun::PHASE_DISCOVER_END,
                EmailProviderReconciliationRun::PHASE_SUMMARY,
            ], true)
            && (int) $run->active_slot === 1
            && $run->cancellation_requested_at === null;
    }

    private function initializeFinalSummary(EmailProviderReconciliationRun $run): bool
    {
        if ($run->phase !== EmailProviderReconciliationRun::PHASE_DISCOVER_END
            || $this->hasNonterminalAutomation($run)) {
            $run->forceFill(['last_progress_at' => now()])->save();

            return false;
        }

        $folderThroughId = (int) (EmailProviderReconciliationFolder::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->max('id') ?? 0);
        $itemThroughId = (int) (EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->max('id') ?? 0);
        $status = $folderThroughId > 0
            ? EmailProviderReconciliationRun::FINAL_SUMMARY_FOLDERS
            : ($itemThroughId > 0
                ? EmailProviderReconciliationRun::FINAL_SUMMARY_ITEMS
                : EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED);
        $run->forceFill([
            'phase' => EmailProviderReconciliationRun::PHASE_SUMMARY,
            'final_summary_status' => $status,
            'final_summary_folder_through_id' => $folderThroughId,
            'final_summary_folder_cursor_id' => 0,
            'final_summary_item_through_id' => $itemThroughId,
            'final_summary_item_cursor_id' => 0,
            'final_summary_started_at' => now(),
            'final_summary_completed_at' => $status
                === EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED
                ? now()
                : null,
            'last_progress_at' => now(),
        ])->save();

        return false;
    }

    private function advanceFinalFolderSummary(EmailProviderReconciliationRun $run): void
    {
        $folders = EmailProviderReconciliationFolder::query()
            ->select(['id', 'status'])
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('id', '>', $run->final_summary_folder_cursor_id)
            ->where('id', '<=', $run->final_summary_folder_through_id)
            ->orderBy('id')
            ->limit(self::DB_BATCH_SIZE)
            ->lockForUpdate()
            ->get();
        $lastId = (int) ($folders->last()?->id ?? $run->final_summary_folder_through_id);
        $exhausted = $folders->count() < self::DB_BATCH_SIZE
            || $lastId >= (int) $run->final_summary_folder_through_id;
        // `folder_count` is the provider LIST denominator, so this public
        // counter represents only clean remote-folder completions. Local-only
        // evidence and stale/blocked/failed terminal rows must not inflate it.
        $completeCount = $folders->where(
            'status',
            EmailProviderReconciliationFolder::STATUS_COMPLETE,
        )->count();
        $nonterminal = $folders->contains(
            fn (EmailProviderReconciliationFolder $folder): bool => ! $folder->terminal(),
        );
        $blockedCount = $folders->where(
            'status',
            EmailProviderReconciliationFolder::STATUS_BLOCKED,
        )->count();
        $failedCount = $folders->where(
            'status',
            EmailProviderReconciliationFolder::STATUS_FAILED,
        )->count();
        $stale = $folders->contains(
            'status',
            EmailProviderReconciliationFolder::STATUS_STALE,
        );
        $nextStatus = $exhausted
            ? ((int) $run->final_summary_item_cursor_id
                < (int) $run->final_summary_item_through_id
                ? EmailProviderReconciliationRun::FINAL_SUMMARY_ITEMS
                : EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED)
            : EmailProviderReconciliationRun::FINAL_SUMMARY_FOLDERS;
        $run->forceFill([
            'final_summary_status' => $nextStatus,
            'final_summary_folder_cursor_id' => $exhausted
                ? $run->final_summary_folder_through_id
                : $lastId,
            'final_summary_complete_folder_count' => (int) $run->final_summary_complete_folder_count + $completeCount,
            'final_summary_conflict_count' => (int) $run->final_summary_conflict_count + $blockedCount,
            'final_summary_error_count' => (int) $run->final_summary_error_count + $failedCount,
            'final_summary_blocked' => (bool) $run->final_summary_blocked || $blockedCount > 0,
            'final_summary_failed' => (bool) $run->final_summary_failed || $failedCount > 0,
            'final_summary_stale' => (bool) $run->final_summary_stale || $stale || $nonterminal,
            'final_summary_batch_count' => (int) $run->final_summary_batch_count + 1,
            'final_summary_completed_at' => $nextStatus
                === EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED
                ? now()
                : null,
            'last_progress_at' => now(),
        ])->save();
    }

    private function advanceFinalItemSummary(EmailProviderReconciliationRun $run): void
    {
        $items = EmailProviderReconciliationItem::query()
            ->select(['id', 'kind', 'status', 'automation_required', 'automation_status'])
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('id', '>', $run->final_summary_item_cursor_id)
            ->where('id', '<=', $run->final_summary_item_through_id)
            ->orderBy('id')
            ->limit(self::DB_BATCH_SIZE)
            ->lockForUpdate()
            ->get();
        $lastId = (int) ($items->last()?->id ?? $run->final_summary_item_through_id);
        $exhausted = $items->count() < self::DB_BATCH_SIZE
            || $lastId >= (int) $run->final_summary_item_through_id;
        $missing = $items->where(
            'status',
            EmailProviderReconciliationItem::STATUS_CONFIRMED_MISSING,
        )->count();
        $moves = $items->filter(fn (EmailProviderReconciliationItem $item): bool => $item->kind === EmailProviderReconciliationItem::KIND_MOVE_CANDIDATE
            && $item->status === EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE
        )->count();
        $conflicts = $items->where(
            'status',
            EmailProviderReconciliationItem::STATUS_CONFLICT,
        )->count();
        $failed = $items->where(
            'status',
            EmailProviderReconciliationItem::STATUS_FAILED,
        )->count();
        $nonterminal = $items->contains(
            fn (EmailProviderReconciliationItem $item): bool => ! $item->terminal(),
        );
        $automationFailures = $items->filter(fn (EmailProviderReconciliationItem $item): bool => $item->automation_required
            && $item->automation_status === EmailProviderReconciliationItem::AUTOMATION_FAILED
        )->count();
        $nextStatus = $exhausted
            ? EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED
            : EmailProviderReconciliationRun::FINAL_SUMMARY_ITEMS;
        $run->forceFill([
            'final_summary_status' => $nextStatus,
            'final_summary_item_cursor_id' => $exhausted
                ? $run->final_summary_item_through_id
                : $lastId,
            'final_summary_missing_count' => (int) $run->final_summary_missing_count + $missing,
            'final_summary_move_count' => (int) $run->final_summary_move_count + $moves,
            'final_summary_conflict_count' => (int) $run->final_summary_conflict_count + $conflicts,
            'final_summary_error_count' => (int) $run->final_summary_error_count + $failed + $automationFailures,
            'final_summary_failed' => (bool) $run->final_summary_failed || $failed > 0,
            'final_summary_automation_failed' => (bool) $run->final_summary_automation_failed || $automationFailures > 0,
            'final_summary_stale' => (bool) $run->final_summary_stale || $nonterminal,
            'final_summary_batch_count' => (int) $run->final_summary_batch_count + 1,
            'final_summary_completed_at' => $exhausted ? now() : null,
            'last_progress_at' => now(),
        ])->save();
    }

    private function publishFinalSummary(EmailProviderReconciliationRun $run): bool
    {
        $latestFolderId = (int) (EmailProviderReconciliationFolder::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->max('id') ?? 0);
        $latestItemId = (int) (EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->max('id') ?? 0);
        if ($latestFolderId > (int) $run->final_summary_folder_through_id
            || $latestItemId > (int) $run->final_summary_item_through_id) {
            $run->markAutomationScopeUnsafe();
            $run->forceFill([
                'final_summary_status' => $latestFolderId
                    > (int) $run->final_summary_folder_through_id
                    ? EmailProviderReconciliationRun::FINAL_SUMMARY_FOLDERS
                    : EmailProviderReconciliationRun::FINAL_SUMMARY_ITEMS,
                'final_summary_folder_through_id' => max(
                    $latestFolderId,
                    (int) $run->final_summary_folder_through_id,
                ),
                'final_summary_item_through_id' => max(
                    $latestItemId,
                    (int) $run->final_summary_item_through_id,
                ),
                'final_summary_stale' => true,
                'final_summary_completed_at' => null,
                'last_progress_at' => now(),
            ])->save();

            return false;
        }
        if ($this->hasNonterminalAutomation($run)) {
            $run->forceFill([
                'failure_code' => 'provider_reconciliation_final_summary_automation_drift',
                'last_progress_at' => now(),
            ])->save();

            return false;
        }

        $status = $run->final_summary_blocked
            ? EmailProviderReconciliationRun::STATUS_BLOCKED
            : ($run->final_summary_failed || $run->final_summary_automation_failed
                ? EmailProviderReconciliationRun::STATUS_PARTIAL
                : ($run->final_summary_stale
                    ? EmailProviderReconciliationRun::STATUS_STALE
                    : ((int) $run->final_summary_conflict_count > 0
                        ? EmailProviderReconciliationRun::STATUS_COMPLETED_WITH_CONFLICTS
                        : EmailProviderReconciliationRun::STATUS_COMPLETED)));
        $run->forceFill([
            'status' => $status,
            'active_slot' => null,
            'complete_folder_count' => $run->final_summary_complete_folder_count,
            'missing_count' => $run->final_summary_missing_count,
            'move_count' => $run->final_summary_move_count,
            'conflict_count' => $run->final_summary_conflict_count,
            'error_count' => $run->final_summary_error_count,
            'failure_code' => in_array($status, [
                EmailProviderReconciliationRun::STATUS_COMPLETED,
                EmailProviderReconciliationRun::STATUS_COMPLETED_WITH_CONFLICTS,
            ], true) ? null : 'provider_reconciliation_'.$status,
            'finished_at' => now(),
            'last_progress_at' => now(),
        ])->save();

        return true;
    }

    private function hasNonterminalAutomation(EmailProviderReconciliationRun $run): bool
    {
        return EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->where('automation_required', true)
            ->whereIn('automation_status', [
                EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION,
                EmailProviderReconciliationItem::AUTOMATION_PENDING,
                EmailProviderReconciliationItem::AUTOMATION_RUNNING,
                EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT,
            ])->exists();
    }

    private function finishCancelled(EmailProviderReconciliationRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            $locked = EmailProviderReconciliationRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->terminal()) {
                return true;
            }
            if ($locked->status !== EmailProviderReconciliationRun::STATUS_CANCELLING) {
                return false;
            }

            $automation = $locked->items()
                ->where('automation_required', true);
            $cancellableAutomationIds = (clone $automation)
                ->whereIn('automation_status', [
                    EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION,
                    EmailProviderReconciliationItem::AUTOMATION_PENDING,
                ])
                ->orderBy('id')
                ->limit(self::DB_BATCH_SIZE)
                ->lockForUpdate()
                ->pluck('id');
            if ($cancellableAutomationIds->isNotEmpty()) {
                EmailProviderReconciliationItem::query()
                    ->whereIn('id', $cancellableAutomationIds)
                    ->whereIn('automation_status', [
                        EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION,
                        EmailProviderReconciliationItem::AUTOMATION_PENDING,
                    ])
                    ->update([
                        'automation_status' => EmailProviderReconciliationItem::AUTOMATION_CANCELLED,
                        'automation_claim_token' => null,
                        'automation_completed_at' => now(),
                        'automation_error_code' => 'provider_reconciliation_automation_cancelled',
                        'updated_at' => now(),
                    ]);
                $locked->forceFill(['last_progress_at' => now()])->save();

                return false;
            }
            $abandonedAutomationIds = (clone $automation)
                ->where('automation_status', EmailProviderReconciliationItem::AUTOMATION_RUNNING)
                ->where(function (Builder $age): void {
                    $age->whereNull('automation_last_attempt_at')
                        ->orWhere('automation_last_attempt_at', '<=', now()->subSeconds(
                            \App\Modules\Email\Jobs\ProcessEmailProviderReconciliationAutomation::ABANDONED_CLAIM_SECONDS,
                        ));
                })
                ->orderBy('id')
                ->limit(self::DB_BATCH_SIZE)
                ->lockForUpdate()
                ->pluck('id');
            if ($abandonedAutomationIds->isNotEmpty()) {
                EmailProviderReconciliationItem::query()
                    ->whereIn('id', $abandonedAutomationIds)
                    ->where('automation_status', EmailProviderReconciliationItem::AUTOMATION_RUNNING)
                    ->update([
                        'automation_status' => EmailProviderReconciliationItem::AUTOMATION_FAILED,
                        'automation_claim_token' => null,
                        'automation_completed_at' => now(),
                        'automation_error_code' => 'provider_reconciliation_automation_worker_lost',
                        'updated_at' => now(),
                    ]);
                $locked->forceFill(['last_progress_at' => now()])->save();

                return false;
            }

            $freshAutomationRunning = (clone $automation)
                ->where('automation_status', EmailProviderReconciliationItem::AUTOMATION_RUNNING)
                ->exists();
            if ($freshAutomationRunning) {
                return false;
            }

            if ((clone $automation)
                ->where(
                    'automation_status',
                    EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT,
                )->exists()) {
                // Notification-owned fanout is already durable and may have
                // committed canonical/outbox rows. Drain it to a truthful
                // terminal outcome instead of truncating or cancelling it.
                return false;
            }

            $baselineItems = $locked->items()
                ->where('historical_baseline_required', true);
            $freshBaselineRunning = (clone $baselineItems)
                ->where(
                    'historical_baseline_status',
                    EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING,
                )
                ->where(
                    'historical_baseline_last_attempt_at',
                    '>',
                    now()->subSeconds(
                        \App\Modules\Email\Actions\ProjectHistoricalEmailReadBaseline::ABANDONED_RECONCILIATION_CLAIM_SECONDS,
                    ),
                )
                ->exists();
            if ($freshBaselineRunning) {
                // A bounded DB-only worker may still be projecting personal
                // read baselines. Keep the run CANCELLING and preserve its
                // token until that worker finishes or crosses the orphan TTL.
                return false;
            }

            // A sealed folder summary protects main item evidence. Reset at
            // most one page of non-published summaries before cancellation
            // mutates a historical-baseline or ordinary child. Completed
            // folder summaries have no nonterminal main item by contract.
            $summaryFolderIds = collect();
            foreach ([
                EmailProviderReconciliationFolder::ITEM_SUMMARY_RUNNING,
                EmailProviderReconciliationFolder::ITEM_SUMMARY_SEALED,
            ] as $summaryStatus) {
                foreach ([
                    EmailProviderReconciliationFolder::STATUS_PENDING,
                    EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
                    EmailProviderReconciliationFolder::STATUS_STALE,
                    EmailProviderReconciliationFolder::STATUS_BLOCKED,
                    EmailProviderReconciliationFolder::STATUS_FAILED,
                ] as $folderStatus) {
                    $summaryFolderIds = $locked->folders()
                        ->where('item_summary_status', $summaryStatus)
                        ->where('status', $folderStatus)
                        ->orderBy('id')
                        ->limit(self::DB_BATCH_SIZE)
                        ->lockForUpdate()
                        ->pluck('id');
                    if ($summaryFolderIds->isNotEmpty()) {
                        break 2;
                    }
                }
            }
            if ($summaryFolderIds->isNotEmpty()) {
                EmailProviderReconciliationFolder::query()
                    ->whereIn('id', $summaryFolderIds)
                    ->update([
                        ...EmailProviderReconciliationFolder::emptyItemSummary(),
                        'updated_at' => now(),
                    ]);
                $locked->forceFill(['last_progress_at' => now()])->save();

                return false;
            }

            /** @var \Illuminate\Database\Eloquent\Collection<int, EmailProviderReconciliationItem> $baselineCancellationBatch */
            $baselineCancellationBatch = (clone $baselineItems)
                ->whereIn('historical_baseline_status', [
                    EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
                    EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING,
                ])
                ->orderBy('id')
                ->limit(self::DB_BATCH_SIZE)
                ->lockForUpdate()
                ->get();
            if ($baselineCancellationBatch->isNotEmpty()) {
                $now = now();
                foreach ($baselineCancellationBatch as $item) {
                    $placement = $item->result_placement_id
                        ? EmailMailboxPlacement::query()
                            ->whereKey($item->result_placement_id)
                            ->where('account_id', $locked->account_id)
                            ->lockForUpdate()
                            ->first()
                        : null;
                    if ($placement) {
                        $placement->forceFill([
                            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
                            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
                            'sync_error_code' => EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
                            'sync_error_message' => null,
                        ])->save();
                        $this->conversations->refreshForPlacement($placement->refresh());
                    }

                    $item->forceFill([
                        'status' => EmailProviderReconciliationItem::STATUS_CANCELLED,
                        'error_code' => 'cancelled',
                        'completed_at' => $now,
                        'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_CANCELLED,
                        'historical_baseline_claim_token' => null,
                        'historical_baseline_completed_at' => $now,
                        'historical_baseline_error_code' => 'cancelled',
                    ])->save();
                }

                $locked->forceFill(['last_progress_at' => $now])->save();

                return false;
            }
            $folderIds = $locked->folders()->whereIn('status', [
                EmailProviderReconciliationFolder::STATUS_PENDING,
                EmailProviderReconciliationFolder::STATUS_SCANNING,
                EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            ])->orderBy('id')
                ->limit(self::DB_BATCH_SIZE)
                ->lockForUpdate()
                ->pluck('id');
            if ($folderIds->isNotEmpty()) {
                EmailProviderReconciliationFolder::query()
                    ->whereIn('id', $folderIds)
                    ->update([
                        ...EmailProviderReconciliationFolder::emptyItemSummary(),
                        'status' => EmailProviderReconciliationFolder::STATUS_CANCELLED,
                        'reason_code' => 'cancelled',
                        'finished_at' => now(),
                        'updated_at' => now(),
                    ]);
                $locked->forceFill(['last_progress_at' => now()])->save();

                return false;
            }

            $itemIds = $locked->items()->whereIn('status', [
                EmailProviderReconciliationItem::STATUS_PENDING,
                EmailProviderReconciliationItem::STATUS_RUNNING,
            ])->orderBy('id')
                ->limit(self::DB_BATCH_SIZE)
                ->lockForUpdate()
                ->pluck('id');
            if ($itemIds->isNotEmpty()) {
                EmailProviderReconciliationItem::query()
                    ->whereIn('id', $itemIds)
                    ->update([
                        'status' => EmailProviderReconciliationItem::STATUS_CANCELLED,
                        'error_code' => 'cancelled',
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);
                $locked->forceFill(['last_progress_at' => now()])->save();

                return false;
            }
            $locked->forceFill([
                ...EmailProviderReconciliationRun::emptyFinalSummary(),
                'status' => EmailProviderReconciliationRun::STATUS_CANCELLED,
                'active_slot' => null,
                'finished_at' => now(),
                'failure_code' => 'provider_reconciliation_cancelled',
            ])->save();

            return true;
        }, 3);
    }

    /**
     * @param  array<int, mixed>  $folders
     * @return array<int, EmailProviderReconciliationFolderDescriptor>
     */
    private function validatedScope(EmailProviderReconciliationRun $run, array $folders): array
    {
        $paths = [];
        $scope = [];
        foreach ($folders as $folder) {
            if (! $folder instanceof EmailProviderReconciliationFolderDescriptor) {
                throw new EmailProviderReconciliationReadException('provider_folder_descriptor_invalid');
            }
            if (isset($paths[$folder->path])) {
                throw new EmailProviderReconciliationReadException('provider_folder_path_duplicate');
            }
            $paths[$folder->path] = true;
            if ($folder->selectable && $folder->syncEnabled) {
                $scope[] = $folder;
            }
        }
        if (count($scope) > (int) $run->max_folders) {
            throw new EmailProviderReconciliationReadException('provider_folder_cap_exceeded');
        }

        usort(
            $scope,
            fn (EmailProviderReconciliationFolderDescriptor $left, EmailProviderReconciliationFolderDescriptor $right): int => strcmp($left->path, $right->path),
        );

        return $scope;
    }
}
