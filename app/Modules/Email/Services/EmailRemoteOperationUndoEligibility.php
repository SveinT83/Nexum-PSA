<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailRemoteOperation;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

class EmailRemoteOperationUndoEligibility
{
    public const WINDOW_MINUTES = 15;

    /** @var array<string, string> */
    private const INVERSE_TYPES = [
        PerformEmailRemoteOperation::MARK_SEEN => PerformEmailRemoteOperation::MARK_UNSEEN,
        PerformEmailRemoteOperation::MARK_UNSEEN => PerformEmailRemoteOperation::MARK_SEEN,
        PerformEmailRemoteOperation::FLAG => PerformEmailRemoteOperation::UNFLAG,
        PerformEmailRemoteOperation::UNFLAG => PerformEmailRemoteOperation::FLAG,
        PerformEmailRemoteOperation::ARCHIVE => PerformEmailRemoteOperation::MOVE,
        PerformEmailRemoteOperation::TRASH => PerformEmailRemoteOperation::MOVE,
        PerformEmailRemoteOperation::MOVE => PerformEmailRemoteOperation::MOVE,
    ];

    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
    ) {}

    /**
     * @return array{
     *   eligible: bool,
     *   reason_code: string,
     *   reason_message: string,
     *   classification: string|null,
     *   inverse_operation_type: string|null,
     *   inverse_operation_id: int|null,
     *   inverse_operation_status: string|null,
     *   expires_at: CarbonInterface|null
     * }
     */
    public function evaluate(
        EmailRemoteOperation $source,
        ?User $actor,
        ?EmailRemoteOperation $currentInverse = null,
    ): array {
        $source->loadMissing(['account', 'placement', 'folder', 'inverseOperation', 'attemptRecords']);
        $expiresAt = $source->result_snapshot_captured_at
            ? $source->result_snapshot_captured_at->copy()->addMinutes(self::WINDOW_MINUTES)
            : null;
        $inverse = $source->inverseOperation;

        if (! $source->account || ! $source->account->is_active) {
            return $this->blocked(
                'EMAIL_UNDO_ACCOUNT_INACTIVE',
                'The mailbox account is no longer active.',
                EmailRemoteOperation::FAILURE_AUTHORIZATION,
                $source,
                $inverse,
                $expiresAt,
            );
        }

        if (! $actor || ! $this->mailboxAccess->canAccessAccount($actor, $source->account, MailboxAccess::ORGANIZE)) {
            return $this->blocked(
                'EMAIL_UNDO_AUTH_REQUIRED',
                'Current Mailbox Organize access is required to undo this provider action.',
                EmailRemoteOperation::FAILURE_AUTHORIZATION,
                $source,
                $inverse,
                $expiresAt,
            );
        }

        if ($source->inverse_of_email_remote_operation_id !== null
            || (int) Arr::get($source->request_json ?? [], 'inverse_of_operation_id', 0) > 0
            || str_starts_with((string) $source->idempotency_key, 'mail-op:undo:')) {
            return $this->blocked(
                'EMAIL_UNDO_INVERSE_NOT_REVERSIBLE',
                'An Undo operation cannot itself be undone from this surface.',
                EmailRemoteOperation::FAILURE_PERMANENT,
                $source,
                $inverse,
                $expiresAt,
            );
        }

        if (! isset(self::INVERSE_TYPES[$source->operation_type])) {
            return $this->blocked(
                'EMAIL_UNDO_UNSUPPORTED',
                'This provider operation has no supported verified inverse.',
                EmailRemoteOperation::FAILURE_PERMANENT,
                $source,
                $inverse,
                $expiresAt,
            );
        }

        if ($source->status !== EmailRemoteOperation::STATUS_SUCCEEDED || ! $source->acknowledged_at) {
            return $this->blocked(
                'EMAIL_UNDO_SOURCE_NOT_SUCCEEDED',
                'Only a provider-acknowledged successful operation can be undone.',
                EmailRemoteOperation::FAILURE_PERMANENT,
                $source,
                $inverse,
                $expiresAt,
            );
        }

        if ($source->reconciled_at
            || Arr::get($source->result_snapshot_json ?? [], 'completion_mode') === 'reconciled'
            || $source->attemptRecords->contains('failure_classification', EmailRemoteOperation::FAILURE_AMBIGUOUS)) {
            return $this->blocked(
                'EMAIL_UNDO_SOURCE_AMBIGUOUS',
                'This operation required ambiguous-result reconciliation and cannot be inverted safely.',
                EmailRemoteOperation::FAILURE_AMBIGUOUS,
                $source,
                $inverse,
                $expiresAt,
            );
        }

        if (! $this->hasCompleteSnapshot($source)) {
            return $this->blocked(
                'EMAIL_UNDO_RESULT_EVIDENCE_MISSING',
                'The successful operation does not have complete immutable result evidence.',
                EmailRemoteOperation::FAILURE_STALE,
                $source,
                $inverse,
                $expiresAt,
            );
        }

        if ($inverse && (! $currentInverse || (int) $inverse->id !== (int) $currentInverse->id)) {
            return $this->blocked(
                'EMAIL_UNDO_ALREADY_REQUESTED',
                'Undo has already been requested; the existing inverse operation is shown instead.',
                null,
                $source,
                $inverse,
                $expiresAt,
            );
        }

        if (! $currentInverse && (! $expiresAt || now()->greaterThan($expiresAt))) {
            return $this->blocked(
                'EMAIL_UNDO_WINDOW_EXPIRED',
                'The verified Undo window has expired.',
                EmailRemoteOperation::FAILURE_STALE,
                $source,
                $inverse,
                $expiresAt,
            );
        }

        if ($currentInverse && $expiresAt && $currentInverse->created_at?->greaterThan($expiresAt)) {
            return $this->blocked(
                'EMAIL_UNDO_REQUEST_WAS_LATE',
                'The inverse operation was not created inside the verified Undo window.',
                EmailRemoteOperation::FAILURE_STALE,
                $source,
                $inverse,
                $expiresAt,
            );
        }

        if ($blocker = $this->localEvidenceBlocker($source)) {
            return $this->blocked(
                $blocker['code'],
                $blocker['message'],
                $blocker['classification'],
                $source,
                $inverse,
                $expiresAt,
            );
        }

        if ($this->hasLaterOperation($source, $currentInverse)) {
            return $this->blocked(
                'EMAIL_UNDO_LATER_MUTATION',
                'A later mailbox operation targets the same placement, so Undo is no longer safe.',
                EmailRemoteOperation::FAILURE_STALE,
                $source,
                $inverse,
                $expiresAt,
            );
        }

        return [
            'eligible' => true,
            'reason_code' => 'EMAIL_UNDO_AVAILABLE',
            'reason_message' => 'Undo is available; provider state will be verified again before any write.',
            'classification' => null,
            'inverse_operation_type' => self::INVERSE_TYPES[$source->operation_type],
            'inverse_operation_id' => $currentInverse?->id,
            'inverse_operation_status' => $currentInverse?->status,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{operation_type: string, placement: EmailMailboxPlacement, target_folder: EmailFolder|null, request: array<string, mixed>}
     */
    public function inverseContext(EmailRemoteOperation $source): array
    {
        $snapshot = $source->result_snapshot_json ?? [];
        $move = in_array($source->operation_type, [
            PerformEmailRemoteOperation::ARCHIVE,
            PerformEmailRemoteOperation::TRASH,
            PerformEmailRemoteOperation::MOVE,
        ], true);
        $placementEvidence = Arr::get($snapshot, $move ? 'target_after' : 'source_after', []);
        $placement = EmailMailboxPlacement::query()->findOrFail((int) ($placementEvidence['placement_id'] ?? 0));
        $targetFolder = $move
            ? EmailFolder::query()->findOrFail((int) Arr::get($snapshot, 'source_folder.folder_id', 0))
            : null;
        $operationType = self::INVERSE_TYPES[$source->operation_type];

        return [
            'operation_type' => $operationType,
            'placement' => $placement,
            'target_folder' => $targetFolder,
            'request' => [
                'source_folder_path' => (string) $placement->folder_path,
                'target_folder_path' => $targetFolder?->path,
                'placement_sync_version' => (int) $placement->sync_version,
                'placement_imap_uid' => (int) $placement->imap_uid,
                'placement_uid_validity' => (int) $placement->imap_uid_validity,
                'target_state' => $this->targetState($operationType),
                'inverse_of_operation_id' => (int) $source->id,
                'undo_source_snapshot_captured_at' => $source->result_snapshot_captured_at?->toIso8601String(),
            ],
        ];
    }

    /** @return array{code: string, message: string, classification: string}|null */
    public function localEvidenceBlocker(EmailRemoteOperation $source): ?array
    {
        $snapshot = $source->result_snapshot_json ?? [];
        $sourceBefore = Arr::get($snapshot, 'source_before', []);
        if ((int) ($sourceBefore['placement_id'] ?? 0) !== (int) $source->email_mailbox_placement_id
            || (int) ($sourceBefore['folder_id'] ?? 0) !== (int) $source->email_folder_id
            || (string) ($sourceBefore['folder_path'] ?? '') !== (string) $source->source_folder_path
            || (int) ($sourceBefore['sync_version'] ?? -1) !== (int) $source->expected_placement_sync_version
            || (int) ($sourceBefore['imap_uid'] ?? 0) !== (int) $source->expected_provider_uid
            || (int) ($sourceBefore['uid_validity'] ?? -1) !== (int) $source->expected_uid_validity) {
            return $this->evidenceBlocker(
                'EMAIL_UNDO_SOURCE_SNAPSHOT_STALE',
                'The immutable source snapshot no longer matches the source operation evidence.',
            );
        }

        $sourceAfter = Arr::get($snapshot, 'source_after', []);
        $sourcePlacement = EmailMailboxPlacement::query()->find((int) ($sourceAfter['placement_id'] ?? 0));
        if (! $sourcePlacement || ! $this->placementMatches($sourcePlacement, $sourceAfter)) {
            return $this->evidenceBlocker(
                'EMAIL_UNDO_SOURCE_PLACEMENT_STALE',
                'The source placement changed after the provider acknowledged the operation.',
            );
        }

        $sourceFolderEvidence = Arr::get($snapshot, 'source_folder', []);
        $sourceFolder = EmailFolder::query()->find((int) ($sourceFolderEvidence['folder_id'] ?? 0));
        if (! $sourceFolder || ! $this->folderMatches($sourceFolder, $sourceFolderEvidence)) {
            return $this->evidenceBlocker(
                'EMAIL_UNDO_SOURCE_FOLDER_STALE',
                'The original source folder is missing, changed, or no longer selectable.',
            );
        }

        if (in_array($source->operation_type, [
            PerformEmailRemoteOperation::ARCHIVE,
            PerformEmailRemoteOperation::TRASH,
            PerformEmailRemoteOperation::MOVE,
        ], true)) {
            $targetAfter = Arr::get($snapshot, 'target_after');
            $targetFolderEvidence = Arr::get($snapshot, 'target_folder');
            if (! is_array($targetAfter) || ! is_array($targetFolderEvidence)) {
                return $this->evidenceBlocker(
                    'EMAIL_UNDO_MOVE_TARGET_AMBIGUOUS',
                    'The acknowledged move does not contain an exact target placement and folder identity.',
                    EmailRemoteOperation::FAILURE_AMBIGUOUS,
                );
            }

            $targetPlacement = EmailMailboxPlacement::query()->find((int) ($targetAfter['placement_id'] ?? 0));
            if (! $targetPlacement || ! $this->placementMatches($targetPlacement, $targetAfter)) {
                return $this->evidenceBlocker(
                    'EMAIL_UNDO_TARGET_PLACEMENT_STALE',
                    'The acknowledged target placement is missing or changed.',
                );
            }

            $targetFolder = EmailFolder::query()->find((int) ($targetFolderEvidence['folder_id'] ?? 0));
            if (! $targetFolder || ! $this->folderMatches($targetFolder, $targetFolderEvidence)) {
                return $this->evidenceBlocker(
                    'EMAIL_UNDO_TARGET_FOLDER_STALE',
                    'The acknowledged target folder is missing, changed, or no longer selectable.',
                );
            }
        }

        return null;
    }

    private function hasCompleteSnapshot(EmailRemoteOperation $source): bool
    {
        $snapshot = $source->result_snapshot_json ?? [];
        $sourceBefore = Arr::get($snapshot, 'source_before', []);
        $sourceAfter = Arr::get($snapshot, 'source_after', []);
        $sourceFolder = Arr::get($snapshot, 'source_folder', []);

        return (int) Arr::get($snapshot, 'schema_version', 0) === EmailRemoteOperationResultSnapshot::SCHEMA_VERSION
            && Arr::get($snapshot, 'operation_type') === $source->operation_type
            && is_array($sourceBefore)
            && (int) ($sourceBefore['placement_id'] ?? 0) > 0
            && (int) ($sourceBefore['folder_id'] ?? 0) > 0
            && (int) ($sourceBefore['imap_uid'] ?? 0) > 0
            && (int) ($sourceBefore['uid_validity'] ?? 0) > 0
            && filled($sourceBefore['folder_path'] ?? null)
            && is_array($sourceAfter)
            && (int) ($sourceAfter['placement_id'] ?? 0) > 0
            && (int) ($sourceAfter['imap_uid'] ?? 0) > 0
            && (int) ($sourceAfter['uid_validity'] ?? 0) > 0
            && is_array($sourceFolder)
            && (int) ($sourceFolder['folder_id'] ?? 0) > 0
            && (int) ($sourceFolder['uid_validity'] ?? 0) > 0
            && $source->result_snapshot_captured_at !== null;
    }

    private function placementMatches(EmailMailboxPlacement $placement, array $evidence): bool
    {
        return (int) $placement->id === (int) ($evidence['placement_id'] ?? 0)
            && (int) $placement->account_id === (int) ($evidence['account_id'] ?? 0)
            && (int) $placement->email_folder_id === (int) ($evidence['folder_id'] ?? 0)
            && (string) $placement->folder_path === (string) ($evidence['folder_path'] ?? '')
            && (int) $placement->sync_version === (int) ($evidence['sync_version'] ?? -1)
            && (int) $placement->imap_uid === (int) ($evidence['imap_uid'] ?? 0)
            && (int) $placement->imap_uid_validity === (int) ($evidence['uid_validity'] ?? -1)
            && (string) $placement->local_state === (string) ($evidence['local_state'] ?? '')
            && (bool) $placement->provider_seen === (bool) ($evidence['provider_seen'] ?? false)
            && (bool) $placement->provider_flagged === (bool) ($evidence['provider_flagged'] ?? false);
    }

    private function folderMatches(EmailFolder $folder, array $evidence): bool
    {
        return (int) $folder->id === (int) ($evidence['folder_id'] ?? 0)
            && (int) $folder->account_id === (int) ($evidence['account_id'] ?? 0)
            && (string) $folder->path === (string) ($evidence['path'] ?? '')
            && (int) $folder->uid_validity === (int) ($evidence['uid_validity'] ?? -1)
            && $folder->is_selectable
            && $folder->sync_enabled;
    }

    private function hasLaterOperation(
        EmailRemoteOperation $source,
        ?EmailRemoteOperation $currentInverse,
    ): bool {
        $snapshot = $source->result_snapshot_json ?? [];
        $placementIds = collect([
            Arr::get($snapshot, 'source_after.placement_id'),
            Arr::get($snapshot, 'target_after.placement_id'),
        ])->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();

        if ($placementIds === []) {
            return true;
        }

        return EmailRemoteOperation::query()
            ->whereKeyNot($source->id)
            ->when($currentInverse, fn ($query) => $query->whereKeyNot($currentInverse->id))
            ->whereIn('email_mailbox_placement_id', $placementIds)
            ->where(function ($query) use ($source): void {
                $query->whereIn('status', [
                    EmailRemoteOperation::STATUS_PENDING,
                    EmailRemoteOperation::STATUS_RUNNING,
                    EmailRemoteOperation::STATUS_FAILED,
                ])->orWhere(function ($succeeded) use ($source): void {
                    $succeeded
                        ->where('status', EmailRemoteOperation::STATUS_SUCCEEDED)
                        ->where('acknowledged_at', '>', $source->acknowledged_at);
                });
            })
            ->exists();
    }

    /** @return array<string, bool> */
    private function targetState(string $operationType): array
    {
        return match ($operationType) {
            PerformEmailRemoteOperation::MARK_SEEN => ['provider_seen' => true],
            PerformEmailRemoteOperation::MARK_UNSEEN => ['provider_seen' => false],
            PerformEmailRemoteOperation::FLAG => ['provider_flagged' => true],
            PerformEmailRemoteOperation::UNFLAG => ['provider_flagged' => false],
            default => [],
        };
    }

    /**
     * @return array{
     *   eligible: false,
     *   reason_code: string,
     *   reason_message: string,
     *   classification: string|null,
     *   inverse_operation_type: string|null,
     *   inverse_operation_id: int|null,
     *   inverse_operation_status: string|null,
     *   expires_at: CarbonInterface|null
     * }
     */
    private function blocked(
        string $code,
        string $message,
        ?string $classification,
        EmailRemoteOperation $source,
        ?EmailRemoteOperation $inverse,
        ?CarbonInterface $expiresAt,
    ): array {
        return [
            'eligible' => false,
            'reason_code' => $code,
            'reason_message' => $message,
            'classification' => $classification,
            'inverse_operation_type' => self::INVERSE_TYPES[$source->operation_type] ?? null,
            'inverse_operation_id' => $inverse?->id,
            'inverse_operation_status' => $inverse?->status,
            'expires_at' => $expiresAt,
        ];
    }

    /** @return array{code: string, message: string, classification: string} */
    private function evidenceBlocker(
        string $code,
        string $message,
        string $classification = EmailRemoteOperation::FAILURE_STALE,
    ): array {
        return compact('code', 'message', 'classification');
    }
}
