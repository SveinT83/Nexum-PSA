<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Builds and verifies the exact bounded local message/evidence snapshot for a shadow run.
 * The digest contains only identifiers and one-way hashes; no mail content is persisted.
 */
class EmailCanonicalCorrelationScope
{
    public const SNAPSHOT_BYTE_CAP = 64 * 1024 * 1024;

    public const RUN_BYTE_CAP = 256 * 1024 * 1024;

    public function __construct(private readonly EmailCanonicalCorrelationEvidence $evidence) {}

    /**
     * @param  list<int>  $accountIds
     * @return array{count:int,message_digest:string,evidence_bytes:int,exceeded:bool}
     */
    public function snapshot(
        array $accountIds,
        int $minimumMessageId,
        int $maximumMessageId,
        int $byteCap = self::SNAPSHOT_BYTE_CAP,
    ): array {
        $evidenceBytes = $this->scopeEvidenceBytes(
            $accountIds,
            $minimumMessageId,
            $maximumMessageId,
            $byteCap,
        );
        if ($evidenceBytes === null) {
            return [
                'count' => 0,
                'message_digest' => '',
                'evidence_bytes' => 0,
                'exceeded' => true,
            ];
        }

        $query = EmailMessage::query()
            ->whereIn('account_id', $accountIds)
            ->whereBetween('id', [$minimumMessageId, $maximumMessageId])
            ->select([
                'id',
                'account_id',
                'message_id',
                'from_email',
                'to_json',
                'cc_json',
                'headers_json',
                'received_at',
                'checksum_sha1',
                'body_text',
                'body_html_sanitized',
                'size_bytes',
                'attachments_count',
                'raw_path',
                'ticket_id',
            ])
            ->with([
                'account:id,address',
                'attachments',
                'placements' => fn (HasMany $placements): HasMany => $placements
                    ->select(['id', 'email_message_id', 'email_conversation_id'])
                    ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                    ->whereNull('provider_missing_at'),
            ])
            ->orderBy('id');

        $context = hash_init('sha256');
        $count = 0;
        // The lightweight byte preflight above bounds the whole selected payload, so a normal
        // relation-aware chunk no longer risks loading an oversized body set into memory.
        foreach ($query->lazyById(25) as $message) {
            $fact = [
                'id' => (int) $message->id,
                'account_id' => (int) $message->account_id,
                'evidence_hash' => $this->evidence->forMessage($message)['evidence_hash'],
                'ticket_id' => $message->ticket_id === null ? null : (int) $message->ticket_id,
                'conversation_ids' => $message->placements
                    ->pluck('email_conversation_id')
                    ->filter()
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
            ];
            hash_update($context, json_encode($fact, JSON_THROW_ON_ERROR)."\n");
            $count++;
        }

        return [
            'count' => $count,
            'message_digest' => hash_final($context),
            'evidence_bytes' => $evidenceBytes,
            'exceeded' => false,
        ];
    }

    /**
     * Count every body/header/raw/attachment byte that can be examined by the evidence builder.
     * This estimate is deliberately conservative and is used before raw files are hashed.
     */
    public function estimateMessageEvidenceBytes(EmailMessage $message): int
    {
        $bytes = 512;
        foreach ([
            $message->message_id,
            $message->from_email,
            $message->to_json,
            $message->cc_json,
            $message->headers_json,
            $message->body_text,
            $message->getRawOriginal('body_html_sanitized'),
            $message->checksum_sha1,
        ] as $value) {
            $serialized = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                : (string) $value;
            $bytes += strlen($serialized === false ? '' : $serialized);
        }

        foreach ($message->attachments as $attachment) {
            $bytes += strlen((string) $attachment->filename)
                + strlen((string) $attachment->content_type)
                + strlen((string) $attachment->checksum_sha1)
                + strlen((string) $attachment->cid)
                + 32;
        }

        $bytes += $this->rawEvidenceFileSize((string) $message->raw_path);

        return $bytes;
    }

    /**
     * Compute the aggregate evidence input before loading or hashing any body/raw content.
     * Returning null is a fail-closed over-budget result.
     *
     * @param  list<int>  $accountIds
     */
    private function scopeEvidenceBytes(
        array $accountIds,
        int $minimumMessageId,
        int $maximumMessageId,
        int $byteCap,
    ): ?int {
        $messageBytes = (int) (EmailMessage::query()
            ->whereIn('account_id', $accountIds)
            ->whereBetween('id', [$minimumMessageId, $maximumMessageId])
            ->selectRaw(implode(' + ', [
                'COALESCE(SUM(LENGTH(COALESCE(message_id, \'\'))), 0)',
                'COALESCE(SUM(LENGTH(COALESCE(from_email, \'\'))), 0)',
                'COALESCE(SUM(LENGTH(COALESCE(to_json, \'\'))), 0)',
                'COALESCE(SUM(LENGTH(COALESCE(cc_json, \'\'))), 0)',
                'COALESCE(SUM(LENGTH(COALESCE(headers_json, \'\'))), 0)',
                'COALESCE(SUM(LENGTH(COALESCE(body_text, \'\'))), 0)',
                'COALESCE(SUM(LENGTH(COALESCE(body_html_sanitized, \'\'))), 0)',
                'COALESCE(SUM(LENGTH(COALESCE(checksum_sha1, \'\'))), 0)',
                'COUNT(*) * 512',
            ]).' AS aggregate')
            ->value('aggregate') ?? 0);
        if ($messageBytes > $byteCap) {
            return null;
        }

        $attachmentBytes = (int) (DB::table('email_attachments')
            ->join('email_messages', 'email_messages.id', '=', 'email_attachments.message_id')
            ->whereIn('email_messages.account_id', $accountIds)
            ->whereBetween('email_messages.id', [$minimumMessageId, $maximumMessageId])
            ->selectRaw(implode(' + ', [
                'COALESCE(SUM(LENGTH(COALESCE(email_attachments.filename, \'\'))), 0)',
                'COALESCE(SUM(LENGTH(COALESCE(email_attachments.content_type, \'\'))), 0)',
                'COALESCE(SUM(LENGTH(COALESCE(email_attachments.checksum_sha1, \'\'))), 0)',
                'COALESCE(SUM(LENGTH(COALESCE(email_attachments.cid, \'\'))), 0)',
                'COUNT(*) * 32',
            ]).' AS aggregate')
            ->value('aggregate') ?? 0);
        if ($attachmentBytes > $byteCap - $messageBytes) {
            return null;
        }

        $bytes = $messageBytes + $attachmentBytes;
        foreach (EmailMessage::query()
            ->whereIn('account_id', $accountIds)
            ->whereBetween('id', [$minimumMessageId, $maximumMessageId])
            ->select(['id', 'raw_path'])
            ->orderBy('id')
            ->lazyById(100) as $message) {
            $rawBytes = $this->rawEvidenceFileSize((string) $message->raw_path);
            if ($rawBytes > $byteCap - $bytes) {
                return null;
            }
            $bytes += $rawBytes;
        }

        return $bytes;
    }

    private function rawEvidenceFileSize(string $path): int
    {
        $path = trim($path);
        if ($path === '' || ! str_starts_with(str_replace('\\', '/', $path), 'email/raw/')) {
            return 0;
        }

        try {
            $disk = Storage::disk('local');
            if (! $disk->exists($path)) {
                return 0;
            }

            $absolute = $disk->path($path);
            $root = realpath($disk->path('email/raw'));
            $real = realpath($absolute);
            if ($root === false
                || $real === false
                || ! str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
                || ! is_file($real)
                || is_link($real)) {
                return 0;
            }

            $size = filesize($real);

            return $size === false ? 0 : max(0, $size);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  list<int>  $accountIds
     * @param  array{message_cap:int,group_cap:int,pair_cap:int,per_group_cap:int,evidence_snapshot_byte_cap:int,evidence_run_byte_cap:int}  $caps
     */
    public function fingerprint(
        array $accountIds,
        int $minimumMessageId,
        int $maximumMessageId,
        array $caps,
        string $messageDigest,
    ): string {
        return hash('sha256', json_encode([
            'algorithm' => EmailCanonicalCorrelationEvidence::ALGORITHM_VERSION,
            'accounts' => $accountIds,
            'frozen_min_message_id' => $minimumMessageId,
            'frozen_max_message_id' => $maximumMessageId,
            'caps' => $caps,
            'message_digest' => $messageDigest,
        ], JSON_THROW_ON_ERROR));
    }
}
