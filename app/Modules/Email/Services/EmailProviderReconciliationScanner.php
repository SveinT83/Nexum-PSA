<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Contracts\EmailProviderReconciliationReader;
use App\Modules\Email\DTOs\EmailProviderReconciliationMessageMetadata;
use App\Modules\Email\Jobs\ImportEmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Support\Facades\DB;

final class EmailProviderReconciliationScanner
{
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const IMPORT_RECOVERY_BATCH_SIZE = 100;

    public function __construct(
        private readonly EmailProviderReconciliationBindingPolicy $bindings,
        private readonly EmailProviderReconciliationFolderProjector $folders,
        private readonly EmailProviderReconciliationPlacementProjector $placements,
        private readonly EmailProviderReconciliationPlacementSnapshot $placementSnapshots,
        private readonly EmailProviderReconciliationFingerprint $fingerprints,
    ) {}

    /**
     * Claim one returned-UID page. The returned import IDs are safe to queue
     * after this method commits.
     *
     * @return array{folder_finished: bool, import_item_ids: array<int, int>}
     */
    public function scanOnePage(
        EmailProviderReconciliationFolder $folderRun,
        EmailProviderReconciliationReader $reader,
    ): array {
        $folderRun = $folderRun->fresh();
        $run = EmailProviderReconciliationRun::query()->findOrFail(
            $folderRun->email_provider_reconciliation_run_id,
        );
        if ($run->terminal()
            || $run->status === EmailProviderReconciliationRun::STATUS_CANCELLING
            || $run->cancellation_requested_at !== null
            || (int) $run->active_slot !== 1
            || $folderRun->terminal()) {
            return ['folder_finished' => true, 'import_item_ids' => []];
        }

        if ($folderRun->status === EmailProviderReconciliationFolder::STATUS_PENDING) {
            if ($folderRun->start_uid_validity === null) {
                $run = $this->resolveRuntime($run, $reader);
                $state = $reader->folderState(
                    (int) $run->account_id,
                    (int) $run->provider_binding_version,
                    (string) $folderRun->folder_path,
                    (int) $run->provider_time_cap_seconds,
                );
                $folderRun = $this->folders->initialize($folderRun, $state);
            } else {
                // The provider start tuple is already frozen. Resume only the
                // bounded DB snapshot and never spend another provider read.
                $folderRun = $this->folders->continueInitialization($folderRun);
            }
            if ($folderRun->terminal()) {
                return ['folder_finished' => true, 'import_item_ids' => []];
            }

            // Folder-state capture is one bounded provider read. A separately
            // queued invocation claims the first metadata page so a slow
            // server cannot multiply the per-job provider time budget.
            return ['folder_finished' => false, 'import_item_ids' => []];
        }

        if ($folderRun->status !== EmailProviderReconciliationFolder::STATUS_SCANNING) {
            return ['folder_finished' => true, 'import_item_ids' => []];
        }

        if ($folderRun->reason_code === 'placement_scan_snapshot_pending') {
            return [
                'folder_finished' => $this->finishScanSnapshot($folderRun),
                'import_item_ids' => [],
            ];
        }
        if ($folderRun->reason_code === 'nomodseq_imports_pending') {
            return [
                'folder_finished' => $this->continueAfterNomodseqImports($folderRun),
                'import_item_ids' => [],
            ];
        }
        if (in_array($folderRun->reason_code, [
            'nomodseq_baseline_pending',
            'nomodseq_verification_pending',
        ], true)) {
            return $this->scanMetadataVerificationPage($folderRun, $run, $reader);
        }

        $run = $this->resolveRuntime($run, $reader);

        $afterUid = max(0, (int) $folderRun->next_uid - 1);
        $page = $reader->metadataPage(
            (int) $run->account_id,
            (int) $run->provider_binding_version,
            (string) $folderRun->folder_path,
            (int) $folderRun->expected_uid_validity,
            $afterUid,
            (int) $folderRun->scan_through_uid,
            (int) $run->uid_batch_size,
            (int) $run->provider_time_cap_seconds,
        );

        $completeThroughUid = $this->validatedCompleteThroughUid(
            $page->completeThroughUid,
            $afterUid,
            (int) $folderRun->scan_through_uid,
            $page->terminal,
        );

        if ($page->messages === []) {
            if ($page->terminal) {
                $finished = $this->finishScan(
                    $folderRun,
                    $run,
                    $afterUid,
                    $completeThroughUid,
                );

                return ['folder_finished' => $finished, 'import_item_ids' => []];
            }

            $this->advanceCursor(
                $folderRun,
                $run,
                $afterUid,
                $completeThroughUid,
                [],
            );

            return ['folder_finished' => false, 'import_item_ids' => []];
        }

        if ($page->terminal) {
            throw new EmailProviderReconciliationReadException('provider_terminal_page_not_empty');
        }

        $messages = $this->validatedMessages(
            $page->messages,
            $afterUid,
            $completeThroughUid,
            (int) $run->uid_batch_size,
        );
        if ($folderRun->supports_modseq) {
            foreach ($messages as $metadata) {
                if ($metadata->modseq === null) {
                    throw new EmailProviderReconciliationReadException(
                        'provider_metadata_modseq_missing',
                    );
                }
            }
        }
        $importItemIds = [];
        foreach ($messages as $metadata) {
            $observed = $this->placements->observe(
                $folderRun,
                $metadata,
                $afterUid,
                $completeThroughUid,
            );
            if ($observed['scope_stale']) {
                return ['folder_finished' => false, 'import_item_ids' => []];
            }
            if ($observed['evidence_drift']) {
                return ['folder_finished' => true, 'import_item_ids' => []];
            }
            if ($observed['import_item_id']) {
                $importItemIds[] = $observed['import_item_id'];
            }
        }

        if (! $this->advanceCursor(
            $folderRun,
            $run,
            $afterUid,
            $completeThroughUid,
            $messages,
        )) {
            return ['folder_finished' => false, 'import_item_ids' => []];
        }

        return [
            'folder_finished' => false,
            'import_item_ids' => array_values(array_unique($importItemIds)),
        ];
    }

    /**
     * Mailboxes without MODSEQ need two matching UID+FLAGS inventories after
     * every detached import PEEK has completed. Folders with no imports may
     * reuse the original scan as the first inventory. Neither verifier pass
     * creates imports or local projections.
     *
     * @return array{folder_finished: bool, import_item_ids: array<int, int>}
     */
    private function scanMetadataVerificationPage(
        EmailProviderReconciliationFolder $folderRun,
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationReader $reader,
    ): array {
        if ($folderRun->supports_modseq
            || $folderRun->metadata_verification_status
                !== EmailProviderReconciliationFolder::METADATA_VERIFICATION_RUNNING
            || ! in_array($folderRun->reason_code, [
                'nomodseq_baseline_pending',
                'nomodseq_verification_pending',
            ], true)) {
            throw new EmailProviderReconciliationReadException(
                'provider_metadata_verification_state_invalid',
            );
        }
        $baselinePass = $folderRun->reason_code === 'nomodseq_baseline_pending';

        $run = $this->resolveRuntime($run, $reader);
        $afterUid = max(0, (int) $folderRun->metadata_verification_next_uid - 1);
        $page = $reader->metadataPage(
            (int) $run->account_id,
            (int) $run->provider_binding_version,
            (string) $folderRun->folder_path,
            (int) $folderRun->expected_uid_validity,
            $afterUid,
            (int) $folderRun->scan_through_uid,
            (int) $run->uid_batch_size,
            (int) $run->provider_time_cap_seconds,
        );
        $completeThroughUid = $this->validatedCompleteThroughUid(
            $page->completeThroughUid,
            $afterUid,
            (int) $folderRun->scan_through_uid,
            $page->terminal,
        );
        if ($page->terminal && $page->messages !== []) {
            throw new EmailProviderReconciliationReadException('provider_terminal_page_not_empty');
        }
        $messages = $page->messages === []
            ? []
            : $this->validatedMessages(
                $page->messages,
                $afterUid,
                $completeThroughUid,
                (int) $run->uid_batch_size,
            );

        $folderFinished = $this->advanceMetadataVerification(
            $folderRun,
            $run,
            $afterUid,
            $completeThroughUid,
            $messages,
            $page->terminal,
            $baselinePass,
        );
        if (! $folderFinished && $page->terminal) {
            $fresh = $folderRun->fresh();
            if ($fresh->reason_code === 'placement_scan_snapshot_pending') {
                $folderFinished = $this->finishScanSnapshot($fresh);
            }
        }

        return ['folder_finished' => $folderFinished, 'import_item_ids' => []];
    }

    /**
     * @param  array<int, EmailProviderReconciliationMessageMetadata>  $messages
     */
    private function advanceMetadataVerification(
        EmailProviderReconciliationFolder $folderRun,
        EmailProviderReconciliationRun $run,
        int $afterUid,
        int $completeThroughUid,
        array $messages,
        bool $terminal,
        bool $baselinePass,
    ): bool {
        return DB::transaction(function () use (
            $folderRun,
            $run,
            $afterUid,
            $completeThroughUid,
            $messages,
            $terminal,
            $baselinePass,
        ): bool {
            $lockedRun = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($run->id);
            $locked = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($folderRun->id);
            if (! $this->activeScanRun($lockedRun)
                || ! $locked
                || (int) $locked->email_provider_reconciliation_run_id !== (int) $lockedRun->id
                || $locked->status !== EmailProviderReconciliationFolder::STATUS_SCANNING
                || $locked->reason_code !== ($baselinePass
                    ? 'nomodseq_baseline_pending'
                    : 'nomodseq_verification_pending')
                || $locked->metadata_verification_status
                    !== EmailProviderReconciliationFolder::METADATA_VERIFICATION_RUNNING
                || (int) $locked->metadata_verification_next_uid - 1 !== $afterUid) {
                return false;
            }

            if ($baselinePass) {
                foreach ($messages as $metadata) {
                    if (! $this->placements->refreshVerifiedObservation($locked, $metadata)) {
                        throw new EmailProviderReconciliationReadException(
                            'provider_metadata_verification_scope_drift',
                        );
                    }
                }
            }

            $nextHash = $this->advanceInventoryHash(
                (string) $locked->metadata_verification_hash,
                $messages,
                false,
                $terminal ? $completeThroughUid : null,
            );
            $nextCount = (int) $locked->metadata_verification_count + count($messages);
            $matches = ! $terminal || $baselinePass || (
                is_string($locked->inventory_hash)
                && hash_equals((string) $locked->inventory_hash, $nextHash)
                && (int) $locked->observed_count === $nextCount
            );
            $baselineCountMatches = ! $terminal
                || ! $baselinePass
                || (int) $locked->observed_count === $nextCount;
            if ($terminal && $baselinePass && $baselineCountMatches) {
                // Pass A begins only after every PEEK/store attempt is no
                // longer retryable. Freeze its exact UID+FLAGS inventory,
                // then reset the same durable cursor for independent pass B.
                $locked->forceFill([
                    'inventory_hash' => $nextHash,
                    'metadata_verification_status' => EmailProviderReconciliationFolder::METADATA_VERIFICATION_RUNNING,
                    'metadata_verification_next_uid' => 1,
                    'metadata_verification_count' => 0,
                    'metadata_verification_hash' => self::EMPTY_SHA256,
                    'metadata_verification_batch_count' => (int) $locked->metadata_verification_batch_count + 1,
                    'metadata_verification_completed_at' => null,
                    'reason_code' => 'nomodseq_verification_pending',
                    'last_progress_at' => now(),
                ])->save();

                $lockedRun->forceFill([
                    'batch_count' => (int) $lockedRun->batch_count + 1,
                    'last_progress_at' => now(),
                ])->save();

                return false;
            }

            $matches = $matches && $baselineCountMatches;
            $completedAt = $terminal ? now() : null;
            $locked->forceFill([
                'status' => $terminal && ! $matches
                    ? EmailProviderReconciliationFolder::STATUS_STALE
                    : EmailProviderReconciliationFolder::STATUS_SCANNING,
                'metadata_verification_status' => $terminal
                    ? ($matches
                        ? EmailProviderReconciliationFolder::METADATA_VERIFICATION_COMPLETED
                        : EmailProviderReconciliationFolder::METADATA_VERIFICATION_FAILED)
                    : EmailProviderReconciliationFolder::METADATA_VERIFICATION_RUNNING,
                'metadata_verification_next_uid' => $completeThroughUid + 1,
                'metadata_verification_count' => $nextCount,
                'metadata_verification_hash' => $nextHash,
                'metadata_verification_batch_count' => (int) $locked->metadata_verification_batch_count + 1,
                'metadata_verification_completed_at' => $completedAt,
                'reason_code' => $terminal
                    ? ($matches
                        ? 'placement_scan_snapshot_pending'
                        : 'provider_nomodseq_inventory_drift')
                    : ($baselinePass
                        ? 'nomodseq_baseline_pending'
                        : 'nomodseq_verification_pending'),
                'finished_at' => $terminal && ! $matches ? $completedAt : null,
                'last_progress_at' => now(),
            ])->save();

            $lockedRun->forceFill([
                'batch_count' => (int) $lockedRun->batch_count + 1,
                'last_progress_at' => now(),
            ])->save();

            return $terminal && ! $matches;
        }, 3);
    }

    /**
     * @param  array<int, EmailProviderReconciliationMessageMetadata>  $messages
     */
    private function advanceCursor(
        EmailProviderReconciliationFolder $folderRun,
        EmailProviderReconciliationRun $run,
        int $afterUid,
        int $completeThroughUid,
        array $messages,
    ): bool {
        return DB::transaction(function () use (
            $folderRun,
            $run,
            $afterUid,
            $completeThroughUid,
            $messages,
        ): bool {
            $lockedRun = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($run->id);
            $lockedFolder = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($folderRun->id);
            if (! $this->activeOrdinaryScanPage(
                $lockedRun,
                $lockedFolder,
                $afterUid,
                $completeThroughUid,
            )) {
                return false;
            }

            $observedCount = count($messages);

            $lockedFolder->forceFill([
                'next_uid' => $completeThroughUid + 1,
                'inventory_hash' => $this->advanceInventoryHash(
                    (string) $lockedFolder->inventory_hash,
                    $messages,
                    (bool) $lockedFolder->supports_modseq,
                ),
                'batch_count' => (int) $lockedFolder->batch_count + 1,
                'observed_count' => (int) $lockedFolder->observed_count + $observedCount,
                'last_progress_at' => now(),
            ])->save();

            $lockedRun->forceFill([
                'batch_count' => (int) $lockedRun->batch_count + 1,
                'observed_count' => (int) $lockedRun->observed_count + max(0, $observedCount),
                'last_progress_at' => now(),
            ])->save();

            return true;
        }, 3);
    }

    private function finishScan(
        EmailProviderReconciliationFolder $folderRun,
        EmailProviderReconciliationRun $run,
        int $afterUid,
        int $completeThroughUid,
    ): bool {
        $terminalCommitted = DB::transaction(function () use (
            $folderRun,
            $run,
            $afterUid,
            $completeThroughUid,
        ): bool {
            $lockedRun = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($run->id);
            $locked = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($folderRun->id);
            if (! $this->activeOrdinaryScanPage(
                $lockedRun,
                $locked,
                $afterUid,
                $completeThroughUid,
            )) {
                return false;
            }

            $ordinaryImap = ! (bool) $locked->supports_modseq;
            $hasImports = EmailProviderReconciliationItem::query()
                ->where('email_provider_reconciliation_folder_id', $locked->id)
                ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
                ->exists();
            $hasRetryableImports = $hasImports && $this->hasRetryableImports($locked);
            $hasUnsuccessfulImports = $hasImports && $this->hasUnsuccessfulImports($locked);
            $awaitImports = $ordinaryImap && $hasRetryableImports;
            $requiresVerification = $ordinaryImap
                && ! $awaitImports
                && ! $hasUnsuccessfulImports;
            $verificationReason = $hasImports
                ? 'nomodseq_baseline_pending'
                : 'nomodseq_verification_pending';
            $completedAt = now();

            $locked->forceFill([
                'next_uid' => $completeThroughUid + 1,
                'inventory_hash' => $this->advanceInventoryHash(
                    (string) $locked->inventory_hash,
                    [],
                    (bool) $locked->supports_modseq,
                    $completeThroughUid,
                ),
                'batch_count' => (int) $locked->batch_count + 1,
                'metadata_verification_status' => $requiresVerification
                    ? EmailProviderReconciliationFolder::METADATA_VERIFICATION_RUNNING
                    : null,
                'metadata_verification_next_uid' => 1,
                'metadata_verification_count' => 0,
                'metadata_verification_hash' => $requiresVerification
                    ? self::EMPTY_SHA256
                    : null,
                'metadata_verification_batch_count' => 0,
                'metadata_verification_started_at' => $requiresVerification ? $completedAt : null,
                'metadata_verification_completed_at' => null,
                'reason_code' => $requiresVerification
                    ? $verificationReason
                    : ($awaitImports
                        ? 'nomodseq_imports_pending'
                        : 'placement_scan_snapshot_pending'),
                'last_progress_at' => now(),
            ])->save();

            $lockedRun->forceFill([
                'batch_count' => (int) $lockedRun->batch_count + 1,
                'last_progress_at' => now(),
            ])->save();

            return true;
        }, 3);

        if (! $terminalCommitted) {
            return false;
        }

        $fresh = $folderRun->fresh();

        return $fresh->reason_code === 'placement_scan_snapshot_pending'
            ? $this->finishScanSnapshot($fresh)
            : false;
    }

    /**
     * Wait without provider I/O until every import PEEK/store attempt has
     * either been accepted or reached a non-retryable outcome. A failed
     * import bypasses flag certification and is surfaced by finalization.
     */
    private function continueAfterNomodseqImports(
        EmailProviderReconciliationFolder $folderRun,
    ): bool {
        $recoveryIds = [];
        $readyForSnapshot = DB::transaction(function () use (
            $folderRun,
            &$recoveryIds,
        ): bool {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($folderRun->email_provider_reconciliation_run_id);
            $locked = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($folderRun->id);
            if (! $this->activeScanRun($run)
                || ! $locked
                || (int) $locked->email_provider_reconciliation_run_id !== (int) $run->id
                || $locked->status !== EmailProviderReconciliationFolder::STATUS_SCANNING
                || $locked->reason_code !== 'nomodseq_imports_pending'
                || $locked->supports_modseq) {
                return false;
            }
            if ($this->hasRetryableImports($locked)) {
                // A folder worker can die after observe/cursor commit but
                // before dispatching the returned import IDs. Recover only a
                // bounded page of never-claimed or abandoned claims; fresh
                // RUNNING ownership remains untouched.
                $recoveryIds = EmailProviderReconciliationItem::query()
                    ->where('email_provider_reconciliation_folder_id', $locked->id)
                    ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
                    ->where(function ($query): void {
                        $query->where('status', EmailProviderReconciliationItem::STATUS_PENDING)
                            ->orWhere(function ($running): void {
                                $running->where('status', EmailProviderReconciliationItem::STATUS_RUNNING)
                                    ->where(function ($abandoned): void {
                                        $abandoned->whereNull('last_attempt_at')
                                            ->orWhere('last_attempt_at', '<=', now()->subSeconds(
                                                EmailProviderReconciliationImporter::ABANDONED_CLAIM_SECONDS,
                                            ));
                                    });
                            });
                    })
                    ->orderBy('id')
                    ->limit(self::IMPORT_RECOVERY_BATCH_SIZE)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                return false;
            }
            if ($this->hasUnsuccessfulImports($locked)) {
                $locked->forceFill([
                    'reason_code' => 'placement_scan_snapshot_pending',
                    'last_progress_at' => now(),
                ])->save();

                return true;
            }

            $locked->forceFill([
                'metadata_verification_status' => EmailProviderReconciliationFolder::METADATA_VERIFICATION_RUNNING,
                'metadata_verification_next_uid' => 1,
                'metadata_verification_count' => 0,
                'metadata_verification_hash' => self::EMPTY_SHA256,
                'metadata_verification_batch_count' => 0,
                'metadata_verification_started_at' => now(),
                'metadata_verification_completed_at' => null,
                'reason_code' => 'nomodseq_baseline_pending',
                'last_progress_at' => now(),
            ])->save();

            return false;
        }, 3);

        // The transaction above has committed; queued workers can now claim
        // the item without observing a half-written folder cursor.
        foreach ($recoveryIds as $itemId) {
            ImportEmailProviderReconciliationItem::dispatch($itemId)->afterCommit();
        }

        return $readyForSnapshot
            ? $this->finishScanSnapshot($folderRun->fresh())
            : false;
    }

    private function hasRetryableImports(
        EmailProviderReconciliationFolder $folderRun,
    ): bool {
        return EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_folder_id', $folderRun->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->whereIn('status', [
                EmailProviderReconciliationItem::STATUS_PENDING,
                EmailProviderReconciliationItem::STATUS_RUNNING,
            ])
            ->exists();
    }

    private function hasUnsuccessfulImports(
        EmailProviderReconciliationFolder $folderRun,
    ): bool {
        return EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_folder_id', $folderRun->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->whereIn('status', [
                EmailProviderReconciliationItem::STATUS_STALE,
                EmailProviderReconciliationItem::STATUS_FAILED,
                EmailProviderReconciliationItem::STATUS_CANCELLED,
            ])
            ->exists();
    }

    /** Advance one post-inventory placement snapshot page. */
    private function finishScanSnapshot(
        EmailProviderReconciliationFolder $folderRun,
    ): bool {
        if ($folderRun->status !== EmailProviderReconciliationFolder::STATUS_SCANNING
            || $folderRun->reason_code !== 'placement_scan_snapshot_pending') {
            return $folderRun->status
                === EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS;
        }

        $snapshot = $this->placementSnapshots->advance(
            $folderRun,
            EmailProviderReconciliationFolder::SNAPSHOT_SCAN_END,
            (int) $folderRun->baseline_max_placement_id,
        );
        if (! $snapshot['complete']) {
            return false;
        }

        return DB::transaction(function () use ($folderRun, $snapshot): bool {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($folderRun->email_provider_reconciliation_run_id);
            $locked = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($folderRun->id);
            if (! $this->activeScanRun($run)
                || ! $locked
                || (int) $locked->email_provider_reconciliation_run_id !== (int) $run->id
                || $locked->status !== EmailProviderReconciliationFolder::STATUS_SCANNING
                || $locked->reason_code !== 'placement_scan_snapshot_pending'
                || $locked->placement_snapshot_purpose
                    !== EmailProviderReconciliationFolder::SNAPSHOT_SCAN_END
                || $locked->placement_snapshot_status
                    !== EmailProviderReconciliationFolder::SNAPSHOT_COMPLETED) {
                return false;
            }

            $hasPendingImports = EmailProviderReconciliationItem::query()
                ->where('email_provider_reconciliation_folder_id', $locked->id)
                ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
                ->whereIn('status', [
                    EmailProviderReconciliationItem::STATUS_PENDING,
                    EmailProviderReconciliationItem::STATUS_RUNNING,
                    EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE,
                ])
                ->exists();
            $locked->forceFill([
                'placement_scan_hash' => $snapshot['hash'],
                'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
                'reason_code' => $hasPendingImports ? 'imports_pending' : null,
                'last_progress_at' => now(),
            ])->save();

            return true;
        }, 3);
    }

    private function resolveRuntime(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationReader $reader,
    ): EmailProviderReconciliationRun {
        $this->bindings->currentAccount($run);
        $runtime = $reader->binding((int) $run->account_id, (int) $run->provider_binding_version);

        return $this->bindings->recordResolvedRuntime($run, $runtime);
    }

    private function activeScanRun(?EmailProviderReconciliationRun $run): bool
    {
        return $run
            && $run->status === EmailProviderReconciliationRun::STATUS_RUNNING
            && $run->phase === EmailProviderReconciliationRun::PHASE_SCAN
            && (int) $run->active_slot === 1
            && $run->cancellation_requested_at === null;
    }

    private function activeOrdinaryScanPage(
        ?EmailProviderReconciliationRun $run,
        ?EmailProviderReconciliationFolder $folder,
        int $afterUid,
        int $completeThroughUid,
    ): bool {
        return $this->activeScanRun($run)
            && $folder
            && (int) $folder->email_provider_reconciliation_run_id === (int) $run->id
            && $folder->status === EmailProviderReconciliationFolder::STATUS_SCANNING
            && $folder->reason_code === null
            && (int) $folder->next_uid - 1 === $afterUid
            && $completeThroughUid >= $afterUid
            && $completeThroughUid <= (int) $folder->scan_through_uid;
    }

    private function validatedCompleteThroughUid(
        ?int $completeThroughUid,
        int $afterUid,
        int $throughUid,
        bool $terminal,
    ): int {
        if ($completeThroughUid === null
            || $completeThroughUid < $afterUid
            || $completeThroughUid > $throughUid
            || $completeThroughUid - $afterUid > EmailProviderReconciliationPolicy::HARD_UID_WINDOW_SPAN
            || (! $terminal && $completeThroughUid === $afterUid)
            || ($terminal && $completeThroughUid !== $throughUid)) {
            throw new EmailProviderReconciliationReadException(
                'provider_metadata_window_incomplete',
            );
        }

        return $completeThroughUid;
    }

    /**
     * @param  array<int, EmailProviderReconciliationMessageMetadata>  $messages
     */
    private function advanceInventoryHash(
        string $currentHash,
        array $messages,
        bool $includeModseq,
        ?int $terminalThroughUid = null,
    ): string {
        foreach ($messages as $metadata) {
            $facts = $metadata->evidenceFacts();
            if (! $includeModseq) {
                unset($facts['modseq']);
            }
            $currentHash = hash(
                'sha256',
                $currentHash.'|'.$this->fingerprints->make($facts),
            );
        }
        if ($terminalThroughUid !== null) {
            $currentHash = hash(
                'sha256',
                $currentHash.'|'.$this->fingerprints->make([
                    'terminal_through_uid' => $terminalThroughUid,
                ]),
            );
        }

        return $currentHash;
    }

    /**
     * @param  array<int, EmailProviderReconciliationMessageMetadata>  $messages
     * @return array<int, EmailProviderReconciliationMessageMetadata>
     */
    private function validatedMessages(
        array $messages,
        int $afterUid,
        int $throughUid,
        int $limit,
    ): array {
        if (count($messages) > $limit) {
            throw new EmailProviderReconciliationReadException('provider_metadata_page_overflow');
        }

        usort(
            $messages,
            fn (EmailProviderReconciliationMessageMetadata $left, EmailProviderReconciliationMessageMetadata $right): int => $left->uid <=> $right->uid,
        );
        $previous = $afterUid;
        foreach ($messages as $message) {
            if (! $message instanceof EmailProviderReconciliationMessageMetadata
                || $message->uid <= $previous
                || $message->uid > $throughUid) {
                throw new EmailProviderReconciliationReadException('provider_metadata_page_invalid');
            }
            $previous = $message->uid;
        }

        return $messages;
    }
}
