<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\DTOs\EmailProviderReconciliationMessageMetadata;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Support\EmailProviderPath;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class EmailProviderRemoteOperationObserver
{
    public function oldestUnresolvedForPlacement(int $placementId): ?EmailRemoteOperation
    {
        $oldestId = collect($this->unresolvedPlacementBranches($placementId))
            ->map(fn (Builder $branch): int => (int) ($branch
                ->orderBy('id')
                ->limit(1)
                ->value('id') ?? 0))
            ->filter()
            ->min();

        if (! $oldestId) {
            return null;
        }

        // Revalidate the chosen row because a worker may have settled it
        // between the bounded branch probes and this primary-key load.
        return $this->applyUnresolvedPredicate(
            EmailRemoteOperation::query()
                ->whereKey($oldestId)
                ->where('email_mailbox_placement_id', $placementId),
        )->first();
    }

    public function hasUnresolvedForPlacement(int $placementId): bool
    {
        return $this->anyUnresolvedBranch($placementId);
    }

    public function hasCompetingUnresolvedForPlacement(
        int $placementId,
        int $exceptOperationId,
    ): bool {
        return $this->anyUnresolvedBranch(
            $placementId,
            fn (Builder $query): Builder => $query->whereKeyNot($exceptOperationId),
        );
    }

    public function hasPriorUnresolvedForPlacement(
        int $placementId,
        int $beforeOperationId,
    ): bool {
        return $this->anyUnresolvedBranch(
            $placementId,
            fn (Builder $query): Builder => $query->where('id', '<', $beforeOperationId),
        );
    }

    /** @return array<int, Builder> */
    private function unresolvedPlacementBranches(int $placementId): array
    {
        return $this->unresolvedBranches('email_mailbox_placement_id', $placementId);
    }

    /** @return array<int, Builder> */
    private function unresolvedBranches(string $scopeColumn, int $scopeId): array
    {
        $base = fn (): Builder => EmailRemoteOperation::query()->where($scopeColumn, $scopeId);

        return [
            $base()->where('status', EmailRemoteOperation::STATUS_PENDING),
            $base()->where('status', EmailRemoteOperation::STATUS_RUNNING),
            $base()->where('status', EmailRemoteOperation::STATUS_FAILED)
                ->whereNull('reconciled_at')
                ->whereNull('failure_classification'),
            $base()->where('status', EmailRemoteOperation::STATUS_FAILED)
                ->whereNull('reconciled_at')
                ->where('failure_classification', EmailRemoteOperation::FAILURE_TRANSIENT),
            $base()->where('status', EmailRemoteOperation::STATUS_FAILED)
                ->whereNull('reconciled_at')
                ->where('failure_classification', EmailRemoteOperation::FAILURE_AMBIGUOUS),
        ];
    }

    private function anyUnresolvedBranch(
        int $placementId,
        ?callable $scope = null,
    ): bool {
        foreach ($this->unresolvedPlacementBranches($placementId) as $branch) {
            if (($scope ? $scope($branch) : $branch)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function applyUnresolvedPredicate(Builder $query): Builder
    {
        return $query->where(function ($unresolved): void {
            $unresolved->whereIn('status', [
                EmailRemoteOperation::STATUS_PENDING,
                EmailRemoteOperation::STATUS_RUNNING,
            ])->orWhere(function ($failed): void {
                $failed->where('status', EmailRemoteOperation::STATUS_FAILED)
                    ->whereNull('reconciled_at')
                    ->where(function ($classification): void {
                        $classification->whereNull('failure_classification')
                            ->orWhereIn('failure_classification', [
                                EmailRemoteOperation::FAILURE_TRANSIENT,
                                EmailRemoteOperation::FAILURE_AMBIGUOUS,
                            ]);
                    });
            });
        });
    }

    public function hasUnresolvedForFolder(int $folderId): bool
    {
        foreach ($this->unresolvedBranches('email_folder_id', $folderId) as $branch) {
            if ($branch->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Complete or stale only exact flag operations after final stable evidence.
     * No provider mutation or retry is invoked here.
     */
    public function reconcileStableFlagObservation(
        EmailProviderReconciliationItem $observation,
    ): int {
        $placement = EmailMailboxPlacement::query()->find($observation->source_placement_id);
        if (! $placement) {
            return 0;
        }
        $run = EmailProviderReconciliationRun::query()->find(
            $observation->email_provider_reconciliation_run_id,
        );
        if (! $run) {
            return 0;
        }

        $metadata = new EmailProviderReconciliationMessageMetadata(
            uid: (int) $observation->imap_uid,
            modseq: $observation->provider_modseq,
            seen: (bool) $observation->provider_seen,
            answered: (bool) $observation->provider_answered,
            flagged: (bool) $observation->provider_flagged,
            deleted: (bool) $observation->provider_deleted,
            draft: (bool) $observation->provider_draft,
            customFlags: $observation->custom_flags_json ?? [],
        );
        $operation = $this->oldestUnresolvedForPlacement((int) $placement->id);
        if (! $operation
            || ! $this->exactSourceScope($operation, $placement, $observation, $run)) {
            return 0;
        }
        $expected = match ($operation->operation_type) {
            'mark_seen' => ['seen', true],
            'mark_unseen' => ['seen', false],
            'flag' => ['flagged', true],
            'unflag' => ['flagged', false],
            default => null,
        };

        if ($expected === null) {
            return 0;
        }

        [$property, $value] = $expected;
        $applied = (bool) $metadata->{$property} === $value;
        $operation->forceFill($applied ? [
            'status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            'acknowledged_at' => $operation->acknowledged_at ?? now(),
            'reconciled_at' => now(),
            'next_attempt_at' => null,
            'error_code' => null,
            'error_message' => null,
            'failure_classification' => null,
            'status_reason_code' => 'PROVIDER_RECONCILIATION_APPLIED',
            'status_reason_message' => 'Stable provider evidence confirms the requested flag state.',
        ] : [
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'failed_at' => $operation->failed_at ?? now(),
            'reconciled_at' => now(),
            'next_attempt_at' => null,
            'error_code' => 'PROVIDER_RECONCILIATION_STALE',
            'error_message' => 'Stable provider state contradicts the requested flag state.',
            'failure_classification' => EmailRemoteOperation::FAILURE_STALE,
            'status_reason_code' => 'PROVIDER_RECONCILIATION_STALE',
            'status_reason_message' => 'The provider state won without replaying the operation.',
        ])->save();

        return 1;
    }

    /**
     * Stable presence contradicts a provider move/delete-style operation whose
     * intended result required this exact source UID to disappear. Mark the
     * operation stale without replaying it.
     */
    public function reconcileStableSourcePresence(
        EmailProviderReconciliationItem $observation,
    ): int {
        $placement = EmailMailboxPlacement::query()->find($observation->source_placement_id);
        if (! $placement) {
            return 0;
        }
        $run = EmailProviderReconciliationRun::query()->find(
            $observation->email_provider_reconciliation_run_id,
        );
        if (! $run) {
            return 0;
        }

        $operation = $this->oldestUnresolvedForPlacement((int) $placement->id);
        if (! $operation
            || ! $this->exactSourceScope($operation, $placement, $observation, $run)
            || ! in_array($operation->operation_type, ['archive', 'trash', 'move'], true)) {
            return 0;
        }

        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'failed_at' => $operation->failed_at ?? now(),
            'reconciled_at' => now(),
            'next_attempt_at' => null,
            'error_code' => 'PROVIDER_RECONCILIATION_SOURCE_PRESENT',
            'error_message' => 'Stable provider state still contains the exact source placement.',
            'failure_classification' => EmailRemoteOperation::FAILURE_STALE,
            'status_reason_code' => 'PROVIDER_RECONCILIATION_SOURCE_PRESENT',
            'status_reason_message' => 'The provider state won without replaying the operation.',
        ])->save();

        return 1;
    }

    /**
     * Resolve a move-style operation only when the provider acknowledged an
     * authoritative COPYUID tuple and that exact target namespace/UID is
     * observed in this same stable account cycle. Provider response JSON is
     * deliberately not evidence: legacy or tampered payloads stay ambiguous.
     * The method never searches by canonical identity and never retries a
     * provider mutation.
     */
    public function reconcileStableSourceAbsence(
        EmailProviderReconciliationItem $absence,
    ): ?int {
        if (! $absence->source_placement_id) {
            return null;
        }

        $source = EmailMailboxPlacement::query()->find($absence->source_placement_id);
        if (! $source) {
            return null;
        }
        $run = EmailProviderReconciliationRun::query()->find(
            $absence->email_provider_reconciliation_run_id,
        );
        if (! $run) {
            return null;
        }

        $resolvedTargetId = null;
        $operation = $this->oldestUnresolvedForPlacement((int) $absence->source_placement_id);
        if (! $operation
            || ! $this->exactSourceScope($operation, $source, $absence, $run)
            || ! in_array($operation->operation_type, ['archive', 'trash', 'move'], true)
            || (int) $operation->expected_provider_uid !== (int) $absence->imap_uid
            || (int) $operation->expected_uid_validity !== (int) $source->imap_uid_validity) {
            return null;
        }

        $targetUidValidity = (int) $operation->acknowledged_target_uid_validity;
        $targetUid = (int) $operation->acknowledged_target_uid;
        $targetPath = $this->providerPath($operation->getAttribute('target_folder_path'));
        if ($targetUidValidity < 1 || $targetUid < 1 || $targetPath === null) {
            return null;
        }

        $targetIds = EmailMailboxPlacement::query()
            ->from('email_mailbox_placements as placements')
            ->select([
                'placements.id',
                'placements.folder_path',
                'folders.folder_path as reconciliation_folder_path',
                'active_folders.path as active_folder_path',
            ])
            ->join('email_provider_reconciliation_folders as folders', function ($join) use ($absence): void {
                $join->on('folders.email_folder_id', '=', 'placements.email_folder_id')
                    ->on('folders.uid_namespace_id', '=', 'placements.uid_namespace_id')
                    ->where(
                        'folders.email_provider_reconciliation_run_id',
                        '=',
                        $absence->email_provider_reconciliation_run_id,
                    );
            })
            ->join('email_folders as active_folders', function ($join): void {
                $join->on('active_folders.id', '=', 'folders.email_folder_id')
                    ->on(
                        'active_folders.active_uid_namespace_id',
                        '=',
                        'folders.uid_namespace_id',
                    );
            })
            ->join('email_folder_uid_namespaces as target_namespaces', function ($join): void {
                $join->on('target_namespaces.id', '=', 'folders.uid_namespace_id')
                    ->on('target_namespaces.email_folder_id', '=', 'folders.email_folder_id')
                    ->on('target_namespaces.account_id', '=', 'folders.account_id');
            })
            ->where(function ($query): void {
                $query->where(
                    'folders.status',
                    EmailProviderReconciliationFolder::STATUS_COMPLETE,
                )->orWhere(function ($waiting): void {
                    $waiting->where(
                        'folders.status',
                        EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
                    )->whereIn(
                        'folders.reason_code',
                        EmailProviderReconciliationFolder::STABLE_EVIDENCE_REASON_CODES,
                    );
                });
            })
            ->where('folders.expected_uid_validity', $targetUidValidity)
            ->where('folders.start_uid_validity', $targetUidValidity)
            ->where('folders.end_uid_validity', $targetUidValidity)
            ->where('placements.last_provider_reconciliation_run_id', $absence->email_provider_reconciliation_run_id)
            ->where('placements.imap_uid', $targetUid)
            ->where('placements.imap_uid_validity', $targetUidValidity)
            ->where('folders.folder_path', $targetPath)
            ->where('folders.account_id', $operation->account_id)
            ->where('placements.account_id', $operation->account_id)
            ->where('placements.provider', $operation->provider)
            ->where('placements.local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->where('active_folders.account_id', $operation->account_id)
            ->where('active_folders.uid_validity', $targetUidValidity)
            ->where('target_namespaces.account_id', $operation->account_id)
            ->where('target_namespaces.uid_validity', $targetUidValidity)
            ->where('target_namespaces.status', 'active')
            ->distinct()
            ->limit(3)
            ->get()
            ->filter(fn (EmailMailboxPlacement $placement): bool =>
                // IMAP folder paths are case-sensitive outside INBOX.
                // Keep the final comparison strict even before every
                // installation has binary provider-path collations.
                $targetPath === (string) $placement->folder_path
                && $targetPath === (string) $placement->getAttribute('reconciliation_folder_path')
                && $targetPath === (string) $placement->getAttribute('active_folder_path'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        if (count($targetIds) !== 1) {
            return null;
        }

        $resolvedTargetId = $targetIds[0];
        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            'acknowledged_at' => $operation->acknowledged_at ?? now(),
            'reconciled_at' => now(),
            'next_attempt_at' => null,
            'error_code' => null,
            'error_message' => null,
            'failure_classification' => null,
            'status_reason_code' => 'PROVIDER_RECONCILIATION_APPLIED',
            'status_reason_message' => 'Stable provider evidence confirms the exact target path and UID.',
        ])->save();
        $absence->forceFill([
            'target_placement_id' => $resolvedTargetId,
            'email_remote_operation_id' => $operation->id,
        ])->save();

        return $resolvedTargetId;
    }

    private function providerPath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        try {
            return EmailProviderPath::normalize($path);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function exactSourceScope(
        EmailRemoteOperation $operation,
        EmailMailboxPlacement $placement,
        EmailProviderReconciliationItem $evidence,
        EmailProviderReconciliationRun $run,
    ): bool {
        $sourcePath = (string) $operation->source_folder_path;

        return (int) $operation->account_id === (int) $run->account_id
            && (int) $placement->account_id === (int) $run->account_id
            && (int) $operation->provider_binding_version === (int) $run->provider_binding_version
            && (string) $operation->provider === (string) $run->provider
            && (int) $operation->email_folder_id === (int) $placement->email_folder_id
            && (int) $operation->email_mailbox_placement_id === (int) $placement->id
            && (int) $operation->expected_provider_uid === (int) $evidence->imap_uid
            && (int) $operation->expected_provider_uid === (int) $placement->imap_uid
            && (int) $operation->expected_uid_validity === (int) $placement->imap_uid_validity
            && $sourcePath !== ''
            && $sourcePath === (string) $placement->folder_path;
    }
}
