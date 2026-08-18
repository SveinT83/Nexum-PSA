<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailCanonicalCorrelationCandidate;
use App\Modules\Email\Models\EmailMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmailCanonicalCorrelationEvidence
{
    public const ALGORITHM_VERSION = 'v1';

    private const DELIVERY_TOLERANCE_SECONDS = 300;

    private const MAX_RAW_EVIDENCE_BYTES = 32 * 1024 * 1024;

    private const MAX_BODY_EVIDENCE_BYTES = 2 * 1024 * 1024;

    private const MAX_ATTACHMENT_EVIDENCE_COUNT = 100;

    public function __construct(private readonly EmailProviderMessageIdentity $providerIdentity) {}

    public function discoveryKey(EmailMessage $message, string $phase): ?string
    {
        if ($phase === 'message_id') {
            $messageId = $this->providerIdentity->normalizeMessageId($message->message_id);

            return $messageId !== '' && strlen($messageId) <= 255
                && preg_match('/\A[^<>\s@]+@[^<>\s@]+\z/u', $messageId) === 1
                    ? hash('sha256', $messageId)
                    : null;
        }

        if ($phase === 'checksum') {
            $checksum = Str::lower(trim((string) $message->checksum_sha1));

            return preg_match('/\A[0-9a-f]{40}\z/', $checksum) === 1
                ? hash('sha256', $checksum)
                : null;
        }

        return null;
    }

    /**
     * Build temporary normalized evidence. Callers must persist only the hashes and reason codes
     * returned by compare(); mail addresses, headers, names and content never belong in shadow rows.
     *
     * @return array<string, mixed>
     */
    public function forMessage(EmailMessage $message): array
    {
        $message->loadMissing(['account:id,address', 'attachments']);

        $messageId = $this->providerIdentity->normalizeMessageId($message->message_id);
        $messageIdValid = $messageId !== ''
            && strlen($messageId) <= 255
            && preg_match('/\A[^<>\s@]+@[^<>\s@]+\z/u', $messageId) === 1;
        $sender = $this->normalizeAddress($message->from_email);
        $recipients = $this->recipients($message);
        $direction = $this->direction($message, $sender);
        $receivedAt = $message->received_at instanceof CarbonInterface
            ? $message->received_at->clone()->utc()->getTimestamp()
            : null;
        $contentHash = $this->contentHash($message);
        $rawSource = $this->rawSourceEvidence($message);
        $attachments = $this->attachmentEvidence($message);

        $facts = [
            'algorithm' => self::ALGORITHM_VERSION,
            'message_id' => $messageIdValid ? $messageId : null,
            'sender' => $sender,
            'recipients' => $recipients['values'],
            'recipients_complete' => $recipients['complete'],
            'direction' => $direction,
            'received_at' => $receivedAt,
            'content_hash' => $contentHash,
            'raw_source_hash' => $rawSource['hash'],
            'raw_source_complete' => $rawSource['complete'],
            'attachment_hash' => $attachments['hash'],
            'attachments_complete' => $attachments['complete'],
        ];

        return [
            ...$facts,
            'evidence_hash' => hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR)),
            'message_id_valid' => $messageIdValid,
        ];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array{candidate_class:string,reason_codes:list<string>,left_evidence_hash:string,right_evidence_hash:string,pair_fingerprint:string}
     */
    public function compare(array $left, array $right): array
    {
        $reasons = [];
        $conflicts = [];
        $missing = [];

        if (! $left['message_id_valid'] || ! $right['message_id_valid']) {
            $missing[] = 'message_id_missing_or_malformed';
        } elseif ($left['message_id'] !== $right['message_id']) {
            $conflicts[] = 'message_id_conflict';
        } else {
            $reasons[] = 'message_id_match';
        }

        $this->compareRequired('sender', $left, $right, $reasons, $conflicts, $missing);
        $this->compareRequired('direction', $left, $right, $reasons, $conflicts, $missing);
        $this->compareRequired('content_hash', $left, $right, $reasons, $conflicts, $missing);
        $this->compareRequired('raw_source_hash', $left, $right, $reasons, $conflicts, $missing);
        $this->compareRequired('attachment_hash', $left, $right, $reasons, $conflicts, $missing);

        if (! $left['raw_source_complete'] || ! $right['raw_source_complete']) {
            $missing[] = 'raw_source_incomplete';
        }

        if (! $left['recipients_complete'] || ! $right['recipients_complete']) {
            $missing[] = 'recipients_incomplete';
        } elseif ($left['recipients'] !== $right['recipients']) {
            $conflicts[] = 'recipients_conflict';
        } else {
            $reasons[] = 'recipients_match';
        }

        if (! $left['attachments_complete'] || ! $right['attachments_complete']) {
            $missing[] = 'attachments_incomplete';
        }

        if (! is_int($left['received_at']) || ! is_int($right['received_at'])) {
            $missing[] = 'delivery_time_missing';
        } elseif (abs($left['received_at'] - $right['received_at']) > self::DELIVERY_TOLERANCE_SECONDS) {
            $conflicts[] = 'delivery_time_conflict';
        } else {
            $reasons[] = 'delivery_time_within_tolerance';
        }

        $reasons = collect([...$reasons, ...$conflicts, ...$missing])
            ->unique()
            ->sort()
            ->values()
            ->all();

        $candidateClass = match (true) {
            $conflicts !== [] => EmailCanonicalCorrelationCandidate::CLASS_DIFFERENT,
            $missing !== [] => EmailCanonicalCorrelationCandidate::CLASS_AMBIGUOUS,
            default => EmailCanonicalCorrelationCandidate::CLASS_STRONG,
        };

        // A checksum-discovered pair with no usable Message-ID remains a possible duplicate when
        // every other fact is complete and agrees. It is never promoted to a strong candidate.
        if ($candidateClass === EmailCanonicalCorrelationCandidate::CLASS_AMBIGUOUS
            && $missing === ['message_id_missing_or_malformed']) {
            $candidateClass = EmailCanonicalCorrelationCandidate::CLASS_POSSIBLE;
        }

        $leftHash = (string) $left['evidence_hash'];
        $rightHash = (string) $right['evidence_hash'];

        return [
            'candidate_class' => $candidateClass,
            'reason_codes' => $reasons,
            'left_evidence_hash' => $leftHash,
            'right_evidence_hash' => $rightHash,
            'pair_fingerprint' => hash('sha256', json_encode([
                'algorithm' => self::ALGORITHM_VERSION,
                'candidate_class' => $candidateClass,
                'reason_codes' => $reasons,
                'left' => $leftHash,
                'right' => $rightHash,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  list<string>  $matches
     * @param  list<string>  $conflicts
     * @param  list<string>  $missing
     */
    private function compareRequired(
        string $key,
        array $left,
        array $right,
        array &$matches,
        array &$conflicts,
        array &$missing,
    ): void {
        if (($left[$key] ?? null) === null || ($right[$key] ?? null) === null) {
            $missing[] = $key.'_missing';

            return;
        }

        if ($left[$key] !== $right[$key]) {
            $conflicts[] = $key.'_conflict';

            return;
        }

        $matches[] = $key.'_match';
    }

    /** @return array{values:list<string>,complete:bool} */
    private function recipients(EmailMessage $message): array
    {
        $to = $this->addressesFrom($message->to_json);
        $cc = $this->addressesFrom($message->cc_json);
        $headers = is_array($message->headers_json) ? $message->headers_json : [];
        $bccPresent = array_key_exists('bcc', $headers) || array_key_exists('Bcc', $headers);
        $bcc = $this->addressesFrom(Arr::get($headers, 'bcc', Arr::get($headers, 'Bcc', [])));

        $values = collect([
            ...$to['values'],
            ...$cc['values'],
            ...$bcc['values'],
        ])->filter()->unique()->sort()->values()->all();

        return [
            'values' => $values,
            // Inbound BCC is commonly unavailable. Treat that absence conservatively instead of
            // accepting a cross-recipient delivery variant as complete evidence.
            'complete' => $to['complete'] && $cc['complete'] && $bccPresent && $bcc['complete'],
        ];
    }

    /** @return array{values:list<string>,complete:bool} */
    private function addressesFrom(mixed $value): array
    {
        if (is_string($value)) {
            return $this->addressesFromStrings($this->splitAddressList($value));
        }

        if (! is_array($value)) {
            return ['values' => [], 'complete' => false];
        }

        $addresses = [];
        $complete = true;
        foreach ($value as $key => $entry) {
            if (is_string($key) && str_contains($key, '@')) {
                $addresses[] = $key;
            }

            if (is_array($entry)) {
                foreach (['email', 'address', 'mail'] as $field) {
                    if (isset($entry[$field])) {
                        $addresses[] = $entry[$field];
                    }
                }
                if (! collect(['email', 'address', 'mail'])->contains(fn (string $field): bool => isset($entry[$field]))) {
                    $complete = false;
                }
            } elseif (is_string($entry)) {
                array_push($addresses, ...$this->splitAddressList($entry));
            } else {
                $complete = false;
            }
        }

        $parsed = $this->addressesFromStrings($addresses);

        return [
            'values' => $parsed['values'],
            'complete' => $complete && $parsed['complete'],
        ];
    }

    /** @param list<mixed> $values
     * @return array{values:list<string>,complete:bool}
     */
    private function addressesFromStrings(array $values): array
    {
        $addresses = [];
        $complete = true;

        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $normalized = $this->normalizeAddress($value);
            if ($normalized === null) {
                $complete = false;

                continue;
            }

            $addresses[] = $normalized;
        }

        return [
            'values' => collect($addresses)->unique()->sort()->values()->all(),
            'complete' => $complete,
        ];
    }

    /**
     * Split an RFC-style address list without silently dropping a quoted comma or angle-bracket
     * address. The parser remains deliberately conservative: every resulting token must normalize
     * to one valid mailbox or the complete-evidence flag is false.
     *
     * @return list<string>
     */
    private function splitAddressList(string $value): array
    {
        $tokens = [];
        $token = '';
        $quoted = false;
        $escaped = false;
        $angleDepth = 0;

        foreach (mb_str_split($value) as $character) {
            if ($escaped) {
                $token .= $character;
                $escaped = false;

                continue;
            }

            if ($character === '\\' && $quoted) {
                $token .= $character;
                $escaped = true;

                continue;
            }

            if ($character === '"') {
                $quoted = ! $quoted;
                $token .= $character;

                continue;
            }

            if (! $quoted && $character === '<') {
                $angleDepth++;
            } elseif (! $quoted && $character === '>' && $angleDepth > 0) {
                $angleDepth--;
            }

            if (! $quoted && $angleDepth === 0 && in_array($character, [',', ';'], true)) {
                $tokens[] = $token;
                $token = '';

                continue;
            }

            $token .= $character;
        }

        $tokens[] = $token;

        if ($quoted || $angleDepth !== 0) {
            // Preserve one invalid token so the caller marks the evidence incomplete.
            return [$value];
        }

        return $tokens;
    }

    private function normalizeAddress(mixed $value): ?string
    {
        $value = Str::lower(trim((string) $value));

        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $value, $matches) === 1) {
            $value = $matches[1];
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
    }

    private function direction(EmailMessage $message, ?string $sender): ?string
    {
        $accountAddress = $this->normalizeAddress($message->account?->address);
        if ($accountAddress === null || $sender === null) {
            return null;
        }

        return hash_equals($accountAddress, $sender) ? 'outbound' : 'inbound';
    }

    private function contentHash(EmailMessage $message): ?string
    {
        $stored = Str::lower(trim((string) $message->checksum_sha1));
        if (preg_match('/\A[0-9a-f]{40}\z/', $stored) !== 1) {
            return null;
        }

        $text = $message->body_text;
        $html = $message->getRawOriginal('body_html_sanitized');
        if (($text !== null && strlen((string) $text) > self::MAX_BODY_EVIDENCE_BYTES)
            || ($html !== null && strlen((string) $html) > self::MAX_BODY_EVIDENCE_BYTES)) {
            return null;
        }

        return hash('sha256', json_encode([
            'stored_checksum_sha1' => $stored,
            'body_text_sha256' => $text === null
                ? null
                : hash('sha256', (string) $text),
            'sanitized_html_sha256' => $html === null
                ? null
                : hash('sha256', (string) $html),
            'size_bytes' => $message->size_bytes,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{hash:?string,complete:bool} */
    private function rawSourceEvidence(EmailMessage $message): array
    {
        $path = trim((string) $message->raw_path);
        if ($path === '' || ! str_starts_with(str_replace('\\', '/', $path), 'email/raw/')) {
            return ['hash' => null, 'complete' => false];
        }

        try {
            $disk = Storage::disk('local');
            if (! $disk->exists($path)) {
                return ['hash' => null, 'complete' => false];
            }

            $absolute = $disk->path($path);
            $root = realpath($disk->path('email/raw'));
            $real = realpath($absolute);
            if ($root === false
                || $real === false
                || (! str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))
                || ! is_file($real)
                || is_link($real)
                || filesize($real) === false
                || filesize($real) > self::MAX_RAW_EVIDENCE_BYTES) {
                return ['hash' => null, 'complete' => false];
            }

            $hash = hash_file('sha256', $real);

            return $hash === false
                ? ['hash' => null, 'complete' => false]
                : ['hash' => $hash, 'complete' => true];
        } catch (\Throwable) {
            return ['hash' => null, 'complete' => false];
        }
    }

    /** @return array{hash:?string,complete:bool} */
    private function attachmentEvidence(EmailMessage $message): array
    {
        $expected = max(0, (int) $message->attachments_count);
        $attachments = $message->attachments;
        if ($expected > self::MAX_ATTACHMENT_EVIDENCE_COUNT
            || $attachments->count() > self::MAX_ATTACHMENT_EVIDENCE_COUNT
            || $attachments->count() !== $expected) {
            return ['hash' => null, 'complete' => false];
        }

        $facts = $attachments
            ->map(function (EmailAttachment $attachment): ?array {
                $checksum = Str::lower(trim((string) $attachment->checksum_sha1));
                if (preg_match('/\A[0-9a-f]{40}\z/', $checksum) !== 1) {
                    return null;
                }

                $filename = trim((string) $attachment->filename);
                $contentType = Str::lower(trim((string) $attachment->content_type));
                $sizeBytes = filter_var($attachment->size_bytes, FILTER_VALIDATE_INT);
                if ($filename === '' || $contentType === '' || $sizeBytes === false || $sizeBytes < 0) {
                    return null;
                }

                return [
                    'checksum_sha1' => $checksum,
                    'size_bytes' => $sizeBytes,
                    'content_type' => $contentType,
                    'filename_hash' => hash('sha256', Str::lower($filename)),
                    'inline' => (bool) $attachment->is_inline,
                    'cid_hash' => filled($attachment->cid)
                        ? hash('sha256', Str::lower(trim((string) $attachment->cid)))
                        : null,
                ];
            });

        if ($facts->contains(null)) {
            return ['hash' => null, 'complete' => false];
        }

        $sorted = $facts
            ->sortBy(fn (array $fact): string => json_encode($fact, JSON_THROW_ON_ERROR))
            ->values()
            ->all();

        return [
            'hash' => hash('sha256', json_encode($sorted, JSON_THROW_ON_ERROR)),
            'complete' => true,
        ];
    }
}
