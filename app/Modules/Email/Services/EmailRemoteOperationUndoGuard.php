<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Models\EmailRemoteOperation;
use Illuminate\Support\Arr;

class EmailRemoteOperationUndoGuard
{
    public function __construct(
        private readonly EmailRemoteOperationUndoEligibility $eligibility,
    ) {}

    /** @return array{code: string, message: string, classification: string}|null */
    public function localBlocker(EmailRemoteOperation $inverse): ?array
    {
        if (! $this->isInverseOperation($inverse)) {
            return null;
        }

        if (! $inverse->inverse_of_email_remote_operation_id) {
            return $this->blocker(
                'EMAIL_UNDO_SOURCE_LINK_MISSING',
                'The immutable source link for this inverse no longer exists.',
                EmailRemoteOperation::FAILURE_STALE,
            );
        }

        $inverse->loadMissing(['inverseOf.inverseOperation', 'requester']);
        $source = $inverse->inverseOf;
        if (! $source) {
            return $this->blocker(
                'EMAIL_UNDO_SOURCE_MISSING',
                'The source operation for this inverse no longer exists.',
                EmailRemoteOperation::FAILURE_STALE,
            );
        }

        $result = $this->eligibility->evaluate($source, $inverse->requester, $inverse);
        if (! $result['eligible']) {
            return $this->blocker(
                $result['reason_code'],
                $result['reason_message'],
                $result['classification'] ?: EmailRemoteOperation::FAILURE_STALE,
            );
        }

        if ($result['inverse_operation_type'] !== $inverse->operation_type) {
            return $this->blocker(
                'EMAIL_UNDO_INVERSE_TYPE_STALE',
                'The recorded inverse type no longer matches the verified source result.',
                EmailRemoteOperation::FAILURE_STALE,
            );
        }

        $context = $this->eligibility->inverseContext($source);
        $placement = $context['placement'];
        $targetFolder = $context['target_folder'];
        if ((int) $inverse->account_id !== (int) $source->account_id
            || (int) $inverse->email_mailbox_placement_id !== (int) $placement->id
            || (int) $inverse->email_folder_id !== (int) $placement->email_folder_id
            || (string) $inverse->source_folder_path !== (string) $placement->folder_path
            || (string) ($inverse->target_folder_path ?? '') !== (string) ($targetFolder?->path ?? '')
            || (int) $inverse->expected_placement_sync_version !== (int) $placement->sync_version
            || (int) $inverse->expected_provider_uid !== (int) $placement->imap_uid
            || (int) $inverse->expected_uid_validity !== (int) $placement->imap_uid_validity) {
            return $this->blocker(
                'EMAIL_UNDO_INVERSE_EVIDENCE_STALE',
                'The inverse operation no longer matches the exact verified source result.',
                EmailRemoteOperation::FAILURE_STALE,
            );
        }

        return null;
    }

    /**
     * Verify the exact provider result immediately before the inverse write.
     * Provider/network exceptions intentionally bubble to the normal recovery
     * classifier; an explicit mismatch returns a terminal stale blocker.
     *
     * @return array{verified: bool, code: string, message: string, classification: string|null, evidence: array<string, mixed>}
     */
    public function verifyProvider(EmailRemoteOperation $inverse, ImapClient $client): array
    {
        if (! $this->isInverseOperation($inverse)) {
            return [
                'verified' => true,
                'code' => 'EMAIL_UNDO_NOT_APPLICABLE',
                'message' => 'This operation is not an inverse.',
                'classification' => null,
                'evidence' => [],
            ];
        }

        if (! $inverse->inverse_of_email_remote_operation_id) {
            return $this->rejected(
                'EMAIL_UNDO_SOURCE_LINK_MISSING',
                'The immutable source link for this inverse no longer exists.',
            );
        }

        $inverse->loadMissing('inverseOf');
        $source = $inverse->inverseOf;
        if (! $source) {
            return $this->rejected(
                'EMAIL_UNDO_SOURCE_MISSING',
                'The source operation for this inverse no longer exists.',
            );
        }

        $snapshot = $source->result_snapshot_json ?? [];
        $move = in_array($source->operation_type, [
            PerformEmailRemoteOperation::ARCHIVE,
            PerformEmailRemoteOperation::TRASH,
            PerformEmailRemoteOperation::MOVE,
        ], true);
        $currentEvidence = Arr::get($snapshot, $move ? 'target_after' : 'source_after', []);
        $folderPath = (string) ($currentEvidence['folder_path'] ?? '');
        $uid = (int) ($currentEvidence['imap_uid'] ?? 0);
        $uidValidity = (int) ($currentEvidence['uid_validity'] ?? 0);

        $folderState = $client->folderState($folderPath);
        $evidence = [
            'source_folder_path' => $folderPath,
            'imap_uid' => $uid,
            'uid_validity' => (int) ($folderState['uid_validity'] ?? 0),
        ];

        if ((int) ($folderState['uid_validity'] ?? 0) !== $uidValidity) {
            return $this->rejected(
                'EMAIL_UNDO_PROVIDER_UIDVALIDITY_STALE',
                'The provider UIDVALIDITY changed after the source operation.',
                $evidence,
            );
        }

        $state = $client->messageStateByUid($uid, $folderPath);
        $evidence += [
            'provider_state' => [
                'exists' => (bool) ($state['exists'] ?? false),
                'folder_path' => (string) ($state['folder_path'] ?? $folderPath),
                'imap_uid' => (int) ($state['imap_uid'] ?? $uid),
                'provider_seen' => $state['provider_seen'] ?? null,
                'provider_flagged' => $state['provider_flagged'] ?? null,
            ],
        ];

        if (! ($state['exists'] ?? false)
            || (int) ($state['imap_uid'] ?? 0) !== $uid
            || (string) ($state['folder_path'] ?? '') !== $folderPath) {
            return $this->rejected(
                'EMAIL_UNDO_PROVIDER_PLACEMENT_STALE',
                'The provider no longer has the exact acknowledged placement.',
                $evidence,
            );
        }

        foreach (['provider_seen', 'provider_flagged'] as $field) {
            if (! array_key_exists($field, $state)
                || ! array_key_exists($field, $currentEvidence)
                || (bool) $state[$field] !== (bool) $currentEvidence[$field]) {
                return $this->rejected(
                    'EMAIL_UNDO_PROVIDER_FLAGS_STALE',
                    'Provider flags are unavailable or changed after the source operation.',
                    $evidence,
                );
            }
        }

        if ($move) {
            $originalEvidence = Arr::get($snapshot, 'source_before', []);
            $originalPath = (string) ($originalEvidence['folder_path'] ?? '');
            $originalUid = (int) ($originalEvidence['imap_uid'] ?? 0);
            $originalUidValidity = (int) ($originalEvidence['uid_validity'] ?? 0);
            $originalFolderState = $client->folderState($originalPath);
            $evidence['target_folder_path'] = $originalPath;
            $evidence['target_uid_validity'] = (int) ($originalFolderState['uid_validity'] ?? 0);

            if ((int) ($originalFolderState['uid_validity'] ?? 0) !== $originalUidValidity) {
                return $this->rejected(
                    'EMAIL_UNDO_PROVIDER_SOURCE_FOLDER_STALE',
                    'The original provider folder UIDVALIDITY changed after the move.',
                    $evidence,
                );
            }

            $originalState = $client->messageStateByUid($originalUid, $originalPath);
            $evidence['original_source_absent'] = ! (bool) ($originalState['exists'] ?? false);
            if ($originalState['exists'] ?? false) {
                return $this->rejected(
                    'EMAIL_UNDO_PROVIDER_SOURCE_REAPPEARED',
                    'The original provider placement exists again, so reversing the move could duplicate it.',
                    $evidence,
                );
            }
        }

        return [
            'verified' => true,
            'code' => 'EMAIL_UNDO_PROVIDER_VERIFIED',
            'message' => 'Provider state matches the immutable source result.',
            'classification' => null,
            'evidence' => $evidence,
        ];
    }

    public function isInverseOperation(EmailRemoteOperation $operation): bool
    {
        return $operation->inverse_of_email_remote_operation_id !== null
            || (int) Arr::get($operation->request_json ?? [], 'inverse_of_operation_id', 0) > 0
            || str_starts_with((string) $operation->idempotency_key, 'mail-op:undo:');
    }

    /** @return array{verified: false, code: string, message: string, classification: string, evidence: array<string, mixed>} */
    private function rejected(string $code, string $message, array $evidence = []): array
    {
        return [
            'verified' => false,
            'code' => $code,
            'message' => $message,
            'classification' => EmailRemoteOperation::FAILURE_STALE,
            'evidence' => $evidence,
        ];
    }

    /** @return array{code: string, message: string, classification: string} */
    private function blocker(string $code, string $message, string $classification): array
    {
        return compact('code', 'message', 'classification');
    }
}
