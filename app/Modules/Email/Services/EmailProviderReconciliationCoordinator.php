<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Contracts\EmailProviderReconciliationReader;
use App\Modules\Email\DTOs\EmailProviderReconciliationFolderDescriptor;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Support\Facades\DB;

final class EmailProviderReconciliationCoordinator
{
    private const HARD_LOCAL_FOLDER_BATCH_SIZE = 100;

    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function __construct(
        private readonly EmailProviderReconciliationBindingPolicy $bindings,
        private readonly EmailProviderReconciliationFingerprint $fingerprints,
    ) {}

    /**
     * Freeze one sorted provider folder scope and return the remote folder work
     * IDs. This method performs discovery only; folder state and UID metadata
     * are claimed by separately bounded jobs.
     *
     * @return array<int, int>
     */
    public function discoverStart(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationReader $reader,
    ): array {
        $run = $run->fresh();
        if (! $this->activeDiscoveryRun($run)) {
            return [];
        }

        // Provider scope was already frozen. Resume only the bounded DB
        // cursor; never repeat LIST after a worker loss between local pages.
        if ($run->start_folder_scope_hash !== null) {
            if ($run->local_folder_snapshot_status
                === EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_RUNNING) {
                $this->advanceLocalFolderSnapshot($run);
            } elseif ($run->local_folder_snapshot_status
                !== EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_COMPLETED) {
                throw new EmailProviderReconciliationReadException(
                    'local_folder_snapshot_state_invalid',
                );
            }

            return $run->fresh()->local_folder_snapshot_status
                === EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_COMPLETED
                    ? $this->pendingRemoteFolderIds($run)
                    : [];
        }

        $this->bindings->currentAccount($run);
        $runtime = $reader->binding((int) $run->account_id, (int) $run->provider_binding_version);
        $run = $this->bindings->recordResolvedRuntime($run, $runtime);
        $discovered = $reader->discoverFolders(
            (int) $run->account_id,
            (int) $run->provider_binding_version,
            (int) $run->provider_time_cap_seconds,
        );
        $folders = $this->validatedScope($run, $discovered);
        $scopeHash = $this->fingerprints->folderScope($folders);

        $this->freezeRemoteScope($run, $folders, $scopeHash);
        $this->advanceLocalFolderSnapshot($run->fresh());

        return $run->fresh()->local_folder_snapshot_status
            === EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_COMPLETED
                ? $this->pendingRemoteFolderIds($run)
                : [];
    }

    /**
     * @param  array<int, EmailProviderReconciliationFolderDescriptor>  $folders
     */
    private function freezeRemoteScope(
        EmailProviderReconciliationRun $run,
        #[\SensitiveParameter] array $folders,
        string $scopeHash,
    ): void {
        DB::transaction(function () use ($run, $folders, $scopeHash): void {
            $locked = EmailProviderReconciliationRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! $this->activeDiscoveryRun($locked)) {
                return;
            }
            if ($locked->start_folder_scope_hash === null
                && ($locked->status !== EmailProviderReconciliationRun::STATUS_QUEUED
                    || $locked->phase !== EmailProviderReconciliationRun::PHASE_DISCOVER_START)) {
                return;
            }

            if ($locked->start_folder_scope_hash !== null) {
                if (! hash_equals((string) $locked->start_folder_scope_hash, $scopeHash)) {
                    throw new EmailProviderReconciliationReadException('provider_scope_changed_during_discovery');
                }

                return;
            }

            $hasCompletedBaseline = EmailProviderReconciliationRun::query()
                ->where('account_id', $locked->account_id)
                ->where('id', '!=', $locked->id)
                ->whereIn('status', [
                    EmailProviderReconciliationRun::STATUS_COMPLETED,
                    EmailProviderReconciliationRun::STATUS_COMPLETED_WITH_CONFLICTS,
                ])
                ->exists();
            $remotePaths = array_map(
                fn (EmailProviderReconciliationFolderDescriptor $descriptor): string => $descriptor->path,
                $folders,
            );
            $localFolders = $remotePaths === []
                ? collect()
                : EmailFolder::query()
                    ->where('account_id', $locked->account_id)
                    ->whereIn('path', $remotePaths)
                    ->get();

            foreach ($folders as $descriptor) {
                /** @var EmailFolder|null $local */
                $local = $localFolders->first(
                    fn (EmailFolder $candidate): bool => $candidate->path === $descriptor->path,
                );
                $discoveryState = ! $local && $hasCompletedBaseline
                    ? EmailProviderReconciliationFolder::DISCOVERY_NEW_AFTER_BASELINE
                    : EmailProviderReconciliationFolder::DISCOVERY_EXISTING;
                $importPolicy = ! $hasCompletedBaseline
                    ? EmailProviderReconciliationFolder::IMPORT_BASELINE_ONLY
                    : ($discoveryState === EmailProviderReconciliationFolder::DISCOVERY_NEW_AFTER_BASELINE
                        ? EmailProviderReconciliationFolder::IMPORT_NEW_FOLDER_NO_RULES
                        : EmailProviderReconciliationFolder::IMPORT_LIVE);
                EmailProviderReconciliationFolder::query()->create([
                    'email_provider_reconciliation_run_id' => $locked->id,
                    'account_id' => $locked->account_id,
                    'email_folder_id' => $local?->id,
                    'uid_namespace_id' => $local?->active_uid_namespace_id,
                    'folder_path' => $descriptor->path,
                    'folder_name' => $descriptor->name,
                    'delimiter' => $descriptor->delimiter,
                    'parent_path' => $descriptor->parentPath,
                    'remote_id' => $descriptor->remoteId,
                    'special_use' => $descriptor->specialUse,
                    'provider_selectable' => $descriptor->selectable,
                    'provider_sync_enabled' => $descriptor->syncEnabled,
                    'discovery_state' => $discoveryState,
                    'status' => EmailProviderReconciliationFolder::STATUS_PENDING,
                    'import_policy' => $importPolicy,
                ]);
            }

            $localThroughId = (int) EmailFolder::query()
                ->where('account_id', $locked->account_id)
                ->where('is_selectable', true)
                ->where('sync_enabled', true)
                ->max('id');
            $localSnapshotComplete = $localThroughId === 0;
            $startedAt = now();

            $locked->forceFill([
                'status' => EmailProviderReconciliationRun::STATUS_RUNNING,
                'phase' => $localSnapshotComplete
                    ? EmailProviderReconciliationRun::PHASE_SCAN
                    : EmailProviderReconciliationRun::PHASE_DISCOVER_LOCAL,
                'start_folder_scope_hash' => $scopeHash,
                'local_folder_snapshot_status' => $localSnapshotComplete
                    ? EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_COMPLETED
                    : EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_RUNNING,
                'local_folder_snapshot_through_id' => $localThroughId,
                'local_folder_snapshot_cursor_id' => 0,
                'local_folder_snapshot_count' => 0,
                'local_folder_snapshot_hash' => self::EMPTY_SHA256,
                'local_folder_snapshot_batch_count' => 0,
                'local_folder_snapshot_started_at' => $startedAt,
                'local_folder_snapshot_completed_at' => $localSnapshotComplete ? $startedAt : null,
                'folder_count' => count($folders),
                'started_at' => $locked->started_at ?? now(),
                'last_progress_at' => now(),
                'failure_code' => null,
            ])->save();
        }, 3);
    }

    /** Advance at most one hard-capped account-local folder page. */
    private function advanceLocalFolderSnapshot(EmailProviderReconciliationRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            $locked = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->findOrFail($run->id);
            if (! $this->activeDiscoveryRun($locked)
                || $locked->status !== EmailProviderReconciliationRun::STATUS_RUNNING
                || ! in_array($locked->phase, [
                    EmailProviderReconciliationRun::PHASE_DISCOVER_LOCAL,
                    EmailProviderReconciliationRun::PHASE_SCAN,
                ], true)) {
                return false;
            }
            if ($locked->local_folder_snapshot_status
                === EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_COMPLETED) {
                return true;
            }
            if ($locked->local_folder_snapshot_status
                !== EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_RUNNING) {
                throw new EmailProviderReconciliationReadException(
                    'local_folder_snapshot_state_invalid',
                );
            }

            $throughId = (int) $locked->local_folder_snapshot_through_id;
            $cursorId = (int) $locked->local_folder_snapshot_cursor_id;
            $folders = EmailFolder::query()
                ->where('account_id', $locked->account_id)
                ->where('id', '>', $cursorId)
                ->where('id', '<=', $throughId)
                ->where('is_selectable', true)
                ->where('sync_enabled', true)
                ->orderBy('id')
                ->limit($this->localFolderBatchSize())
                ->get();
            $remotePaths = array_fill_keys(
                $locked->folders()
                    ->whereIn(
                        'discovery_state',
                        EmailProviderReconciliationFolder::REMOTE_DISCOVERY_STATES,
                    )
                    ->orderBy('id')
                    ->pluck('folder_path')
                    ->map(fn (mixed $path): string => (string) $path)
                    ->all(),
                true,
            );
            $rollingHash = (string) $locked->local_folder_snapshot_hash;
            $count = (int) $locked->local_folder_snapshot_count;

            foreach ($folders as $folder) {
                $rollingHash = hash(
                    'sha256',
                    $rollingHash."\n".$this->fingerprints->make($this->localFolderFacts($folder)),
                );
                $count++;

                if (! isset($remotePaths[$folder->path])) {
                    EmailProviderReconciliationFolder::query()->firstOrCreate([
                        'email_provider_reconciliation_run_id' => $locked->id,
                        'folder_path' => $folder->path,
                    ], [
                        'account_id' => $locked->account_id,
                        'email_folder_id' => $folder->id,
                        'uid_namespace_id' => $folder->active_uid_namespace_id,
                        'folder_name' => $folder->name,
                        'delimiter' => $folder->delimiter,
                        'parent_path' => $folder->parent_path,
                        'remote_id' => $folder->remote_id,
                        'special_use' => $folder->special_use,
                        'provider_selectable' => false,
                        'provider_sync_enabled' => false,
                        'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_LOCAL_ONLY,
                        'status' => EmailProviderReconciliationFolder::STATUS_PENDING,
                        'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
                    ]);
                }
            }

            $lastId = (int) ($folders->last()?->id ?? $cursorId);
            $complete = $folders->count() < $this->localFolderBatchSize()
                || $lastId >= $throughId;
            $nextCursor = $complete ? $throughId : $lastId;
            $completedAt = $complete ? now() : null;
            $locked->forceFill([
                'phase' => $complete
                    ? EmailProviderReconciliationRun::PHASE_SCAN
                    : EmailProviderReconciliationRun::PHASE_DISCOVER_LOCAL,
                'local_folder_snapshot_status' => $complete
                    ? EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_COMPLETED
                    : EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_RUNNING,
                'local_folder_snapshot_cursor_id' => $nextCursor,
                'local_folder_snapshot_count' => $count,
                'local_folder_snapshot_hash' => $rollingHash,
                'local_folder_snapshot_batch_count' => (int) $locked->local_folder_snapshot_batch_count + 1,
                'local_folder_snapshot_completed_at' => $completedAt,
                'last_progress_at' => now(),
            ])->save();

            return $complete;
        }, 3);
    }

    /** @return array<int, int> */
    private function pendingRemoteFolderIds(EmailProviderReconciliationRun $run): array
    {
        return EmailProviderReconciliationFolder::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->whereIn(
                'discovery_state',
                EmailProviderReconciliationFolder::REMOTE_DISCOVERY_STATES,
            )
            ->whereIn('status', [
                EmailProviderReconciliationFolder::STATUS_PENDING,
                EmailProviderReconciliationFolder::STATUS_SCANNING,
            ])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return array<string, mixed> */
    private function localFolderFacts(#[\SensitiveParameter] EmailFolder $folder): array
    {
        return [
            'id' => (int) $folder->id,
            'path' => (string) $folder->path,
            'name' => (string) $folder->name,
            'delimiter' => $folder->delimiter,
            'parent_path' => $folder->parent_path,
            'remote_id' => $folder->remote_id,
            'special_use' => $folder->special_use,
            'active_uid_namespace_id' => $folder->active_uid_namespace_id,
            'is_selectable' => (bool) $folder->is_selectable,
            'sync_enabled' => (bool) $folder->sync_enabled,
        ];
    }

    private function localFolderBatchSize(): int
    {
        return min(
            self::HARD_LOCAL_FOLDER_BATCH_SIZE,
            max(1, (int) config(
                'email_provider_reconciliation.local_folder_snapshot_batch_size',
                self::HARD_LOCAL_FOLDER_BATCH_SIZE,
            )),
        );
    }

    private function activeDiscoveryRun(?EmailProviderReconciliationRun $run): bool
    {
        return $run
            && ! $run->terminal()
            && $run->status !== EmailProviderReconciliationRun::STATUS_CANCELLING
            && (int) $run->active_slot === 1
            && $run->cancellation_requested_at === null
            && in_array($run->status, [
                EmailProviderReconciliationRun::STATUS_QUEUED,
                EmailProviderReconciliationRun::STATUS_RUNNING,
            ], true)
            && in_array($run->phase, [
                EmailProviderReconciliationRun::PHASE_DISCOVER_START,
                EmailProviderReconciliationRun::PHASE_DISCOVER_LOCAL,
                EmailProviderReconciliationRun::PHASE_SCAN,
            ], true);
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
            fn (EmailProviderReconciliationFolderDescriptor $left, EmailProviderReconciliationFolderDescriptor $right): int => strcmp(
                $left->path,
                $right->path,
            ),
        );

        return $scope;
    }
}
