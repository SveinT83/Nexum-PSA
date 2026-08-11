<?php

namespace App\Modules\Email\Services;

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
    ) {}

    public function persist(EmailMessage $message, iterable $attachments): int
    {
        $persisted = 0;
        $position = 0;

        foreach ($attachments as $attachment) {
            $position++;

            if ($position > $this->policy->maxCount()) {
                Log::notice('Inbound attachment count limit reached; remaining attachments were skipped.', [
                    'email_message_id' => $message->id,
                    'limit' => $this->policy->maxCount(),
                ]);

                break;
            }

            try {
                $content = (string) $this->attachmentValue($attachment, ['getContent']);
                $filename = $this->safeFilename(
                    $this->attachmentValue($attachment, ['getName', 'getFilename']),
                    $position,
                );
                $sizeBytes = strlen($content);
                $mimeType = $this->detectedMimeType($attachment);

                if ($sizeBytes > $this->policy->maxBytes()) {
                    $this->logRejection($message, $filename, 'size_limit');

                    continue;
                }

                if (! $this->policy->allowsMimeType($mimeType)) {
                    $this->logRejection($message, $filename, 'mime_type');

                    continue;
                }

                $checksum = sha1($content);
                $path = $this->storagePath($message, $position, $checksum, $filename);
                $disk = Storage::disk('local');
                $existing = EmailAttachment::query()
                    ->where('message_id', $message->id)
                    ->where('path', $path)
                    ->first();

                if ($existing) {
                    if (! $disk->exists($path) && ! $disk->put($path, $content)) {
                        $this->logFailure($message, $filename, 'storage_write');

                        continue;
                    }

                    $persisted++;

                    continue;
                }

                $fileCreated = false;
                if (! $disk->exists($path)) {
                    if (! $disk->put($path, $content)) {
                        $this->logFailure($message, $filename, 'storage_write');

                        continue;
                    }

                    $fileCreated = true;
                }

                try {
                    EmailAttachment::create([
                        'message_id' => $message->id,
                        'filename' => $filename,
                        'content_type' => $mimeType,
                        'size_bytes' => $sizeBytes,
                        'disk' => 'local',
                        'path' => $path,
                        'is_inline' => $this->isInline($attachment),
                        'cid' => $this->contentId($attachment),
                        'checksum_sha1' => $checksum,
                    ]);
                } catch (Throwable $exception) {
                    if ($fileCreated) {
                        $disk->delete($path);
                    }

                    $this->logFailure($message, $filename, 'metadata_write', $exception);

                    continue;
                }

                $persisted++;
            } catch (Throwable $exception) {
                $this->logFailure($message, 'attachment-'.$position, 'attachment_read', $exception);
            }
        }

        return $persisted;
    }

    private function detectedMimeType(mixed $attachment): ?string
    {
        $mimeType = $this->attachmentValue($attachment, ['getMimeType', 'getContentType']);

        return $this->policy->normalizeMimeType(is_scalar($mimeType) ? (string) $mimeType : null);
    }

    private function isInline(mixed $attachment): bool
    {
        $disposition = mb_strtolower((string) $this->attachmentValue($attachment, ['getDisposition']));

        return $disposition === 'inline';
    }

    private function contentId(mixed $attachment): ?string
    {
        if (! $this->isInline($attachment)) {
            return null;
        }

        $contentId = trim((string) $this->attachmentValue($attachment, ['getId']), "<> \t\n\r\0\x0B");

        return $contentId !== '' ? mb_substr($contentId, 0, 255) : null;
    }

    private function attachmentValue(mixed $attachment, array $methods): mixed
    {
        if (! is_object($attachment)) {
            return null;
        }

        foreach ($methods as $method) {
            try {
                $value = $attachment->{$method}();
                if ($value !== null && $value !== '') {
                    return $value;
                }
            } catch (Throwable) {
                // Try the next Webklex-compatible getter before rejecting the attachment.
            }
        }

        return null;
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

    private function storagePath(
        EmailMessage $message,
        int $position,
        string $checksum,
        string $filename,
    ): string {
        $uid = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $message->imap_uid) ?: 'unknown';

        return sprintf(
            'email/attachments/%d/%s/%03d-%s-%s',
            $message->account_id,
            $uid,
            $position,
            substr($checksum, 0, 12),
            $filename,
        );
    }

    private function logRejection(EmailMessage $message, string $filename, string $reason): void
    {
        Log::notice('Inbound attachment was rejected by policy.', [
            'email_message_id' => $message->id,
            'filename' => $filename,
            'reason' => $reason,
        ]);
    }

    private function logFailure(
        EmailMessage $message,
        string $filename,
        string $reason,
        ?Throwable $exception = null,
    ): void {
        Log::warning('Inbound attachment could not be persisted.', [
            'email_message_id' => $message->id,
            'filename' => $filename,
            'reason' => $reason,
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
