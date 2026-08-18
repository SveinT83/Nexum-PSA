<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\DTOs\InboundAttachmentPersistenceResult;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Support\InboundAttachmentPolicy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Persists accepted attachment files and metadata while isolating per-file failures.
 */
final class InboundAttachmentPersister
{
    public function __construct(
        private readonly InboundAttachmentPolicy $policy,
        private readonly EmailPrivateStorage $privateStorage,
    ) {}

    public function persist(EmailMessage $message, iterable $attachments): int
    {
        return $this->persistWithResult($message, $attachments)->persistedCount;
    }

    /**
     * Persist one exact provider attachment set and retain only content-free
     * outcome evidence. Reconciliation callers use this stronger result so a
     * policy rejection can complete while an allowed-item I/O failure retries.
     */
    public function persistWithResult(
        EmailMessage $message,
        iterable $attachments,
        bool $referencePathBeforeWrite = false,
    ): InboundAttachmentPersistenceResult {
        $persisted = 0;
        $policyRejected = 0;
        $failed = 0;
        $failureCodes = [];
        $countLimitReached = false;
        $position = 0;

        foreach ($attachments as $attachment) {
            $position++;

            if ($position > $this->policy->maxCount()) {
                $policyRejected++;
                $countLimitReached = true;
                Log::notice('Inbound attachment count limit reached; remaining attachments were skipped.', [
                    'email_message_id' => $message->id,
                    'limit' => $this->policy->maxCount(),
                ]);

                break;
            }

            try {
                [$contentAvailable, $content] = $this->attachmentValue(
                    $attachment,
                    ['getContent'],
                    true,
                );
                if (! $contentAvailable || ! is_scalar($content)) {
                    throw new \RuntimeException('Inbound attachment content is unavailable.');
                }
                $content = (string) $content;
                [, $filenameValue] = $this->attachmentValue(
                    $attachment,
                    ['getName', 'getFilename'],
                );
                $filename = $this->safeFilename(
                    $filenameValue,
                    $position,
                );
                $sizeBytes = strlen($content);
                $mimeType = $this->detectedMimeType($attachment);

                if ($sizeBytes > $this->policy->maxBytes()) {
                    $policyRejected++;
                    $this->logRejection($message, $position, 'size_limit');

                    continue;
                }

                if (! $this->policy->allowsMimeType($mimeType)) {
                    $policyRejected++;
                    $this->logRejection($message, $position, 'mime_type');

                    continue;
                }

                $checksum = sha1($content);
                $isInline = $this->isInline($attachment);
                $contentId = $this->contentId($attachment, $isInline);
                $path = $this->storagePath($message, $position, $checksum, $filename);
                $legacyPath = $this->legacyStoragePath($message, $position, $checksum, $filename);
                $disk = Storage::disk('local');
                $metadata = [
                    'message_id' => $message->id,
                    'filename' => $filename,
                    'content_type' => $mimeType,
                    'size_bytes' => $sizeBytes,
                    'disk' => 'local',
                    'is_inline' => $isInline,
                    'cid' => $contentId,
                    'checksum_sha1' => $checksum,
                ];
                $existing = EmailAttachment::query()
                    ->where('message_id', $message->id)
                    ->whereIn('path', [$path, $legacyPath])
                    ->first();

                if ($existing) {
                    $existingPath = (string) $existing->path;
                    if ($referencePathBeforeWrite
                        && ! $this->updateMetadata($existing, $metadata, $message, $position)) {
                        $failed++;
                        $failureCodes[] = 'metadata_write';

                        continue;
                    }
                    if (! $this->storedFileMatches(
                        $existingPath,
                        $sizeBytes,
                        $checksum,
                    ) && ! $this->writeAndVerify(
                        $existingPath,
                        $content,
                        $sizeBytes,
                        $checksum,
                    )) {
                        $failed++;
                        $failureCodes[] = 'storage_write';
                        $this->logFailure($message, $position, 'storage_write');

                        continue;
                    }
                    if (! $referencePathBeforeWrite
                        && ! $this->updateMetadata($existing, $metadata, $message, $position)) {
                        $failed++;
                        $failureCodes[] = 'metadata_write';

                        continue;
                    }

                    $persisted++;

                    continue;
                }

                if ($referencePathBeforeWrite) {
                    try {
                        EmailAttachment::create($metadata + [
                            'path' => $path,
                        ]);
                    } catch (Throwable $exception) {
                        $failed++;
                        $failureCodes[] = 'metadata_write';
                        $this->logFailure($message, $position, 'metadata_write', $exception);

                        continue;
                    }

                    if (! $this->storedFileMatches($path, $sizeBytes, $checksum)
                        && ! $this->writeAndVerify($path, $content, $sizeBytes, $checksum)) {
                        // The hidden message now durably references the intended
                        // path. A retry repairs it; no provider bytes can become
                        // an unreferenced orphan on worker loss.
                        $failed++;
                        $failureCodes[] = 'storage_write';
                        $this->logFailure($message, $position, 'storage_write');

                        continue;
                    }

                    $persisted++;

                    continue;
                }

                $fileCreated = false;
                if (! $this->storedFileMatches($path, $sizeBytes, $checksum)) {
                    $existedBefore = $disk->exists($path);
                    if (! $this->writeAndVerify($path, $content, $sizeBytes, $checksum)) {
                        $failed++;
                        $failureCodes[] = 'storage_write';
                        $this->logFailure($message, $position, 'storage_write');

                        continue;
                    }

                    $fileCreated = ! $existedBefore;
                }

                try {
                    EmailAttachment::create($metadata + [
                        'path' => $path,
                    ]);
                } catch (Throwable $exception) {
                    if ($fileCreated) {
                        $disk->delete($path);
                    }

                    $failed++;
                    $failureCodes[] = 'metadata_write';
                    $this->logFailure($message, $position, 'metadata_write', $exception);

                    continue;
                }

                $persisted++;
            } catch (Throwable $exception) {
                $failed++;
                $failureCodes[] = 'attachment_read';
                $this->logFailure($message, $position, 'attachment_read', $exception);
            }
        }

        return new InboundAttachmentPersistenceResult(
            persistedCount: $persisted,
            policyRejectedCount: $policyRejected,
            failedCount: $failed,
            failureCodes: array_values(array_unique($failureCodes)),
            countLimitReached: $countLimitReached,
        );
    }

    /**
     * Verify a provider attachment set without changing metadata or private bytes.
     *
     * This is intentionally separate from persistence: a PREEXISTING active
     * placement is not durable evidence of an interrupted reconciliation Store.
     * Missing or corrupt artifacts fail closed and remain owned by the governed
     * active-content recovery flow.
     */
    public function verifyWithResult(
        EmailMessage $message,
        iterable $attachments,
    ): InboundAttachmentPersistenceResult {
        $verified = 0;
        $policyRejected = 0;
        $failed = 0;
        $failureCodes = [];
        $countLimitReached = false;
        $position = 0;
        $verifiedIds = [];

        foreach ($attachments as $attachment) {
            $position++;

            if ($position > $this->policy->maxCount()) {
                $policyRejected++;
                $countLimitReached = true;

                break;
            }

            try {
                [$contentAvailable, $content] = $this->attachmentValue(
                    $attachment,
                    ['getContent'],
                    true,
                );
                if (! $contentAvailable || ! is_scalar($content)) {
                    throw new \RuntimeException('Inbound attachment content is unavailable.');
                }
                $content = (string) $content;
                [, $filenameValue] = $this->attachmentValue(
                    $attachment,
                    ['getName', 'getFilename'],
                );
                $filename = $this->safeFilename($filenameValue, $position);
                $sizeBytes = strlen($content);
                $mimeType = $this->detectedMimeType($attachment);

                if ($sizeBytes > $this->policy->maxBytes()) {
                    $policyRejected++;

                    continue;
                }
                if (! $this->policy->allowsMimeType($mimeType)) {
                    $policyRejected++;

                    continue;
                }

                $checksum = sha1($content);
                $isInline = $this->isInline($attachment);
                $contentId = $this->contentId($attachment, $isInline);
                $path = $this->storagePath($message, $position, $checksum, $filename);
                $legacyPath = $this->legacyStoragePath($message, $position, $checksum, $filename);
                $existing = EmailAttachment::query()
                    ->where('message_id', $message->id)
                    ->whereIn('path', [$path, $legacyPath])
                    ->first();
                $metadataMatches = $existing
                    && (string) $existing->filename === $filename
                    && (string) ($existing->content_type ?? '') === (string) ($mimeType ?? '')
                    && (int) $existing->size_bytes === $sizeBytes
                    && (string) $existing->disk === 'local'
                    && (bool) $existing->is_inline === $isInline
                    && (string) ($existing->cid ?? '') === (string) ($contentId ?? '')
                    && hash_equals($checksum, (string) $existing->checksum_sha1);

                if (! $metadataMatches) {
                    $failed++;
                    $failureCodes[] = 'metadata_verify';

                    continue;
                }
                if (! $this->storedFileMatches((string) $existing->path, $sizeBytes, $checksum)) {
                    $failed++;
                    $failureCodes[] = 'storage_verify';

                    continue;
                }

                $verifiedIds[] = (int) $existing->id;
                $verified++;
            } catch (Throwable) {
                $failed++;
                $failureCodes[] = 'attachment_read';
            }
        }

        $actualIds = $message->attachments()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        sort($verifiedIds);
        if ((int) $message->attachments_count !== count($actualIds)
            || $actualIds !== $verifiedIds) {
            $failed++;
            $failureCodes[] = 'metadata_parity';
        }

        return new InboundAttachmentPersistenceResult(
            persistedCount: $verified,
            policyRejectedCount: $policyRejected,
            failedCount: $failed,
            failureCodes: array_values(array_unique($failureCodes)),
            countLimitReached: $countLimitReached,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function updateMetadata(
        EmailAttachment $attachment,
        array $metadata,
        EmailMessage $message,
        int $position,
    ): bool {
        try {
            $attachment->forceFill($metadata);
            if ($attachment->isDirty()) {
                $attachment->save();
            }

            return true;
        } catch (Throwable $exception) {
            $this->logFailure($message, $position, 'metadata_write', $exception);

            return false;
        }
    }

    private function detectedMimeType(mixed $attachment): ?string
    {
        [, $mimeType] = $this->attachmentValue($attachment, ['getMimeType', 'getContentType']);

        return $this->policy->normalizeMimeType(is_scalar($mimeType) ? (string) $mimeType : null);
    }

    private function isInline(mixed $attachment): bool
    {
        [, $disposition] = $this->attachmentValue($attachment, ['getDisposition']);
        $disposition = mb_strtolower((string) $disposition);

        return $disposition === 'inline';
    }

    private function contentId(mixed $attachment, bool $isInline): ?string
    {
        if (! $isInline) {
            return null;
        }

        [, $contentId] = $this->attachmentValue($attachment, ['getId']);
        $contentId = trim((string) $contentId, "<> \t\n\r\0\x0B");

        return $contentId !== '' ? mb_substr($contentId, 0, 255) : null;
    }

    /**
     * @param  array<int, string>  $methods
     * @return array{0: bool, 1: mixed}
     */
    private function attachmentValue(
        mixed $attachment,
        array $methods,
        bool $allowEmpty = false,
    ): array {
        if (! is_object($attachment)) {
            return [false, null];
        }

        $lastFailure = null;
        foreach ($methods as $method) {
            if (! is_callable([$attachment, $method])) {
                continue;
            }

            try {
                $value = $attachment->{$method}();
                if ($value !== null && ($allowEmpty || $value !== '')) {
                    return [true, $value];
                }
            } catch (Throwable $exception) {
                $lastFailure = $exception;
                // Try the next Webklex-compatible getter before rejecting the attachment.
            }
        }

        if ($lastFailure) {
            throw $lastFailure;
        }

        return [false, null];
    }

    private function safeFilename(mixed $candidate, int $position): string
    {
        $filename = str_replace('\\', '/', trim((string) $candidate));
        $filename = basename($filename);
        $filename = preg_replace('/[\x00-\x1F\x7F]+/u', '', $filename) ?? '';
        $filename = preg_replace('/[^\pL\pN ._-]+/u', '_', $filename) ?? '';
        $filename = trim($filename, " .\t\n\r\0\x0B");

        if ($filename === '') {
            return 'attachment-'.$position;
        }

        if (mb_strlen($filename) <= 180) {
            return $filename;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.'.mb_substr($extension, 0, 20) : '';
        $stemLength = max(1, 180 - mb_strlen($suffix));

        return rtrim(mb_substr(pathinfo($filename, PATHINFO_FILENAME), 0, $stemLength), ' .').$suffix;
    }

    /**
     * A final-path write can be interrupted without a metadata row. A retry
     * trusts a file only after its exact expected size and digest match the
     * detached provider attachment currently being stored.
     */
    private function writeAndVerify(
        string $path,
        string $content,
        int $sizeBytes,
        string $checksum,
    ): bool {
        return $this->privateStorage->put($path, $content)
            && $this->storedFileMatches($path, $sizeBytes, $checksum);
    }

    private function storedFileMatches(
        string $path,
        int $sizeBytes,
        string $checksum,
    ): bool {
        $stream = null;

        try {
            $stream = Storage::disk('local')->readStream($path);
            if (! is_resource($stream)) {
                return false;
            }

            $digest = hash_init('sha1');
            $readBytes = hash_update_stream($digest, $stream);

            return $readBytes === $sizeBytes
                && hash_equals($checksum, hash_final($digest));
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function storagePath(
        EmailMessage $message,
        int $position,
        string $checksum,
        string $filename,
    ): string {
        $mailboxHash = hash('sha256', (string) $message->mailbox);
        $uid = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $message->imap_uid) ?: 'unknown';
        $uidValidity = max(0, (int) $message->imap_uid_validity);

        return sprintf(
            'email/attachments/v2/%d/%s/%d/%s/%03d-%s-%s',
            $message->account_id,
            $mailboxHash,
            $uidValidity,
            $uid,
            $position,
            substr($checksum, 0, 12),
            $filename,
        );
    }

    /**
     * Preserve idempotent reads/repairs for files stored by the original
     * account/mailbox/UID layout. Existing rows are never moved or rewritten.
     */
    private function legacyStoragePath(
        EmailMessage $message,
        int $position,
        string $checksum,
        string $filename,
    ): string {
        $mailbox = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $message->mailbox) ?: 'INBOX';
        $uid = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $message->imap_uid) ?: 'unknown';

        return sprintf(
            'email/attachments/%d/%s/%s/%03d-%s-%s',
            $message->account_id,
            $mailbox,
            $uid,
            $position,
            substr($checksum, 0, 12),
            $filename,
        );
    }

    private function logRejection(EmailMessage $message, int $position, string $reason): void
    {
        Log::notice('Inbound attachment was rejected by policy.', [
            'email_message_id' => $message->id,
            'attachment_position' => $position,
            'reason' => $reason,
        ]);
    }

    private function logFailure(
        EmailMessage $message,
        int $position,
        string $reason,
        ?Throwable $exception = null,
    ): void {
        Log::warning('Inbound attachment could not be persisted.', [
            'email_message_id' => $message->id,
            'attachment_position' => $position,
            'reason' => $reason,
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
