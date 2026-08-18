<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailCanonicalMessage;
use App\Modules\Email\Models\EmailMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Produces the stricter, local-file-backed evidence required for physical canonical projection.
 * Shadow correlation is intentionally insufficient: this contract compares every projected field
 * and verifies the actual raw and attachment bytes before two source occurrences may share content.
 */
final class EmailCanonicalCutoverEvidence
{
    public const ALGORITHM_VERSION = 'canonical-cutover-v1';

    private const MAX_BODY_FIELD_BYTES = 2 * 1024 * 1024;

    private const MAX_STRUCTURED_FIELD_BYTES = 2 * 1024 * 1024;

    private const MAX_STRUCTURED_DEPTH = 24;

    private const MAX_STRUCTURED_NODES = 10_000;

    private const MAX_STRUCTURED_ENTRIES = 5_000;

    private const MAX_RAW_BYTES = 32 * 1024 * 1024;

    private const MAX_ATTACHMENT_BYTES = 32 * 1024 * 1024;

    private const MAX_TOTAL_FILE_BYTES = 64 * 1024 * 1024;

    private const MAX_ATTACHMENTS = 100;

    public function __construct(private readonly EmailProviderMessageIdentity $providerIdentity) {}

    /**
     * @return array{
     *   strict_evidence_hash:string,
     *   root_projection_hash:string,
     *   source_state_hash:string,
     *   complete:bool,
     *   evidence_bytes:int,
     *   reason_codes:list<string>,
     *   projection:array<string,mixed>,
     *   attachments:list<array<string,mixed>>
     * }
     */
    public function forMessage(EmailMessage $message): array
    {
        $message->loadMissing(['account:id,address', 'attachments']);

        $reasons = [];
        $messageId = $this->providerIdentity->normalizeMessageId($message->message_id);
        if ($messageId === ''
            || strlen($messageId) > 255
            || preg_match('/\A[^<>\s@]+@[^<>\s@]+\z/u', $messageId) !== 1) {
            $messageId = null;
            $reasons[] = 'message_id_missing_or_malformed';
        }

        $fromEmail = $this->normalizeAddress($message->from_email);
        if ($fromEmail === null) {
            $reasons[] = 'sender_missing_or_malformed';
        }

        $accountAddress = $this->normalizeAddress($message->account?->address);
        $direction = $accountAddress !== null && $fromEmail !== null
            ? (hash_equals($accountAddress, $fromEmail) ? 'outbound' : 'inbound')
            : null;
        if ($direction === null) {
            $reasons[] = 'direction_incomplete';
        }

        [$to, $toComplete, $toLimitExceeded] = $this->canonicalJson($message->to_json);
        [$cc, $ccComplete, $ccLimitExceeded] = $this->canonicalJson($message->cc_json);
        [$headers, $headersComplete, $headersLimitExceeded] = $this->canonicalJson($message->headers_json);
        $toBytes = $this->encodedBytes($to);
        $ccBytes = $this->encodedBytes($cc);
        $headerBytes = $this->encodedBytes($headers);
        if (! $toComplete || ! $ccComplete || ! $headersComplete) {
            $reasons[] = 'json_evidence_incomplete';
        }
        if ($toLimitExceeded || $ccLimitExceeded || $headersLimitExceeded) {
            $reasons[] = 'structured_evidence_complexity_exceeded';
        }
        if ($toBytes > self::MAX_STRUCTURED_FIELD_BYTES
            || $ccBytes > self::MAX_STRUCTURED_FIELD_BYTES
            || $headerBytes > self::MAX_STRUCTURED_FIELD_BYTES) {
            $reasons[] = 'structured_evidence_too_large';
        }

        $receivedAt = $this->dateKey($message->received_at);
        if ($receivedAt === null) {
            $reasons[] = 'received_at_missing';
        }

        $bodyText = $message->body_text;
        $bodyHtml = $message->getRawOriginal('body_html_sanitized');
        if (($bodyText !== null && strlen((string) $bodyText) > self::MAX_BODY_FIELD_BYTES)
            || ($bodyHtml !== null && strlen((string) $bodyHtml) > self::MAX_BODY_FIELD_BYTES)) {
            $reasons[] = 'body_evidence_too_large';
        }

        $storedChecksum = Str::lower(trim((string) $message->checksum_sha1));
        if (preg_match('/\A[0-9a-f]{40}\z/', $storedChecksum) !== 1) {
            $storedChecksum = null;
            $reasons[] = 'content_checksum_missing_or_malformed';
        }

        $raw = $this->localFileEvidence(
            (string) $message->raw_path,
            'email/raw/',
            self::MAX_RAW_BYTES,
        );
        if (! $raw['complete']) {
            $reasons[] = 'raw_source_incomplete';
        }

        $attachments = $this->attachmentEvidence($message);
        array_push($reasons, ...$attachments['reason_codes']);

        if (($raw['size_bytes'] ?? 0) + $attachments['total_bytes'] > self::MAX_TOTAL_FILE_BYTES) {
            $reasons[] = 'aggregate_file_evidence_too_large';
        }

        $projection = [
            'normalized_message_id' => $messageId,
            'message_id' => $this->nullableString($message->message_id),
            'subject' => $this->nullableString($message->subject),
            'from_name' => $this->nullableString($message->from_name),
            'from_email' => $fromEmail,
            'to_json' => $to,
            'cc_json' => $cc,
            'headers_json' => $headers,
            'in_reply_to' => $this->nullableString($message->in_reply_to),
            'references' => $this->nullableString($message->references),
            'direction' => $direction,
            'received_at' => $receivedAt,
            'size_bytes' => $message->size_bytes === null ? null : (int) $message->size_bytes,
            'is_oversize' => (bool) $message->is_oversize,
            'body_html_sanitized' => $bodyHtml === null ? null : (string) $bodyHtml,
            'body_text' => $bodyText === null ? null : (string) $bodyText,
            'raw_path' => $raw['complete'] ? (string) $message->raw_path : $this->nullableString($message->raw_path),
            'raw_source_sha256' => $raw['sha256'],
            'attachments_count' => (int) $message->attachments_count,
            'checksum_sha1' => $storedChecksum,
        ];

        $materializedBytes = $toBytes
            + $ccBytes
            + $headerBytes
            + strlen((string) $bodyText)
            + strlen((string) $bodyHtml)
            + collect([
                $messageId,
                $message->message_id,
                $message->subject,
                $message->from_name,
                $fromEmail,
                $message->in_reply_to,
                $message->references,
                $message->raw_path,
                $storedChecksum,
            ])->sum(fn (mixed $value): int => strlen((string) $value))
            + (int) ($raw['size_bytes'] ?? 0)
            + $attachments['total_bytes'];

        $comparisonFacts = $projection;
        unset($comparisonFacts['message_id'], $comparisonFacts['raw_path']);
        $comparisonFacts['raw_size_bytes'] = $raw['size_bytes'];
        $comparisonFacts['attachments'] = $attachments['comparison_facts'];

        $reasons = collect($reasons)->unique()->sort()->values()->all();
        $complete = $reasons === [];
        $strictHash = $this->hash([
            'algorithm' => self::ALGORITHM_VERSION,
            'complete' => $complete,
            'reason_codes' => $reasons,
            'facts' => $comparisonFacts,
        ]);

        $projectionAttachments = $attachments['projection_attachments'];
        $projectionHash = $this->hash([
            'algorithm' => self::ALGORITHM_VERSION,
            'root_source_email_message_id' => (int) $message->id,
            'projection' => $projection,
            'attachments' => $projectionAttachments,
        ]);

        return [
            'strict_evidence_hash' => $strictHash,
            'root_projection_hash' => $projectionHash,
            'source_state_hash' => $this->storedSourceStateHash($message),
            'complete' => $complete,
            'evidence_bytes' => $materializedBytes,
            'reason_codes' => $reasons,
            'projection' => $projection,
            'attachments' => $projectionAttachments,
        ];
    }

    /**
     * Cheap DB-state drift guard for ordinary body/list reads. Raw and attachment endpoints still
     * request the full actual-file pass before using a canonical private-file reference.
     */
    public function storedSourceStateHash(EmailMessage $message): string
    {
        $message->loadMissing(['account:id,address', 'attachments']);
        $attributes = [];
        foreach ([
            'message_id', 'subject', 'from_name', 'from_email', 'to_json', 'cc_json',
            'headers_json', 'in_reply_to', 'references', 'received_at', 'size_bytes',
            'is_oversize', 'body_html_sanitized', 'body_text', 'raw_path',
            'attachments_count', 'checksum_sha1',
        ] as $field) {
            $value = in_array($field, ['to_json', 'cc_json', 'headers_json'], true)
                ? $this->canonicalJson($message->{$field})[0]
                : ($field === 'received_at'
                    ? $this->dateKey($message->received_at)
                    : $message->getRawOriginal($field));
            $attributes[$field] = $value;
        }
        $attributes['account_address'] = Str::lower(trim((string) $message->account?->address));

        $attachments = $message->attachments
            ->sortBy('id')
            ->values()
            ->map(fn (EmailAttachment $attachment): array => [
                'id' => (int) $attachment->id,
                'filename' => $attachment->filename,
                'content_type' => $attachment->content_type,
                'size_bytes' => $attachment->size_bytes === null ? null : (int) $attachment->size_bytes,
                'disk' => $attachment->disk,
                'path' => $attachment->path,
                'is_inline' => (bool) $attachment->is_inline,
                'cid' => $attachment->cid,
                'checksum_sha1' => $attachment->checksum_sha1,
            ])
            ->all();

        return $this->hash([
            'algorithm' => self::ALGORITHM_VERSION,
            'source_email_message_id' => (int) $message->id,
            'attributes' => $attributes,
            'attachments' => $attachments,
        ]);
    }

    /**
     * Rebuild the hash of the stored canonical projection itself. This detects a direct projection
     * mutation independently from drift in its root source or another mapped source.
     */
    public function storedProjectionHash(EmailCanonicalMessage $canonical): string
    {
        $canonical->loadMissing('attachments');

        $projection = [
            'normalized_message_id' => $canonical->normalized_message_id,
            'message_id' => $canonical->message_id,
            'subject' => $canonical->subject,
            'from_name' => $canonical->from_name,
            'from_email' => $canonical->from_email,
            'to_json' => $this->canonicalJson($canonical->to_json)[0],
            'cc_json' => $this->canonicalJson($canonical->cc_json)[0],
            'headers_json' => $this->canonicalJson($canonical->headers_json)[0],
            'in_reply_to' => $canonical->in_reply_to,
            'references' => $canonical->references,
            'direction' => $canonical->direction,
            'received_at' => $this->dateKey($canonical->received_at),
            'size_bytes' => $canonical->size_bytes === null ? null : (int) $canonical->size_bytes,
            'is_oversize' => (bool) $canonical->is_oversize,
            'body_html_sanitized' => $canonical->getRawOriginal('body_html_sanitized'),
            'body_text' => $canonical->body_text,
            'raw_path' => $canonical->raw_path,
            'raw_source_sha256' => $canonical->raw_source_sha256,
            'attachments_count' => (int) $canonical->attachments_count,
            'checksum_sha1' => $canonical->checksum_sha1,
        ];

        $attachments = $canonical->attachments
            ->sortBy('position')
            ->values()
            ->map(fn ($attachment): array => [
                'source_email_attachment_id' => (int) $attachment->source_email_attachment_id,
                'filename' => $attachment->filename,
                'content_type' => $attachment->content_type,
                'size_bytes' => (int) $attachment->size_bytes,
                'disk' => $attachment->disk,
                'path' => $attachment->path,
                'is_inline' => (bool) $attachment->is_inline,
                'cid' => $attachment->cid,
                'checksum_sha1' => $attachment->checksum_sha1,
                'actual_sha256' => $attachment->actual_sha256,
            ])
            ->all();

        return $this->hash([
            'algorithm' => self::ALGORITHM_VERSION,
            'root_source_email_message_id' => (int) $canonical->root_source_email_message_id,
            'projection' => $projection,
            'attachments' => $attachments,
        ]);
    }

    /** @param array<string,mixed> $left
     * @param  array<string,mixed>  $right
     */
    public function exactlyEquivalent(array $left, array $right): bool
    {
        return ($left['complete'] ?? false) === true
            && ($right['complete'] ?? false) === true
            && hash_equals(
                (string) ($left['strict_evidence_hash'] ?? ''),
                (string) ($right['strict_evidence_hash'] ?? ''),
            );
    }

    /**
     * @return array{
     *   comparison_facts:list<array<string,mixed>>,
     *   projection_attachments:list<array<string,mixed>>,
     *   reason_codes:list<string>,
     *   total_bytes:int
     * }
     */
    private function attachmentEvidence(EmailMessage $message): array
    {
        $rows = $message->attachments;
        $expected = max(0, (int) $message->attachments_count);
        $reasons = [];
        if ($expected > self::MAX_ATTACHMENTS || $rows->count() > self::MAX_ATTACHMENTS) {
            $reasons[] = 'attachment_count_too_large';
        }
        if ($expected !== $rows->count()) {
            $reasons[] = 'attachment_count_mismatch';
        }

        $prepared = [];
        $totalBytes = 0;
        foreach ($rows->take(self::MAX_ATTACHMENTS) as $attachment) {
            $file = $this->localFileEvidence(
                (string) $attachment->path,
                'email/attachments/',
                self::MAX_ATTACHMENT_BYTES,
            );
            $declaredSha1 = Str::lower(trim((string) $attachment->checksum_sha1));
            $declaredSize = filter_var($attachment->size_bytes, FILTER_VALIDATE_INT);
            $contentType = $this->nullableString($attachment->content_type);
            $filename = $this->nullableString($attachment->filename);

            if (! $file['complete']) {
                $reasons[] = 'attachment_file_incomplete';
            }
            if (($attachment->disk ?: EmailPrivateStorage::DISK) !== EmailPrivateStorage::DISK) {
                $reasons[] = 'attachment_disk_not_private_local';
            }
            if ($filename === null || $contentType === null) {
                $reasons[] = 'attachment_metadata_incomplete';
            }
            if ($declaredSize === false || $declaredSize < 0 || $declaredSize !== $file['size_bytes']) {
                $reasons[] = 'attachment_size_mismatch';
            }
            if (preg_match('/\A[0-9a-f]{40}\z/', $declaredSha1) !== 1
                || $file['sha1'] === null
                || ! hash_equals($declaredSha1, $file['sha1'])) {
                $reasons[] = 'attachment_checksum_mismatch';
            }

            $totalBytes += (int) ($file['size_bytes'] ?? 0);
            $fact = [
                'filename' => $filename,
                'content_type' => $contentType === null ? null : Str::lower($contentType),
                'size_bytes' => $declaredSize === false ? null : $declaredSize,
                'is_inline' => (bool) $attachment->is_inline,
                'cid' => $this->nullableString($attachment->cid),
                'checksum_sha1' => preg_match('/\A[0-9a-f]{40}\z/', $declaredSha1) === 1
                    ? $declaredSha1
                    : null,
                'actual_sha256' => $file['sha256'],
            ];
            $prepared[] = [
                'sort_key' => $this->hash($fact),
                'source_id' => (int) $attachment->id,
                'fact' => $fact,
                'projection' => [
                    'source_email_attachment_id' => (int) $attachment->id,
                    'filename' => (string) $attachment->filename,
                    'content_type' => $attachment->content_type,
                    'size_bytes' => $declaredSize === false ? 0 : $declaredSize,
                    'disk' => (string) ($attachment->disk ?: EmailPrivateStorage::DISK),
                    'path' => (string) $attachment->path,
                    'is_inline' => (bool) $attachment->is_inline,
                    'cid' => $attachment->cid,
                    'checksum_sha1' => $declaredSha1,
                    'actual_sha256' => (string) ($file['sha256'] ?? str_repeat('0', 64)),
                ],
            ];
        }

        usort($prepared, fn (array $left, array $right): int => [
            $left['sort_key'],
            $left['source_id'],
        ] <=> [
            $right['sort_key'],
            $right['source_id'],
        ]);

        return [
            'comparison_facts' => array_values(array_map(
                fn (array $item): array => $item['fact'],
                $prepared,
            )),
            'projection_attachments' => array_values(array_map(
                fn (array $item): array => $item['projection'],
                $prepared,
            )),
            'reason_codes' => collect($reasons)->unique()->sort()->values()->all(),
            'total_bytes' => $totalBytes,
        ];
    }

    /** @return array{complete:bool,sha1:?string,sha256:?string,size_bytes:?int} */
    private function localFileEvidence(string $path, string $prefix, int $maxBytes): array
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === ''
            || str_starts_with($path, '/')
            || ! str_starts_with($path, $prefix)
            || str_contains($path, '/../')
            || str_ends_with($path, '/..')) {
            return $this->incompleteFile();
        }

        $segments = explode('/', $path);
        if (in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)) {
            return $this->incompleteFile();
        }

        try {
            $disk = Storage::disk(EmailPrivateStorage::DISK);
            if (! $disk->exists($path)) {
                return $this->incompleteFile();
            }

            $root = realpath($disk->path(rtrim($prefix, '/')));
            $candidate = $disk->path($path);
            $real = realpath($candidate);
            if ($root === false
                || $real === false
                || is_link($candidate)
                || ! is_file($real)
                || ! str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                return $this->incompleteFile();
            }

            $size = filesize($real);
            if ($size === false || $size > $maxBytes) {
                return $this->incompleteFile();
            }

            $sha1 = hash_file('sha1', $real);
            $sha256 = hash_file('sha256', $real);
            if ($sha1 === false || $sha256 === false) {
                return $this->incompleteFile();
            }

            return [
                'complete' => true,
                'sha1' => $sha1,
                'sha256' => $sha256,
                'size_bytes' => $size,
            ];
        } catch (Throwable) {
            return $this->incompleteFile();
        }
    }

    /** @return array{complete:false,sha1:null,sha256:null,size_bytes:null} */
    private function incompleteFile(): array
    {
        return [
            'complete' => false,
            'sha1' => null,
            'sha256' => null,
            'size_bytes' => null,
        ];
    }

    /** @return array{0:mixed,1:bool,2:bool} */
    private function canonicalJson(mixed $value): array
    {
        $state = ['nodes' => 0, 'entries' => 0, 'limit_exceeded' => false];

        [$normalized, $complete] = $this->normalizeCanonicalJson($value, 0, $state);

        return [$normalized, $complete, $state['limit_exceeded']];
    }

    /**
     * Normalize historic JSON under explicit structural limits. The limit is enforced while
     * walking the value so deeply nested or very wide headers cannot consume unbounded stack or
     * memory before the later encoded-byte check gets a chance to reject them.
     *
     * @param  array{nodes:int,entries:int,limit_exceeded:bool}  $state
     * @return array{0:mixed,1:bool}
     */
    private function normalizeCanonicalJson(mixed $value, int $depth, array &$state): array
    {
        $state['nodes']++;
        if ($depth > self::MAX_STRUCTURED_DEPTH || $state['nodes'] > self::MAX_STRUCTURED_NODES) {
            $state['limit_exceeded'] = true;

            return [null, false];
        }

        if ($value === null || is_scalar($value)) {
            return [$value, true];
        }

        if (! is_array($value)) {
            return [null, false];
        }

        $state['entries'] += count($value);
        if (count($value) > self::MAX_STRUCTURED_ENTRIES
            || $state['entries'] > self::MAX_STRUCTURED_ENTRIES) {
            $state['limit_exceeded'] = true;

            return [null, false];
        }

        $complete = true;
        if (array_is_list($value)) {
            $normalized = [];
            foreach ($value as $entry) {
                [$entry, $entryComplete] = $this->normalizeCanonicalJson($entry, $depth + 1, $state);
                $complete = $complete && $entryComplete;
                $normalized[] = $entry;
            }

            return [$normalized, $complete];
        }

        ksort($value, SORT_STRING);
        $normalized = [];
        foreach ($value as $key => $entry) {
            if (! is_string($key) && ! is_int($key)) {
                $complete = false;

                continue;
            }

            [$entry, $entryComplete] = $this->normalizeCanonicalJson($entry, $depth + 1, $state);
            $complete = $complete && $entryComplete;
            $normalized[(string) $key] = $entry;
        }

        return [$normalized, $complete];
    }

    private function normalizeAddress(mixed $value): ?string
    {
        $value = Str::lower(trim((string) $value));

        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $value, $matches) === 1) {
            $value = $matches[1];
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function dateKey(mixed $value): ?string
    {
        return $value instanceof CarbonInterface
            ? $value->clone()->utc()->format('Y-m-d\TH:i:s.u\Z')
            : null;
    }

    private function encodedBytes(mixed $value): int
    {
        return strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
