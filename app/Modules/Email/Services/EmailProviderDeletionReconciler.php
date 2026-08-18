<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderInventoryFolder;
use App\Modules\Email\Models\EmailProviderInventoryRun;
use App\Modules\Email\Models\EmailProviderPlacementFinding;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class EmailProviderDeletionReconciler
{
    public const DEFAULT_GRACE_DAYS = 7;

    public function __construct(
        private readonly EmailProviderInventoryScanner $scanner,
        private readonly EmailProviderMessageIdentity $identity,
        private readonly EmailConversationProjector $conversations,
    ) {}

    public function reconcileAccount(
        EmailAccount $account,
        int $maxFolders = EmailProviderInventoryScanner::DEFAULT_MAX_FOLDERS,
        int $maxMessagesPerFolder = EmailProviderInventoryScanner::DEFAULT_MAX_MESSAGES_PER_FOLDER,
        int $batchSize = EmailProviderInventoryScanner::DEFAULT_BATCH_SIZE,
        int $graceDays = self::DEFAULT_GRACE_DAYS,
        int $providerBindingVersion = 0,
    ): EmailProviderInventoryRun {
        $maxFolders = max(1, $maxFolders);
        $maxMessagesPerFolder = max(1, $maxMessagesPerFolder);
        $baseline = $this->placementBaseline($account);

        if ($providerBindingVersion < 1) {
            throw new EmailProviderSecurityException('provider_binding_snapshot_missing');
        }

        if (app(EmailAccountProviderRuntimeResolver::class)->bindingVersion($account)
            !== $providerBindingVersion) {
            return $this->recordBindingStale($account, $providerBindingVersion);
        }

        $run = EmailProviderInventoryRun::query()->create($this->withProviderBindingVersion([
            'account_id' => $account->id,
            'provider' => 'imap',
            'status' => EmailProviderInventoryRun::STATUS_RUNNING,
            'max_folders' => $maxFolders,
            'max_messages_per_folder' => $maxMessagesPerFolder,
            'started_at' => now(),
        ], $providerBindingVersion));

        try {
            $snapshot = $this->scanner->scan(
                $account,
                $maxFolders,
                $maxMessagesPerFolder,
                $batchSize,
                $providerBindingVersion,
            );

            return $this->applySnapshot($run, $account, $snapshot, $baseline, $graceDays);
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => EmailProviderInventoryRun::STATUS_FAILED,
                'failure_code' => 'inventory_reconciliation_failed',
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    public function recordBindingStale(
        EmailAccount $account,
        int $providerBindingVersion,
    ): EmailProviderInventoryRun {
        return $this->recordBindingBlocked($account, $providerBindingVersion, 'provider_binding_stale');
    }

    public function recordBindingBlocked(
        EmailAccount $account,
        int $providerBindingVersion,
        string $failureCode,
    ): EmailProviderInventoryRun {
        return EmailProviderInventoryRun::query()->create($this->withProviderBindingVersion([
            'account_id' => $account->id,
            'provider' => 'imap',
            'status' => EmailProviderInventoryRun::STATUS_BLOCKED,
            'max_folders' => EmailProviderInventoryScanner::DEFAULT_MAX_FOLDERS,
            'max_messages_per_folder' => EmailProviderInventoryScanner::DEFAULT_MAX_MESSAGES_PER_FOLDER,
            'failure_code' => Str::limit($failureCode, 100, ''),
            'started_at' => now(),
            'finished_at' => now(),
        ], $providerBindingVersion));
    }

    /** @param array<string, mixed> $attributes
     *  @return array<string, mixed>
     */
    private function withProviderBindingVersion(array $attributes, int $version): array
    {
        if (Schema::hasColumn('email_provider_inventory_runs', 'provider_binding_version')) {
            if ($version < 1) {
                throw new EmailProviderSecurityException('provider_binding_snapshot_missing');
            }
            $attributes['provider_binding_version'] = $version;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  Collection<int, array<string, mixed>>  $baseline
     */
    private function applySnapshot(
        EmailProviderInventoryRun $run,
        EmailAccount $account,
        array $snapshot,
        Collection $baseline,
        int $graceDays,
    ): EmailProviderInventoryRun {
        $folderAudits = $this->persistFolderAudits($run, $account, $snapshot);
        $folderSnapshots = collect($snapshot['folders'] ?? []);
        $reportedFolderCount = max(0, (int) ($snapshot['reported_folder_count'] ?? 0));
        $run->forceFill([
            'folder_count' => max($reportedFolderCount, $folderSnapshots->count()),
            'complete_folder_count' => $folderSnapshots
                ->where('status', EmailProviderInventoryFolder::STATUS_COMPLETE)
                ->count(),
            'scanned_message_count' => $folderSnapshots->sum(
                fn (array $folder): int => (int) ($folder['scanned_message_count'] ?? 0),
            ),
            'inventory_scope_fingerprint' => $this->safeFingerprint($snapshot['scope_fingerprint'] ?? null),
        ])->save();

        if ((int) ($snapshot['account_id'] ?? 0) !== (int) $account->id) {
            return $this->blockRun($run, 'inventory_account_mismatch');
        }

        if (($snapshot['status'] ?? null) !== 'complete'
            || $folderSnapshots->isEmpty()
            || $folderSnapshots->contains(
                fn (array $folder): bool => ($folder['status'] ?? null) !== EmailProviderInventoryFolder::STATUS_COMPLETE,
            )) {
            $status = ($snapshot['status'] ?? null) === 'failed'
                ? EmailProviderInventoryRun::STATUS_FAILED
                : EmailProviderInventoryRun::STATUS_BLOCKED;

            $run->forceFill([
                'status' => $status,
                'failure_code' => Str::limit(
                    (string) ($snapshot['failure_code'] ?? 'folder_inventory_incomplete'),
                    100,
                    '',
                ),
                'finished_at' => now(),
            ])->save();

            return $run->refresh();
        }

        if ($reportedFolderCount !== $folderSnapshots->count()
            || $reportedFolderCount > (int) $run->max_folders) {
            return $this->blockRun($run, 'inventory_folder_count_mismatch');
        }

        $validated = $this->validatedInventory($account, $folderSnapshots, $folderAudits, $baseline);

        if (! $validated['valid']) {
            return $this->blockRun($run, $validated['reason']);
        }

        $inventories = $validated['inventories'];
        $identityTargets = $validated['identity_targets'];
        $folderIdsByPath = $validated['folder_ids_by_path'];
        $conversationIds = collect($this->restoreReturnedTombstones(
            $run,
            $account,
            $inventories,
            $folderAudits,
        ));
        $counts = [
            EmailProviderPlacementFinding::TYPE_CONFIRMED_MISSING => 0,
            EmailProviderPlacementFinding::TYPE_CONFIRMED_MOVE => 0,
            EmailProviderPlacementFinding::TYPE_AMBIGUOUS => 0,
        ];

        foreach ($baseline as $placementId => $baselinePlacement) {
            $folderPath = (string) $baselinePlacement['folder_path'];
            $uid = (int) $baselinePlacement['imap_uid'];
            $uidValidity = (int) $baselinePlacement['imap_uid_validity'];

            if (isset($inventories[$folderPath][$uidValidity][$uid])) {
                continue;
            }

            $result = DB::transaction(function () use (
                $run,
                $account,
                $placementId,
                $baselinePlacement,
                $folderAudits,
                $identityTargets,
                $folderIdsByPath,
                $graceDays,
            ): array {
                $placement = EmailMailboxPlacement::query()
                    ->with('message')
                    ->where('account_id', $account->id)
                    ->whereKey($placementId)
                    ->lockForUpdate()
                    ->first();

                if (! $placement) {
                    return ['type' => null, 'conversation_id' => null];
                }

                $folderAudit = $folderAudits->get($placement->folder_path);

                if (! $folderAudit) {
                    return ['type' => null, 'conversation_id' => null];
                }

                $reason = $this->baselineMismatchReason($placement, $baselinePlacement);
                $sourceFingerprint = $placement->message
                    ? $this->identity->forMessage($placement->message)
                    : null;
                $target = null;
                $findingType = EmailProviderPlacementFinding::TYPE_AMBIGUOUS;

                if ($reason === null && $this->hasUnresolvedRemoteOperation($placement)) {
                    $reason = 'remote_operation_unresolved';
                }

                if ($reason === null && $sourceFingerprint === null) {
                    $reason = 'source_identity_insufficient';
                }

                if ($reason === null) {
                    /** @var Collection<int, array<string, mixed>> $targets */
                    $targets = collect($identityTargets[$sourceFingerprint] ?? [])
                        ->reject(fn (array $candidate): bool => $candidate['folder_path'] === $placement->folder_path
                            && (int) $candidate['imap_uid'] === (int) $placement->imap_uid)
                        ->values();

                    if ($targets->count() > 1) {
                        $reason = 'move_identity_has_multiple_targets';
                    } elseif ($targets->count() === 1) {
                        $candidate = $targets->first();
                        $target = $this->projectedTargetPlacement(
                            $account,
                            $candidate,
                            $folderIdsByPath,
                            $sourceFingerprint,
                        );

                        if (! $target) {
                            $reason = 'move_target_not_safely_projected';
                        } else {
                            $findingType = EmailProviderPlacementFinding::TYPE_CONFIRMED_MOVE;
                            $reason = 'provider_move_confirmed';
                        }
                    } else {
                        $findingType = EmailProviderPlacementFinding::TYPE_CONFIRMED_MISSING;
                        $reason = 'provider_absence_confirmed';
                    }
                }

                $terminal = in_array($findingType, [
                    EmailProviderPlacementFinding::TYPE_CONFIRMED_MISSING,
                    EmailProviderPlacementFinding::TYPE_CONFIRMED_MOVE,
                ], true);
                $observedAt = now();
                $finding = EmailProviderPlacementFinding::query()->create([
                    'email_provider_inventory_run_id' => $run->id,
                    'email_provider_inventory_folder_id' => $folderAudit->id,
                    'account_id' => $account->id,
                    'source_placement_id' => $placement->id,
                    'email_message_id' => $placement->email_message_id,
                    'email_conversation_id' => $placement->email_conversation_id,
                    'source_folder_id' => $placement->email_folder_id,
                    'source_folder_path' => $placement->folder_path,
                    'source_uid_validity' => $placement->imap_uid_validity,
                    'source_uid' => $placement->imap_uid,
                    'finding_type' => $findingType,
                    'reason_code' => $reason,
                    'identity_fingerprint' => $sourceFingerprint,
                    'target_placement_id' => $target?->id,
                    'target_folder_id' => $target?->email_folder_id,
                    'target_folder_path' => $target?->folder_path,
                    'target_uid_validity' => $target?->imap_uid_validity,
                    'target_uid' => $target?->imap_uid,
                    'cleanup_due_at' => $terminal
                        ? $observedAt->copy()->addDays(max(1, $graceDays))
                        : null,
                    'observed_at' => $observedAt,
                    'created_at' => $observedAt,
                ]);

                if (! $terminal) {
                    return [
                        'type' => $finding->finding_type,
                        'conversation_id' => null,
                    ];
                }

                $conversationId = $placement->email_conversation_id;
                $messageId = $placement->email_message_id;
                $placement->forceFill([
                    'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
                    'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
                    'sync_version' => max(1, (int) $placement->sync_version) + 1,
                    'last_reconciled_at' => $observedAt,
                    'provider_missing_at' => $observedAt,
                    'sync_error_code' => null,
                    'sync_error_message' => null,
                ])->save();

                // Keep the placement itself as bounded, provider-confirmed tombstone
                // evidence through the grace period. Only active placements make the
                // legacy message a live Mail item; Ticket evidence remains separate.
                $hasSurvivingPlacement = EmailMailboxPlacement::query()
                    ->where('email_message_id', $messageId)
                    ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                    ->exists();

                if (! $hasSurvivingPlacement && $placement->message && ! $placement->message->trashed()) {
                    $placement->message->delete();
                }

                return [
                    'type' => $finding->finding_type,
                    'conversation_id' => $conversationId ? (int) $conversationId : null,
                ];
            }, 3);

            if ($result['type']) {
                $counts[$result['type']]++;
            }

            if ($result['conversation_id']) {
                $conversationIds->push($result['conversation_id']);
            }
        }

        $conversationIds->filter()->unique()->each(function (int $conversationId): void {
            $this->conversations->refreshConversation(
                EmailConversation::query()->find($conversationId),
            );
        });

        $run->forceFill([
            'confirmed_missing_count' => $counts[EmailProviderPlacementFinding::TYPE_CONFIRMED_MISSING],
            'confirmed_move_count' => $counts[EmailProviderPlacementFinding::TYPE_CONFIRMED_MOVE],
            'ambiguous_count' => $counts[EmailProviderPlacementFinding::TYPE_AMBIGUOUS],
            'status' => $counts[EmailProviderPlacementFinding::TYPE_AMBIGUOUS] > 0
                ? EmailProviderInventoryRun::STATUS_COMPLETED_WITH_AMBIGUITY
                : EmailProviderInventoryRun::STATUS_COMPLETED,
            'failure_code' => $counts[EmailProviderPlacementFinding::TYPE_AMBIGUOUS] > 0
                ? 'placement_ambiguity_retained'
                : null,
            'finished_at' => now(),
        ])->save();

        return $run->refresh();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function placementBaseline(EmailAccount $account): Collection
    {
        return EmailMailboxPlacement::query()
            ->where('account_id', $account->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->get([
                'id',
                'email_message_id',
                'email_conversation_id',
                'email_folder_id',
                'folder_path',
                'imap_uid_validity',
                'imap_uid',
                'local_state',
                'sync_version',
            ])
            ->mapWithKeys(fn (EmailMailboxPlacement $placement): array => [
                $placement->id => $placement->only([
                    'email_message_id',
                    'email_conversation_id',
                    'email_folder_id',
                    'folder_path',
                    'imap_uid_validity',
                    'imap_uid',
                    'local_state',
                    'sync_version',
                ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return Collection<string, EmailProviderInventoryFolder>
     */
    private function persistFolderAudits(
        EmailProviderInventoryRun $run,
        EmailAccount $account,
        array $snapshot,
    ): Collection {
        return collect($snapshot['folders'] ?? [])
            ->mapWithKeys(function (array $folder) use ($run, $account): array {
                $path = Str::limit((string) ($folder['folder_path'] ?? '(unknown)'), 512, '');
                $audit = EmailProviderInventoryFolder::query()->create([
                    'email_provider_inventory_run_id' => $run->id,
                    'account_id' => $account->id,
                    'email_folder_id' => isset($folder['email_folder_id'])
                        ? (int) $folder['email_folder_id']
                        : null,
                    'folder_path' => $path,
                    'status' => Str::limit((string) ($folder['status'] ?? 'failed'), 40, ''),
                    'reason_code' => filled($folder['reason_code'] ?? null)
                        ? Str::limit((string) $folder['reason_code'], 100, '')
                        : null,
                    'expected_uid_validity' => $folder['expected_uid_validity'] ?? null,
                    'observed_uid_validity' => $folder['observed_uid_validity'] ?? null,
                    'start_uid_next' => $folder['start_uid_next'] ?? null,
                    'end_uid_next' => $folder['end_uid_next'] ?? null,
                    'start_exists_count' => $folder['start_exists_count'] ?? null,
                    'end_exists_count' => $folder['end_exists_count'] ?? null,
                    'scanned_message_count' => max(0, (int) ($folder['scanned_message_count'] ?? 0)),
                    'inventory_fingerprint' => $this->safeFingerprint($folder['inventory_fingerprint'] ?? null),
                    'started_at' => $folder['started_at'] ?? $run->started_at,
                    'finished_at' => $folder['finished_at'] ?? now(),
                    'created_at' => now(),
                ]);

                return [$path => $audit];
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $folders
     * @param  Collection<string, EmailProviderInventoryFolder>  $folderAudits
     * @param  Collection<int, array<string, mixed>>  $baseline
     * @return array<string, mixed>
     */
    private function validatedInventory(
        EmailAccount $account,
        Collection $folders,
        Collection $folderAudits,
        Collection $baseline,
    ): array {
        $inventories = [];
        $identityTargets = [];
        $folderIdsByPath = [];

        foreach ($folders as $folderSnapshot) {
            $path = (string) ($folderSnapshot['folder_path'] ?? '');
            $folder = EmailFolder::query()
                ->where('account_id', $account->id)
                ->where('path', $path)
                ->first();

            if (! $folder
                || ! $folderAudits->has($path)
                || (int) ($folderSnapshot['email_folder_id'] ?? 0) !== (int) $folder->id
                || (int) ($folderSnapshot['expected_uid_validity'] ?? 0) !== (int) $folder->uid_validity
                || (int) ($folderSnapshot['observed_uid_validity'] ?? 0) !== (int) $folder->uid_validity) {
                return ['valid' => false, 'reason' => 'folder_projection_changed_or_mismatched'];
            }

            $uidValidity = (int) $folder->uid_validity;
            $folderIdsByPath[$path] = (int) $folder->id;
            $inventories[$path][$uidValidity] = [];

            foreach ((array) ($folderSnapshot['messages'] ?? []) as $message) {
                $uid = (int) ($message['imap_uid'] ?? 0);

                if ($uid <= 0
                    || (int) ($message['uid_validity'] ?? 0) !== $uidValidity
                    || isset($inventories[$path][$uidValidity][$uid])) {
                    return ['valid' => false, 'reason' => 'invalid_uid_inventory_evidence'];
                }

                $fingerprint = $this->safeFingerprint($message['identity_fingerprint'] ?? null);
                $evidence = [
                    'folder_path' => $path,
                    'imap_uid' => $uid,
                    'uid_validity' => $uidValidity,
                    'identity_fingerprint' => $fingerprint,
                    'provider_seen' => (bool) ($message['provider_seen'] ?? false),
                    'provider_flagged' => (bool) ($message['provider_flagged'] ?? false),
                    'provider_deleted' => (bool) ($message['provider_deleted'] ?? false),
                    'provider_draft' => (bool) ($message['provider_draft'] ?? false),
                ];
                $inventories[$path][$uidValidity][$uid] = $evidence;

                if ($fingerprint) {
                    $identityTargets[$fingerprint][] = $evidence;
                }
            }

            if (count($inventories[$path][$uidValidity]) !== (int) ($folderSnapshot['scanned_message_count'] ?? -1)
                || count($inventories[$path][$uidValidity]) !== (int) ($folderSnapshot['end_exists_count'] ?? -2)) {
                return ['valid' => false, 'reason' => 'inventory_count_not_complete'];
            }
        }

        foreach ($baseline as $placement) {
            if (! isset($inventories[(string) $placement['folder_path']][(int) $placement['imap_uid_validity']])) {
                return ['valid' => false, 'reason' => 'baseline_folder_not_fully_inventoried'];
            }
        }

        return [
            'valid' => true,
            'reason' => null,
            'inventories' => $inventories,
            'identity_targets' => $identityTargets,
            'folder_ids_by_path' => $folderIdsByPath,
        ];
    }

    /**
     * Provider evidence can return during the grace period. Restore the exact
     * UID/UIDVALIDITY placement and cancel its old cleanup path rather than
     * allowing a previously correct absence finding to become a later purge.
     *
     * @param  array<string, array<int, array<int, array<string, mixed>>>>  $inventories
     * @param  Collection<string, EmailProviderInventoryFolder>  $folderAudits
     * @return array<int, int>
     */
    private function restoreReturnedTombstones(
        EmailProviderInventoryRun $run,
        EmailAccount $account,
        array $inventories,
        Collection $folderAudits,
    ): array {
        $conversationIds = [];
        $tombstones = EmailMailboxPlacement::query()
            ->where('account_id', $account->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_HIDDEN)
            ->whereNotNull('provider_missing_at')
            ->get();

        foreach ($tombstones as $tombstone) {
            $evidence = $inventories[$tombstone->folder_path][(int) $tombstone->imap_uid_validity][(int) $tombstone->imap_uid]
                ?? null;

            if (! $evidence) {
                continue;
            }

            $conversationId = DB::transaction(function () use (
                $run,
                $account,
                $tombstone,
                $evidence,
                $folderAudits,
            ): ?int {
                $placement = EmailMailboxPlacement::query()
                    ->where('account_id', $account->id)
                    ->whereKey($tombstone->id)
                    ->lockForUpdate()
                    ->first();

                if (! $placement
                    || $placement->local_state !== EmailMailboxPlacement::LOCAL_HIDDEN
                    || $placement->provider_missing_at === null
                    || (int) $placement->imap_uid !== (int) $evidence['imap_uid']
                    || (int) $placement->imap_uid_validity !== (int) $evidence['uid_validity']) {
                    return null;
                }

                $terminalFinding = EmailProviderPlacementFinding::query()
                    ->where('source_placement_id', $placement->id)
                    ->whereIn('finding_type', [
                        EmailProviderPlacementFinding::TYPE_CONFIRMED_MISSING,
                        EmailProviderPlacementFinding::TYPE_CONFIRMED_MOVE,
                    ])
                    ->latest('id')
                    ->first();
                $folderAudit = $folderAudits->get($placement->folder_path);

                if (! $terminalFinding || ! $folderAudit) {
                    return null;
                }

                $observedAt = now();
                EmailProviderPlacementFinding::query()->create([
                    'email_provider_inventory_run_id' => $run->id,
                    'email_provider_inventory_folder_id' => $folderAudit->id,
                    'account_id' => $account->id,
                    'source_placement_id' => $placement->id,
                    'email_message_id' => $placement->email_message_id,
                    'email_conversation_id' => $placement->email_conversation_id,
                    'source_folder_id' => $placement->email_folder_id,
                    'source_folder_path' => $placement->folder_path,
                    'source_uid_validity' => $placement->imap_uid_validity,
                    'source_uid' => $placement->imap_uid,
                    'finding_type' => EmailProviderPlacementFinding::TYPE_REAPPEARED,
                    'reason_code' => 'provider_placement_reappeared',
                    'identity_fingerprint' => $evidence['identity_fingerprint'],
                    'cleanup_due_at' => null,
                    'observed_at' => $observedAt,
                    'created_at' => $observedAt,
                ]);

                $placement->forceFill([
                    'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
                    'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
                    'sync_version' => max(1, (int) $placement->sync_version) + 1,
                    'provider_seen' => (bool) $evidence['provider_seen'],
                    'provider_flagged' => (bool) $evidence['provider_flagged'],
                    'provider_deleted' => (bool) $evidence['provider_deleted'],
                    'provider_draft' => (bool) $evidence['provider_draft'],
                    'last_reconciled_at' => $observedAt,
                    'provider_missing_at' => null,
                    'sync_error_code' => null,
                    'sync_error_message' => null,
                ])->save();

                $message = EmailMessage::query()
                    ->withTrashed()
                    ->whereKey($placement->email_message_id)
                    ->lockForUpdate()
                    ->first();

                if ($message?->trashed()
                    && $message->deleted_at?->greaterThanOrEqualTo($terminalFinding->observed_at)) {
                    $message->restore();
                }

                return $placement->email_conversation_id
                    ? (int) $placement->email_conversation_id
                    : null;
            }, 3);

            if ($conversationId) {
                $conversationIds[] = $conversationId;
            }
        }

        return array_values(array_unique($conversationIds));
    }

    /**
     * @param  array<string, mixed>  $baseline
     */
    private function baselineMismatchReason(
        EmailMailboxPlacement $placement,
        array $baseline,
    ): ?string {
        foreach ([
            'email_message_id',
            'email_conversation_id',
            'email_folder_id',
            'folder_path',
            'imap_uid_validity',
            'imap_uid',
            'local_state',
            'sync_version',
        ] as $field) {
            if ((string) $placement->getAttribute($field) !== (string) ($baseline[$field] ?? '')) {
                return 'placement_changed_during_inventory';
            }
        }

        return null;
    }

    private function hasUnresolvedRemoteOperation(EmailMailboxPlacement $placement): bool
    {
        return EmailRemoteOperation::query()
            ->where('email_mailbox_placement_id', $placement->id)
            ->whereNotIn('status', [
                EmailRemoteOperation::STATUS_SUCCEEDED,
                EmailRemoteOperation::STATUS_CANCELLED,
                EmailRemoteOperation::STATUS_SUPERSEDED,
            ])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, int>  $folderIdsByPath
     */
    private function projectedTargetPlacement(
        EmailAccount $account,
        array $candidate,
        array $folderIdsByPath,
        string $sourceFingerprint,
    ): ?EmailMailboxPlacement {
        $folderPath = (string) $candidate['folder_path'];
        $folderId = $folderIdsByPath[$folderPath] ?? null;

        if (! $folderId) {
            return null;
        }

        $target = EmailMailboxPlacement::query()
            ->with('message')
            ->where('account_id', $account->id)
            ->where('email_folder_id', $folderId)
            ->where('imap_uid_validity', (int) $candidate['uid_validity'])
            ->where('imap_uid', (int) $candidate['imap_uid'])
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->first();

        if (! $target?->message
            || $this->identity->forMessage($target->message) !== $sourceFingerprint) {
            return null;
        }

        return $target;
    }

    private function safeFingerprint(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }

    private function blockRun(EmailProviderInventoryRun $run, string $reason): EmailProviderInventoryRun
    {
        $run->forceFill([
            'status' => EmailProviderInventoryRun::STATUS_BLOCKED,
            'failure_code' => Str::limit($reason, 100, ''),
            'finished_at' => now(),
        ])->save();

        return $run->refresh();
    }
}
