<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\DTOs\EmailProviderReconciliationFolderState;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class EmailProviderReconciliationFolderProjector
{
    public function __construct(
        private readonly EmailProviderReconciliationPlacementSnapshot $placements,
    ) {}

    public function initialize(
        EmailProviderReconciliationFolder $folderRun,
        EmailProviderReconciliationFolderState $state,
    ): EmailProviderReconciliationFolder {
        $prepared = DB::transaction(function () use ($folderRun, $state): EmailProviderReconciliationFolder {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($folderRun->email_provider_reconciliation_run_id);
            $locked = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->findOrFail($folderRun->id);

            if (! $this->activeScanRun($run)
                || (int) $locked->email_provider_reconciliation_run_id !== (int) $run->id
                || $locked->status !== EmailProviderReconciliationFolder::STATUS_PENDING) {
                return $locked;
            }
            if ($locked->start_uid_validity !== null) {
                return $locked;
            }

            $folder = EmailFolder::query()
                ->where('account_id', $locked->account_id)
                ->where('path', $locked->folder_path)
                ->lockForUpdate()
                ->first();
            $activeNamespace = $folder?->activeUidNamespace()
                ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
                ->first();

            if ($activeNamespace
                && (int) $activeNamespace->uid_validity !== $state->uidValidity) {
                $folder->forceFill([
                    'sync_status' => EmailFolder::SYNC_ERROR,
                    'sync_error_code' => 'IMAP_UIDVALIDITY_CHANGED',
                    'sync_error_message' => 'The provider UID namespace changed and requires explicit re-baselining.',
                ])->save();
                $locked->forceFill([
                    'email_folder_id' => $folder->id,
                    'uid_namespace_id' => $activeNamespace->id,
                    'expected_uid_validity' => (int) $activeNamespace->uid_validity,
                    'start_uid_validity' => $state->uidValidity,
                    'start_uid_next' => $state->uidNext,
                    'start_exists_count' => $state->existsCount,
                    'start_highest_modseq' => $state->highestModseq,
                    'supports_modseq' => $state->supportsModseq,
                    'status' => EmailProviderReconciliationFolder::STATUS_BLOCKED,
                    'reason_code' => 'uidvalidity_changed',
                    'finished_at' => now(),
                ])->save();

                return $locked->refresh();
            }

            if (! $folder) {
                $folder = new EmailFolder;
                $folder->account_id = $locked->account_id;
                $folder->path = $locked->folder_path;
            }

            $folder->forceFill([
                'provider' => 'imap',
                'name' => $locked->folder_name,
                'delimiter' => $locked->delimiter,
                'parent_path' => $locked->parent_path,
                'remote_id' => $locked->remote_id,
                'special_use' => $locked->special_use,
                'role' => EmailFolder::inferRole(
                    $locked->folder_path,
                    $locked->special_use,
                    $locked->delimiter,
                ),
                'is_selectable' => true,
                'sync_enabled' => true,
                'uid_validity' => $state->uidValidity,
                'uid_next' => $state->uidNext,
                // live_start_uid is the frozen inclusive high-water. Polling
                // and later reconciliation import only UIDs strictly above it.
                'live_start_uid' => $folder->live_start_uid ?? $state->scanThroughUid(),
                'highest_modseq' => $state->supportsModseq ? $state->highestModseq : null,
                'exists_count' => $state->existsCount,
                'sync_status' => EmailFolder::SYNC_SYNCED,
                'last_discovered_at' => now(),
                'sync_error_code' => null,
                'sync_error_message' => null,
            ])->save();

            $activeNamespace ??= $this->activeNamespace($folder, $state);
            if (! $activeNamespace) {
                $locked->forceFill([
                    'email_folder_id' => $folder->id,
                    'expected_uid_validity' => $state->uidValidity,
                    'start_uid_validity' => $state->uidValidity,
                    'start_uid_next' => $state->uidNext,
                    'start_exists_count' => $state->existsCount,
                    'start_highest_modseq' => $state->highestModseq,
                    'supports_modseq' => $state->supportsModseq,
                    'status' => EmailProviderReconciliationFolder::STATUS_BLOCKED,
                    'reason_code' => 'uid_namespace_not_active',
                    'finished_at' => now(),
                ])->save();

                return $locked->refresh();
            }

            if ((int) $folder->active_uid_namespace_id !== (int) $activeNamespace->id) {
                $folder->forceFill(['active_uid_namespace_id' => $activeNamespace->id])->save();
            }

            $locked->forceFill([
                'email_folder_id' => $folder->id,
                'uid_namespace_id' => $activeNamespace->id,
                'expected_uid_validity' => $state->uidValidity,
                'start_uid_validity' => $state->uidValidity,
                'start_uid_next' => $state->uidNext,
                'start_exists_count' => $state->existsCount,
                'start_highest_modseq' => $state->highestModseq,
                'supports_modseq' => $state->supportsModseq,
                'scan_through_uid' => $state->scanThroughUid(),
                'next_uid' => 1,
                'reason_code' => null,
                'last_progress_at' => now(),
            ])->save();

            return $locked->refresh();
        }, 3);

        return $this->continueInitialization($prepared);
    }

    /** Advance at most one DB-only placement-baseline page. */
    public function continueInitialization(
        EmailProviderReconciliationFolder $folderRun,
    ): EmailProviderReconciliationFolder {
        $folderRun = $folderRun->fresh();
        if ($folderRun->status !== EmailProviderReconciliationFolder::STATUS_PENDING
            || $folderRun->start_uid_validity === null
            || ! $folderRun->email_folder_id
            || ! $folderRun->uid_namespace_id) {
            return $folderRun;
        }

        $baseline = $this->placements->advance(
            $folderRun,
            EmailProviderReconciliationFolder::SNAPSHOT_BASELINE,
        );
        if (! $baseline['complete']) {
            return $folderRun->fresh();
        }

        return DB::transaction(function () use ($baseline, $folderRun): EmailProviderReconciliationFolder {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($folderRun->email_provider_reconciliation_run_id);
            $locked = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->findOrFail($folderRun->id);
            if (! $this->activeScanRun($run)
                || (int) $locked->email_provider_reconciliation_run_id !== (int) $run->id
                || $locked->status !== EmailProviderReconciliationFolder::STATUS_PENDING
                || $locked->placement_snapshot_purpose
                    !== EmailProviderReconciliationFolder::SNAPSHOT_BASELINE
                || $locked->placement_snapshot_status
                    !== EmailProviderReconciliationFolder::SNAPSHOT_COMPLETED) {
                return $locked;
            }

            $locked->forceFill([
                'baseline_max_placement_id' => $baseline['through_id'],
                'baseline_placement_count' => $baseline['count'],
                'placement_baseline_hash' => $baseline['hash'],
                'status' => EmailProviderReconciliationFolder::STATUS_SCANNING,
                'reason_code' => null,
                'scan_started_at' => now(),
                'last_progress_at' => now(),
            ])->save();

            return $locked->refresh();
        }, 3);
    }

    private function activeScanRun(?EmailProviderReconciliationRun $run): bool
    {
        return $run
            && $run->status === EmailProviderReconciliationRun::STATUS_RUNNING
            && $run->phase === EmailProviderReconciliationRun::PHASE_SCAN
            && (int) $run->active_slot === 1
            && $run->cancellation_requested_at === null;
    }

    private function activeNamespace(
        EmailFolder $folder,
        EmailProviderReconciliationFolderState $state,
    ): ?EmailFolderUidNamespace {
        $existing = EmailFolderUidNamespace::query()
            ->where('email_folder_id', $folder->id)
            ->where('uid_validity', $state->uidValidity)
            ->first();

        if ($existing) {
            return $existing->status === EmailFolderUidNamespace::STATUS_ACTIVE
                ? $existing
                : null;
        }

        try {
            return EmailFolderUidNamespace::query()->create([
                'account_id' => $folder->account_id,
                'email_folder_id' => $folder->id,
                'generation' => max(1, (int) $folder->uidNamespaces()->max('generation') + 1),
                'uid_validity' => $state->uidValidity,
                'uid_next_at_establishment' => $state->uidNext,
                'live_start_uid' => $folder->live_start_uid,
                'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
                'provenance_code' => 'provider_reconciliation_discovery',
                'established_at' => now(),
            ]);
        } catch (QueryException) {
            return EmailFolderUidNamespace::query()
                ->where('email_folder_id', $folder->id)
                ->where('uid_validity', $state->uidValidity)
                ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
                ->first();
        }
    }
}
