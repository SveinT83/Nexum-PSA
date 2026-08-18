<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Actions\ManageProviderEmailFolder;
use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Support\EmailProviderPath;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class EmailRemoteOperationReconciler
{
    public const APPLIED = 'applied';

    public const NOT_APPLIED = 'not_applied';

    public const UNRESOLVED = 'unresolved';

    /** @return array{result: string, reason_code: string, reason_message: string, response: array<string, mixed>} */
    public function reconcile(EmailRemoteOperation $operation, ImapClient $client): array
    {
        $operation->loadMissing(['placement.message', 'folder']);

        return match ($operation->operation_type) {
            PerformEmailRemoteOperation::MARK_SEEN => $this->reconcileFlag($operation, $client, 'provider_seen', true),
            PerformEmailRemoteOperation::MARK_UNSEEN => $this->reconcileFlag($operation, $client, 'provider_seen', false),
            PerformEmailRemoteOperation::FLAG => $this->reconcileFlag($operation, $client, 'provider_flagged', true),
            PerformEmailRemoteOperation::UNFLAG => $this->reconcileFlag($operation, $client, 'provider_flagged', false),
            PerformEmailRemoteOperation::ARCHIVE,
            PerformEmailRemoteOperation::TRASH,
            PerformEmailRemoteOperation::MOVE => $this->reconcileMove($operation, $client),
            ManageProviderEmailFolder::RENAME_FOLDER,
            ManageProviderEmailFolder::MOVE_FOLDER => $this->reconcileFolderRename($operation, $client),
            ManageProviderEmailFolder::DELETE_FOLDER => $this->reconcileFolderDelete($operation, $client),
            default => $this->unresolved('REMOTE_RECONCILIATION_UNSUPPORTED', 'This operation type cannot be reconciled safely.'),
        };
    }

    /** @return array{result: string, reason_code: string, reason_message: string, response: array<string, mixed>} */
    private function reconcileFlag(
        EmailRemoteOperation $operation,
        ImapClient $client,
        string $field,
        bool $expected,
    ): array {
        $placement = $operation->placement;
        if (! $placement) {
            return $this->unresolved('REMOTE_RECONCILIATION_CONTEXT', 'The mailbox placement no longer exists.');
        }

        $sourcePath = $this->providerPath($operation->getAttribute('source_folder_path'));
        $sourceUidValidity = (int) $operation->expected_uid_validity;
        if ($sourcePath === null || $sourceUidValidity <= 0) {
            return $this->unresolved('REMOTE_RECONCILIATION_SOURCE_NAMESPACE', 'The frozen source UID namespace is incomplete.');
        }
        if (! $this->hasExactActiveSourceNamespace($operation, $placement, $sourcePath, $sourceUidValidity)) {
            return $this->unresolved('REMOTE_RECONCILIATION_SOURCE_NAMESPACE', 'The frozen source UID namespace is no longer active locally.');
        }

        $providerSourceFolderState = $client->folderState($sourcePath);
        if ((int) ($providerSourceFolderState['uid_validity'] ?? 0) !== $sourceUidValidity) {
            return $this->unresolved('REMOTE_RECONCILIATION_SOURCE_NAMESPACE', 'The provider source UID namespace changed before recovery.');
        }

        $state = $client->messageStateByUid(
            (int) $operation->expected_provider_uid,
            $sourcePath,
        );

        if (! ($state['exists'] ?? false)) {
            return $this->unresolved('REMOTE_RECONCILIATION_MESSAGE_MISSING', 'Provider evidence cannot locate the source message.');
        }

        if ((bool) ($state[$field] ?? false) !== $expected) {
            return $this->notApplied('REMOTE_RECONCILIATION_NOT_APPLIED', 'Provider state proves the requested flag change was not applied.', $state);
        }

        $placement->forceFill([
            $field => $expected,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => ((int) $placement->sync_version) + 1,
            'last_reconciled_at' => now(),
            'sync_error_code' => null,
            'sync_error_message' => null,
        ])->save();

        app(EmailConversationProjector::class)->refreshForPlacement($placement->refresh());

        return $this->applied('REMOTE_RECONCILIATION_APPLIED', 'Provider state confirms the requested flag change was applied.', $state);
    }

    /** @return array{result: string, reason_code: string, reason_message: string, response: array<string, mixed>} */
    private function reconcileMove(EmailRemoteOperation $operation, ImapClient $client): array
    {
        $source = $operation->placement;
        if (! $source) {
            return $this->unresolved('REMOTE_RECONCILIATION_CONTEXT', 'The source mailbox placement no longer exists.');
        }

        $targetUid = (int) $operation->acknowledged_target_uid;
        $targetUidValidity = (int) $operation->acknowledged_target_uid_validity;
        $targetPath = $this->providerPath($operation->getAttribute('target_folder_path'));

        if ($targetUid <= 0 || $targetUidValidity <= 0 || $targetPath === null) {
            return $this->unresolved('REMOTE_RECONCILIATION_MOVE_AMBIGUOUS', 'The provider did not supply complete target evidence. The move will not be replayed.');
        }

        $sourcePath = $this->providerPath($operation->getAttribute('source_folder_path'));
        $sourceUidValidity = (int) $operation->expected_uid_validity;
        if ($sourcePath === null || $sourceUidValidity <= 0) {
            return $this->unresolved('REMOTE_RECONCILIATION_SOURCE_NAMESPACE', 'The frozen source UID namespace is incomplete.');
        }
        if (! $this->hasExactActiveSourceNamespace($operation, $source, $sourcePath, $sourceUidValidity)) {
            return $this->unresolved('REMOTE_RECONCILIATION_SOURCE_NAMESPACE', 'The frozen source UID namespace is no longer active locally.');
        }

        $providerSourceFolderState = $client->folderState($sourcePath);
        if ((int) ($providerSourceFolderState['uid_validity'] ?? 0) !== $sourceUidValidity) {
            return $this->unresolved('REMOTE_RECONCILIATION_SOURCE_NAMESPACE', 'The provider source UID namespace changed before recovery.');
        }

        $targetFolder = EmailFolder::query()
            ->where('account_id', $operation->account_id)
            ->where('path', $targetPath)
            ->first();
        $targetNamespace = $targetFolder
            ? EmailFolderUidNamespace::query()
                ->whereKey($targetFolder->active_uid_namespace_id)
                ->where('account_id', $operation->account_id)
                ->where('email_folder_id', $targetFolder->id)
                ->where('uid_validity', $targetUidValidity)
                ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
                ->first()
            : null;
        if (! $targetFolder || ! $targetNamespace) {
            return $this->unresolved('REMOTE_RECONCILIATION_TARGET_NAMESPACE', 'The acknowledged target UID namespace is no longer active locally.');
        }

        $providerTargetFolderState = $client->folderState($targetPath);
        if ((int) ($providerTargetFolderState['uid_validity'] ?? 0) !== $targetUidValidity) {
            return $this->unresolved('REMOTE_RECONCILIATION_TARGET_NAMESPACE', 'The provider target UID namespace changed after acknowledgement.');
        }

        $sourceState = $client->messageStateByUid(
            (int) $operation->expected_provider_uid,
            $sourcePath,
        );
        $targetState = $client->messageStateByUid($targetUid, $targetPath);

        if ($sourceState['exists'] ?? false) {
            if ($targetState['exists'] ?? false) {
                return $this->unresolved('REMOTE_RECONCILIATION_MOVE_DUPLICATED', 'Both source and target provider placements exist. The move will not be replayed.');
            }

            return $this->notApplied('REMOTE_RECONCILIATION_NOT_APPLIED', 'Provider evidence proves the target is absent while the source still exists, so the move may be retried.', [
                'source' => $sourceState,
                'target' => $targetState,
            ]);
        }

        if (! ($targetState['exists'] ?? false)) {
            return $this->unresolved('REMOTE_RECONCILIATION_TARGET_MISSING', 'Neither the expected source nor target provider placement can be proven.');
        }

        $attributes = [
            'email_message_id' => $source->email_message_id,
            'provider' => $source->provider,
            'folder_path' => $targetFolder->path,
            'remote_message_id' => $source->remote_message_id,
            'provider_seen' => $source->provider_seen,
            'provider_answered' => $source->provider_answered,
            'provider_flagged' => $source->provider_flagged,
            'provider_deleted' => false,
            'provider_draft' => $source->provider_draft,
            'flags_json' => $source->flags_json,
            'labels_json' => $source->labels_json,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
            'last_reconciled_at' => now(),
            'provider_missing_at' => null,
            'sync_error_code' => null,
            'sync_error_message' => null,
        ];

        if (Schema::hasColumn('email_mailbox_placements', 'email_conversation_id')) {
            $attributes['email_conversation_id'] = $source->email_conversation_id;
        }

        $target = EmailMailboxPlacement::query()->updateOrCreate([
            'account_id' => $source->account_id,
            'email_folder_id' => $targetFolder->id,
            'uid_namespace_id' => $targetNamespace->id,
            'imap_uid_validity' => $targetUidValidity,
            'imap_uid' => $targetUid,
        ], $attributes);

        $source->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => ((int) $source->sync_version) + 1,
            'provider_missing_at' => now(),
            'last_reconciled_at' => now(),
            'sync_error_code' => null,
            'sync_error_message' => null,
        ])->save();

        app(EmailConversationProjector::class)->assignPlacement($target);
        app(EmailConversationProjector::class)->refreshForPlacement($target->refresh());

        return $this->applied('REMOTE_RECONCILIATION_APPLIED', 'Provider evidence confirms the move and the local placement was reconciled.', [
            'target_folder_path' => $targetPath,
            'target_imap_uid' => $targetUid,
            'target_uid_validity' => $targetUidValidity,
            'target_uid_authoritative' => true,
            'target_placement_id' => $target->id,
        ]);
    }

    private function hasExactActiveSourceNamespace(
        EmailRemoteOperation $operation,
        EmailMailboxPlacement $placement,
        string $sourcePath,
        int $sourceUidValidity,
    ): bool {
        $folder = EmailFolder::query()
            ->where('account_id', $operation->account_id)
            ->where('path', $sourcePath)
            ->first();
        if (! $folder
            || (int) $placement->uid_namespace_id <= 0
            || (int) $placement->imap_uid_validity !== $sourceUidValidity
            || (int) $folder->active_uid_namespace_id !== (int) $placement->uid_namespace_id) {
            return false;
        }

        return EmailFolderUidNamespace::query()
            ->whereKey($placement->uid_namespace_id)
            ->where('account_id', $operation->account_id)
            ->where('email_folder_id', $folder->id)
            ->where('uid_validity', $sourceUidValidity)
            ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
            ->exists();
    }

    /** @return array{result: string, reason_code: string, reason_message: string, response: array<string, mixed>} */
    private function reconcileFolderRename(EmailRemoteOperation $operation, ImapClient $client): array
    {
        $sourcePath = $this->providerPath($operation->getAttribute('source_folder_path'));
        $targetPath = $this->providerPath($operation->getAttribute('target_folder_path'));
        if ($sourcePath === null || $targetPath === null) {
            return $this->unresolved('REMOTE_RECONCILIATION_FOLDER_AMBIGUOUS', 'Provider folder evidence is ambiguous, so the rename will not be replayed.');
        }
        $sourceExists = $client->folderExists($sourcePath);
        $targetExists = $client->folderExists($targetPath);

        if ($sourceExists && ! $targetExists) {
            return $this->notApplied('REMOTE_RECONCILIATION_NOT_APPLIED', 'The source folder still exists and the target does not, so the rename may be retried.');
        }

        if (! $sourceExists && $targetExists && $operation->folder) {
            $folder = $operation->folder;
            $folder->forceFill([
                'path' => $targetPath,
                'name' => basename(str_replace('\\', '/', $targetPath)) ?: $targetPath,
                'parent_path' => str_contains($targetPath, '/') ? dirname($targetPath) : null,
                'remote_id' => $targetPath,
                'sync_status' => EmailFolder::SYNC_SYNCED,
                'last_synced_at' => now(),
                'sync_error_code' => null,
                'sync_error_message' => null,
            ])->save();

            EmailMailboxPlacement::query()
                ->where('email_folder_id', $folder->id)
                ->update([
                    'folder_path' => $targetPath,
                    'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
                    'last_reconciled_at' => now(),
                    'sync_error_code' => null,
                    'sync_error_message' => null,
                ]);

            return $this->applied('REMOTE_RECONCILIATION_APPLIED', 'Provider evidence confirms the folder rename and the local folder was reconciled.', [
                'source_folder_path' => $sourcePath,
                'target_folder_path' => $targetPath,
            ]);
        }

        return $this->unresolved('REMOTE_RECONCILIATION_FOLDER_AMBIGUOUS', 'Provider folder evidence is ambiguous, so the rename will not be replayed.');
    }

    /** @return array{result: string, reason_code: string, reason_message: string, response: array<string, mixed>} */
    private function reconcileFolderDelete(EmailRemoteOperation $operation, ImapClient $client): array
    {
        $sourcePath = $this->providerPath($operation->getAttribute('source_folder_path'));
        if ($sourcePath === null) {
            return $this->unresolved('REMOTE_RECONCILIATION_FOLDER_AMBIGUOUS', 'Provider folder evidence is ambiguous, so the deletion will not be replayed.');
        }
        if ($client->folderExists($sourcePath)) {
            return $this->notApplied('REMOTE_RECONCILIATION_NOT_APPLIED', 'The provider folder still exists, so deletion may be retried.');
        }

        if (! $operation->folder) {
            return $this->unresolved('REMOTE_RECONCILIATION_CONTEXT', 'The local folder record no longer exists.');
        }

        $operation->folder->forceFill([
            'is_selectable' => false,
            'sync_enabled' => false,
            'exists_count' => 0,
            'unseen_count' => 0,
            'sync_status' => EmailFolder::SYNC_SYNCED,
            'last_synced_at' => now(),
            'sync_error_code' => null,
            'sync_error_message' => null,
        ])->save();

        return $this->applied('REMOTE_RECONCILIATION_APPLIED', 'Provider evidence confirms the folder is deleted and the local projection was reconciled.');
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

    /** @param array<string, mixed> $response */
    private function applied(string $code, string $message, array $response = []): array
    {
        return compact('response') + ['result' => self::APPLIED, 'reason_code' => $code, 'reason_message' => $message];
    }

    /** @param array<string, mixed> $response */
    private function notApplied(string $code, string $message, array $response = []): array
    {
        return compact('response') + ['result' => self::NOT_APPLIED, 'reason_code' => $code, 'reason_message' => $message];
    }

    /** @return array{result: string, reason_code: string, reason_message: string, response: array<string, mixed>} */
    private function unresolved(string $code, string $message): array
    {
        return ['result' => self::UNRESOLVED, 'reason_code' => $code, 'reason_message' => $message, 'response' => []];
    }
}
