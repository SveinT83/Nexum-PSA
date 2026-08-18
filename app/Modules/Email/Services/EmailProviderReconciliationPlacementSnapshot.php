<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Support\Facades\DB;

final class EmailProviderReconciliationPlacementSnapshot
{
    public const MAX_ROWS_PER_INVOCATION = 500;

    private const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * Advance one purpose-owned snapshot page.
     *
     * @return array{purpose:string, complete:bool, through_id:int, cursor_id:int, count:int, hash:string, batch_count:int}
     */
    public function advance(
        EmailProviderReconciliationFolder $folderRun,
        string $purpose,
        ?int $throughPlacementId = null,
        ?int $batchSize = null,
    ): array {
        if (! in_array($purpose, [
            EmailProviderReconciliationFolder::SNAPSHOT_BASELINE,
            EmailProviderReconciliationFolder::SNAPSHOT_SCAN_END,
            EmailProviderReconciliationFolder::SNAPSHOT_REMOTE_END,
            EmailProviderReconciliationFolder::SNAPSHOT_REMOTE_PROJECTION,
            EmailProviderReconciliationFolder::SNAPSHOT_LOCAL_FREEZE,
            EmailProviderReconciliationFolder::SNAPSHOT_LOCAL_PROJECTION,
        ], true)) {
            throw new EmailProviderReconciliationReadException('placement_snapshot_purpose_invalid');
        }
        $throughPlacementId = $throughPlacementId === null
            ? null
            : max(0, $throughPlacementId);
        $batchSize = max(1, min(
            self::MAX_ROWS_PER_INVOCATION,
            $batchSize ?? (int) config(
                'email_provider_reconciliation.placement_snapshot_batch_size',
                self::MAX_ROWS_PER_INVOCATION,
            ),
        ));

        return DB::transaction(function () use (
            $batchSize,
            $folderRun,
            $purpose,
            $throughPlacementId,
        ): array {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($folderRun->email_provider_reconciliation_run_id);
            $locked = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->findOrFail($folderRun->id);
            if (! $this->runAllowsPurpose($run, $purpose)
                || (int) $locked->email_provider_reconciliation_run_id !== (int) $run->id
                || ! $this->folderAllowsPurpose($locked, $purpose)) {
                return $this->result($locked);
            }
            if (! $locked->email_folder_id || $locked->account_id < 1) {
                throw new EmailProviderReconciliationReadException('placement_snapshot_scope_invalid');
            }

            if ($locked->placement_snapshot_purpose !== $purpose) {
                $through = $throughPlacementId ?? (int) EmailMailboxPlacement::query()
                    ->where('account_id', $locked->account_id)
                    ->where('email_folder_id', $locked->email_folder_id)
                    ->max('id');
                $locked->forceFill([
                    'placement_snapshot_purpose' => $purpose,
                    'placement_snapshot_status' => EmailProviderReconciliationFolder::SNAPSHOT_RUNNING,
                    'placement_snapshot_through_id' => max(0, $through),
                    'placement_snapshot_cursor_id' => 0,
                    'placement_snapshot_count' => 0,
                    'placement_snapshot_hash' => self::EMPTY_HASH,
                    'placement_snapshot_batch_count' => 0,
                    'placement_snapshot_started_at' => now(),
                    'placement_snapshot_completed_at' => null,
                    'last_progress_at' => now(),
                ]);
            } elseif ($throughPlacementId !== null
                && (int) $locked->placement_snapshot_through_id !== $throughPlacementId) {
                throw new EmailProviderReconciliationReadException('placement_snapshot_scope_drift');
            }

            if ($locked->placement_snapshot_status === EmailProviderReconciliationFolder::SNAPSHOT_COMPLETED) {
                return $this->result($locked);
            }
            if ($locked->placement_snapshot_status !== EmailProviderReconciliationFolder::SNAPSHOT_RUNNING
                || ! is_string($locked->placement_snapshot_hash)) {
                throw new EmailProviderReconciliationReadException('placement_snapshot_state_invalid');
            }

            $placements = EmailMailboxPlacement::query()
                ->where('account_id', $locked->account_id)
                ->where('email_folder_id', $locked->email_folder_id)
                ->where('id', '>', $locked->placement_snapshot_cursor_id)
                ->where('id', '<=', $locked->placement_snapshot_through_id)
                ->select([
                    'id',
                    'email_message_id',
                    'uid_namespace_id',
                    'imap_uid_validity',
                    'imap_uid',
                    'local_state',
                    'sync_status',
                    'sync_error_code',
                    'sync_version',
                ])
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            $cursor = (int) $locked->placement_snapshot_cursor_id;
            $count = (int) $locked->placement_snapshot_count;
            $hash = (string) $locked->placement_snapshot_hash;
            foreach ($placements as $placement) {
                [$localState, $syncStatus, $syncErrorCode] = $this->snapshotProjectionState(
                    $placement,
                );
                $hash = hash('sha256', implode('|', [
                    $hash,
                    (int) $placement->id,
                    (int) $placement->email_message_id,
                    (int) $placement->uid_namespace_id,
                    (int) $placement->imap_uid_validity,
                    (int) $placement->imap_uid,
                    $localState,
                    $syncStatus,
                    $syncErrorCode,
                    (int) $placement->sync_version,
                ]));
                $cursor = (int) $placement->id;
                $count++;
            }

            $hasMore = EmailMailboxPlacement::query()
                ->where('account_id', $locked->account_id)
                ->where('email_folder_id', $locked->email_folder_id)
                ->where('id', '>', $cursor)
                ->where('id', '<=', $locked->placement_snapshot_through_id)
                ->exists();
            $locked->forceFill([
                'placement_snapshot_status' => $hasMore
                    ? EmailProviderReconciliationFolder::SNAPSHOT_RUNNING
                    : EmailProviderReconciliationFolder::SNAPSHOT_COMPLETED,
                'placement_snapshot_cursor_id' => $cursor,
                'placement_snapshot_count' => $count,
                'placement_snapshot_hash' => $hash,
                'placement_snapshot_batch_count' => (int) $locked->placement_snapshot_batch_count + 1,
                'placement_snapshot_completed_at' => $hasMore ? null : now(),
                'last_progress_at' => now(),
            ])->save();

            return $this->result($locked->refresh());
        }, 3);
    }

    private function runAllowsPurpose(
        ?EmailProviderReconciliationRun $run,
        string $purpose,
    ): bool {
        if (! $run || $run->terminal()
            || $run->status === EmailProviderReconciliationRun::STATUS_CANCELLING
            || (int) $run->active_slot !== 1
            || $run->cancellation_requested_at !== null) {
            return false;
        }

        if (in_array($purpose, [
            EmailProviderReconciliationFolder::SNAPSHOT_BASELINE,
            EmailProviderReconciliationFolder::SNAPSHOT_SCAN_END,
        ], true)) {
            return $run->status === EmailProviderReconciliationRun::STATUS_RUNNING
                && $run->phase === EmailProviderReconciliationRun::PHASE_SCAN;
        }

        return in_array($run->status, [
            EmailProviderReconciliationRun::STATUS_RUNNING,
            EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
        ], true) && in_array($run->phase, [
            EmailProviderReconciliationRun::PHASE_IMPORTS,
            EmailProviderReconciliationRun::PHASE_FINALIZE,
            EmailProviderReconciliationRun::PHASE_DISCOVER_END,
        ], true);
    }

    private function folderAllowsPurpose(
        EmailProviderReconciliationFolder $folder,
        string $purpose,
    ): bool {
        return match ($purpose) {
            EmailProviderReconciliationFolder::SNAPSHOT_BASELINE => $folder->status === EmailProviderReconciliationFolder::STATUS_PENDING
                && $folder->start_uid_validity !== null,
            EmailProviderReconciliationFolder::SNAPSHOT_SCAN_END => $folder->status === EmailProviderReconciliationFolder::STATUS_SCANNING
                && $folder->reason_code === 'placement_scan_snapshot_pending',
            EmailProviderReconciliationFolder::SNAPSHOT_REMOTE_END => $folder->status === EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS
                && $folder->end_uid_validity !== null,
            EmailProviderReconciliationFolder::SNAPSHOT_REMOTE_PROJECTION => $folder->status === EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS
                && $folder->reason_code === 'stable_absence_freeze',
            EmailProviderReconciliationFolder::SNAPSHOT_LOCAL_FREEZE => $folder->status === EmailProviderReconciliationFolder::STATUS_PENDING
                && $folder->discovery_state
                    === EmailProviderReconciliationFolder::DISCOVERY_LOCAL_ONLY,
            EmailProviderReconciliationFolder::SNAPSHOT_LOCAL_PROJECTION => $folder->status === EmailProviderReconciliationFolder::STATUS_PENDING
                && $folder->discovery_state
                    === EmailProviderReconciliationFolder::DISCOVERY_LOCAL_ONLY
                && $folder->reason_code === 'stable_folder_absence_freeze',
            default => false,
        };
    }

    /** @return array{purpose:string, complete:bool, through_id:int, cursor_id:int, count:int, hash:string, batch_count:int} */
    private function result(EmailProviderReconciliationFolder $folderRun): array
    {
        return [
            'purpose' => (string) $folderRun->placement_snapshot_purpose,
            'complete' => $folderRun->placement_snapshot_status
                === EmailProviderReconciliationFolder::SNAPSHOT_COMPLETED,
            'through_id' => (int) $folderRun->placement_snapshot_through_id,
            'cursor_id' => (int) $folderRun->placement_snapshot_cursor_id,
            'count' => (int) $folderRun->placement_snapshot_count,
            'hash' => (string) $folderRun->placement_snapshot_hash,
            'batch_count' => (int) $folderRun->placement_snapshot_batch_count,
        ];
    }

    /**
     * A v1 reconciliation-owned Store/baseline marker is born hidden and may
     * become active only through the exact importer/baseline CAS. Hash that
     * one authorized transition as its eventual active state so an interrupted
     * prior run can be repaired without looking like concurrent local drift.
     */
    /** @return array{string,string,string} */
    private function snapshotProjectionState(EmailMailboxPlacement $placement): array
    {
        if ((int) $placement->sync_version === 1
            && $placement->local_state === EmailMailboxPlacement::LOCAL_HIDDEN
            && $placement->sync_status === EmailMailboxPlacement::SYNC_PENDING
            && in_array($placement->sync_error_code, [
                EmailProviderReconciliationStore::STORE_PENDING_CODE,
                EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
            ], true)) {
            return [
                EmailMailboxPlacement::LOCAL_ACTIVE,
                EmailMailboxPlacement::SYNC_SYNCED,
                '',
            ];
        }

        return [
            (string) $placement->local_state,
            (string) $placement->sync_status,
            (string) ($placement->sync_error_code ?? ''),
        ];
    }
}
