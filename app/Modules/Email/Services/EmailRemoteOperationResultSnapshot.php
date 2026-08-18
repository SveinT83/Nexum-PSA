<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailRemoteOperation;

class EmailRemoteOperationResultSnapshot
{
    public const SCHEMA_VERSION = 1;

    /**
     * Capture only identity and provider-state metadata needed to prove an
     * inverse. Message content, headers, addresses, MIME, and secrets are not
     * part of this durable result evidence.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    public function capture(
        EmailRemoteOperation $operation,
        array $response,
        bool $reconciled = false,
    ): ?array {
        if (! in_array($operation->operation_type, PerformEmailRemoteOperation::allowedOperations(), true)) {
            return null;
        }

        $source = EmailMailboxPlacement::query()->find($operation->email_mailbox_placement_id);
        $sourceFolder = EmailFolder::query()->find($operation->email_folder_id);
        if (! $source || ! $sourceFolder) {
            return null;
        }

        $target = isset($response['target_placement_id'])
            ? EmailMailboxPlacement::query()->find((int) $response['target_placement_id'])
            : null;
        $targetFolder = isset($response['target_folder_id'])
            ? EmailFolder::query()->find((int) $response['target_folder_id'])
            : null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'operation_type' => $operation->operation_type,
            'completion_mode' => $reconciled ? 'reconciled' : 'acknowledged',
            'source_before' => [
                'placement_id' => (int) $operation->email_mailbox_placement_id,
                'folder_id' => (int) $operation->email_folder_id,
                'folder_path' => (string) $operation->source_folder_path,
                'sync_version' => $operation->expected_placement_sync_version,
                'imap_uid' => $operation->expected_provider_uid,
                'uid_validity' => $operation->expected_uid_validity,
            ],
            'source_after' => $this->placementEvidence($source),
            'target_after' => $target ? $this->placementEvidence($target) : null,
            'source_folder' => $this->folderEvidence($sourceFolder),
            'target_folder' => $targetFolder ? $this->folderEvidence($targetFolder) : null,
            'provider_result' => [
                'provider_seen' => $response['provider_seen'] ?? null,
                'provider_flagged' => $response['provider_flagged'] ?? null,
                'source_hidden' => $response['source_hidden'] ?? null,
                'target_imap_uid' => $response['target_imap_uid'] ?? null,
                'target_uid_validity' => $response['target_uid_validity'] ?? null,
                'target_uid_authoritative' => $response['target_uid_authoritative'] ?? null,
            ],
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function placementEvidence(EmailMailboxPlacement $placement): array
    {
        return [
            'placement_id' => (int) $placement->id,
            'account_id' => (int) $placement->account_id,
            'folder_id' => (int) $placement->email_folder_id,
            'folder_path' => (string) $placement->folder_path,
            'sync_version' => (int) $placement->sync_version,
            'imap_uid' => (int) $placement->imap_uid,
            'uid_validity' => (int) $placement->imap_uid_validity,
            'local_state' => (string) $placement->local_state,
            'provider_seen' => (bool) $placement->provider_seen,
            'provider_flagged' => (bool) $placement->provider_flagged,
        ];
    }

    /** @return array<string, mixed> */
    private function folderEvidence(EmailFolder $folder): array
    {
        return [
            'folder_id' => (int) $folder->id,
            'account_id' => (int) $folder->account_id,
            'path' => (string) $folder->path,
            'uid_validity' => (int) $folder->uid_validity,
            'is_selectable' => (bool) $folder->is_selectable,
            'sync_enabled' => (bool) $folder->sync_enabled,
        ];
    }
}
