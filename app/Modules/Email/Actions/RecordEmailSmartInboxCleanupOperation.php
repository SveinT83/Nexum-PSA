<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Support\EmailProviderPath;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RecordEmailSmartInboxCleanupOperation
{
    public function __construct(
        private readonly RecordEmailRemoteOperation $recordRemoteOperation,
    ) {}

    /**
     * Record the reviewed provider mutation while the suggestion transaction
     * is open. The caller runs the ordinary recovery action only after this
     * record and the applied suggestion audit have committed.
     *
     * @throws ValidationException
     */
    public function handle(
        EmailSmartInboxSuggestion $suggestion,
        EmailMailboxPlacement $placement,
        User $actor,
    ): EmailRemoteOperation {
        $placement->loadMissing(['account', 'folder', 'message']);

        if (! $placement->account
            || ! $placement->folder
            || ! $placement->message
            || (int) $placement->account_id !== (int) $suggestion->account_id
            || (int) $placement->email_conversation_id !== (int) $suggestion->email_conversation_id
            || $placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE
            || ! $placement->folder->is_selectable
            || ! $placement->folder->sync_enabled) {
            throw ValidationException::withMessages([
                'suggestion' => 'The provider source placement is no longer available for cleanup.',
            ]);
        }

        $operationType = match ($suggestion->effect_type) {
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL => PerformEmailRemoteOperation::ARCHIVE,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER => PerformEmailRemoteOperation::MOVE,
            default => throw ValidationException::withMessages([
                'suggestion' => 'This Smart Inbox proposal is not an allowlisted cleanup action.',
            ]),
        };

        $targetFolderId = $this->positiveInteger($suggestion->proposal_json['target_folder_id'] ?? null);
        $targetFolder = EmailFolder::query()
            ->whereKey($targetFolderId)
            ->where('account_id', $suggestion->account_id)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->when(
                $operationType === PerformEmailRemoteOperation::ARCHIVE,
                fn ($folders) => $folders->where('role', EmailFolder::ROLE_ARCHIVE),
            )
            ->lockForUpdate()
            ->first();

        try {
            $targetFolderPath = $targetFolder
                ? EmailProviderPath::normalize((string) $targetFolder->getAttribute('path'))
                : null;
        } catch (InvalidArgumentException) {
            $targetFolderPath = null;
        }
        if (! $targetFolder
            || $targetFolderPath === null
            || (int) $targetFolder->id === (int) $placement->email_folder_id
            || (string) ($suggestion->proposal_json['target_folder_path'] ?? '') !== $targetFolderPath
            || (string) ($suggestion->proposal_json['target_folder_name'] ?? '') !== (string) $targetFolder->name) {
            throw ValidationException::withMessages([
                'suggestion' => 'The proposed provider target is stale or no longer selectable.',
            ]);
        }

        $storedSourcePath = $placement->getAttribute('folder_path');
        if (! is_string($storedSourcePath) || $storedSourcePath === '') {
            $storedSourcePath = $placement->folder->getAttribute('path');
        }
        try {
            $sourceFolderPath = EmailProviderPath::normalize((string) $storedSourcePath);
        } catch (InvalidArgumentException) {
            $sourceFolderPath = null;
        }
        if ($sourceFolderPath === null
            || (string) $placement->folder->path !== $sourceFolderPath
            || (int) $placement->imap_uid <= 0) {
            throw ValidationException::withMessages([
                'suggestion' => 'The provider source placement is missing immutable UID evidence.',
            ]);
        }

        $request = [
            'source_folder_path' => $sourceFolderPath,
            'target_folder_path' => $targetFolderPath,
            'target_folder_id' => (int) $targetFolder->id,
            'placement_sync_version' => (int) $placement->sync_version,
            'placement_imap_uid' => (int) $placement->imap_uid,
            'placement_uid_validity' => (int) $placement->imap_uid_validity,
            'target_state' => [],
        ];
        $operation = $this->recordRemoteOperation->pending(
            $placement->account,
            $operationType,
            self::idempotencyKey($suggestion),
            $actor,
            $placement->folder,
            $placement,
            $request,
        );

        if ((int) $operation->account_id !== (int) $placement->account_id
            || (int) $operation->email_folder_id !== (int) $placement->email_folder_id
            || (int) $operation->email_mailbox_placement_id !== (int) $placement->id
            || (int) $operation->requested_by !== (int) $actor->id
            || $operation->operation_type !== $operationType
            || $operation->source_folder_path !== $sourceFolderPath
            || $operation->target_folder_path !== $targetFolderPath
            || (int) data_get($operation->request_json, 'target_folder_id') !== (int) $targetFolder->id) {
            throw ValidationException::withMessages([
                'suggestion' => 'The deterministic provider-operation reference is already in use.',
            ]);
        }

        return $operation;
    }

    public static function idempotencyKey(EmailSmartInboxSuggestion $suggestion): string
    {
        return 'mail-op:smart-inbox:'.$suggestion->id;
    }

    private function positiveInteger(mixed $value): int
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            throw ValidationException::withMessages([
                'suggestion' => 'The proposed provider target is invalid.',
            ]);
        }

        $id = (int) $value;
        if ($id < 1) {
            throw ValidationException::withMessages([
                'suggestion' => 'The proposed provider target is invalid.',
            ]);
        }

        return $id;
    }
}
