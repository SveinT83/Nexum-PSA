<?php

namespace App\Modules\Email\Services;

use Illuminate\Support\Str;

class EmailRemoteOperationEvidenceSanitizer
{
    /**
     * Only operational metadata needed to prove a mailbox mutation is kept.
     * Message content, MIME, attachments, credentials, and arbitrary provider
     * payload fields are intentionally discarded.
     */
    private const ALLOWED_KEYS = [
        'provider',
        'source_folder_path',
        'target_folder_path',
        'folder_path',
        'path',
        'name',
        'folder_id',
        'target_parent_folder_id',
        'placement_sync_version',
        'placement_imap_uid',
        'placement_uid_validity',
        'target_state',
        'ok',
        'provider_seen',
        'provider_flagged',
        'source_hidden',
        'target_folder_id',
        'target_placement_id',
        'target_imap_uid',
        'target_uid_authoritative',
        'imap_uid',
        'imap_uid_validity',
        'uid_validity',
        'uid_next',
        'exists_count',
        'unseen_count',
        'highest_modseq',
        'remote_id',
        'special_use',
        'role',
        'is_selectable',
        'sync_enabled',
        'sync_status',
        'placements_reprojected',
        'folder_hidden_locally',
        'reconciled',
        'provider_state',
        'inverse_of_operation_id',
        'undo_source_snapshot_captured_at',
        'undo_verification',
        'verified',
        'original_source_absent',
        'target_uid_validity',
    ];

    /** @return array<string, mixed> */
    public function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (! in_array((string) $key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            $sanitized[(string) $key] = $this->sanitizeValue($value);
        }

        return $sanitized;
    }

    public function message(?string $message): ?string
    {
        $message = trim((string) $message);
        if ($message === '') {
            return null;
        }

        $message = preg_replace(
            '/\b(password|passwd|secret|token|authorization|bearer)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $message,
        ) ?? $message;

        return Str::limit($message, 1000, '');
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/password|secret|token|authorization|body|mime|attachment/i', $key)) {
                    continue;
                }

                $result[$key] = $this->sanitizeValue($item);
            }

            return $result;
        }

        if (is_string($value)) {
            return Str::limit($value, 1000, '');
        }

        return is_scalar($value) || $value === null ? $value : (string) $value;
    }
}
