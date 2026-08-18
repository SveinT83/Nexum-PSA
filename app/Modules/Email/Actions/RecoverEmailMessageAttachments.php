<?php

namespace App\Modules\Email\Actions;

use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailAttachmentRecoveryReadiness;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailPrivateStorage;
use App\Modules\Email\Services\EmailProviderMessageIdentity;
use App\Modules\Email\Services\EmailRawMessageSnapshot;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Services\InboundAttachmentPersister;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Email\Support\InboundAttachmentPolicy;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Repairs one message without replaying provider mutations or inbound rules.
 */
final class RecoverEmailMessageAttachments
{
    public const MAX_LOCAL_SNAPSHOT_BYTES = 64 * 1024 * 1024;

    public function __construct(
        private readonly EmailRawMessageSnapshot $snapshots,
        private readonly InboundAttachmentPersister $persister,
        private readonly EmailProviderMessageIdentity $providerIdentity,
        private readonly EmailAttachmentRecoveryReadiness $readiness,
        private readonly InboundAttachmentPolicy $attachmentPolicy,
    ) {}

    /**
     * @return array{message_id: int, status: string, reason_code: string, source: string|null, source_count: int, processed_count: int, before_count: int, after_count: int, counter_before: int, counter_after: int}
     */
    public function handle(EmailMessage $message, bool $allowProviderFallback = false): array
    {
        $message->loadMissing(['account', 'placements.folder']);
        $beforeCount = $message->attachments()->count();
        $readiness = $this->readiness->check();

        if (! $readiness['safe']) {
            return $this->failWithoutMutation(
                $message,
                $beforeCount,
                'schema_not_ready_'.$readiness['reason_code'],
            );
        }

        if ($message->is_oversize) {
            return $this->withoutSource($message, $beforeCount, 'oversize_message');
        }

        if ($beforeCount > 0 && $beforeCount === (int) $message->attachments_count) {
            $result = $this->report(
                $message->id,
                'unchanged',
                'existing_rows_complete',
                'existing_rows',
                $beforeCount,
                $beforeCount,
                $beforeCount,
                $beforeCount,
                $beforeCount,
                $beforeCount,
            );
            $this->logReport($result);

            return $result;
        }

        $local = $this->localSource($message);
        if ($local['attachments'] !== []) {
            return $this->persist($message, $local['attachments'], 'local_snapshot');
        }

        // The original inbound persister stored files below
        // email/attachments/{account}/{uid} before attachment rows gained the
        // current mailbox-aware path. A complete directory whose file count
        // exactly matches the preserved message counter is stronger evidence
        // than a missing provider UID, and lets the bounded recovery retain
        // those bytes without broad mailbox searching.
        $legacy = $this->legacyStoredSource($message, $beforeCount);
        if ($legacy !== []) {
            return $this->persist($message, $legacy, 'legacy_attachment_directory');
        }

        if (! $allowProviderFallback) {
            return $this->withoutSource(
                $message,
                $beforeCount,
                $local['reason_code'] === 'local_snapshot_empty'
                    ? 'local_snapshot_empty'
                    : 'provider_fallback_disabled',
            );
        }

        $provider = $this->providerSource($message);
        if ($provider['attachments'] === []) {
            return $this->withoutSource($message, $beforeCount, $provider['reason_code']);
        }

        return $this->persist($message, $provider['attachments'], 'provider_fallback');
    }

    /**
     * @return array{attachments: array<int, object>, reason_code: string}
     */
    private function localSource(EmailMessage $message): array
    {
        $disk = Storage::disk(EmailPrivateStorage::DISK);
        $path = $this->safeRawPath($disk, (string) $message->raw_path);

        if ($path === null) {
            return ['attachments' => [], 'reason_code' => 'local_snapshot_unavailable'];
        }

        try {
            $size = $disk->size($path);
            if ($size < 1 || $size > self::MAX_LOCAL_SNAPSHOT_BYTES) {
                return ['attachments' => [], 'reason_code' => 'local_snapshot_size_invalid'];
            }

            $parsed = $this->snapshots->parseStored(
                $disk->get($path),
                is_array($message->headers_json) ? $message->headers_json : [],
            );

            if (! $parsed) {
                return ['attachments' => [], 'reason_code' => 'local_snapshot_unparseable'];
            }

            $attachments = $this->objects($parsed->getAttachments());

            return [
                'attachments' => $attachments,
                'reason_code' => $attachments === [] ? 'local_snapshot_empty' : 'local_snapshot_ready',
            ];
        } catch (Throwable) {
            return ['attachments' => [], 'reason_code' => 'local_snapshot_read_failed'];
        }
    }

    /**
     * @return array{attachments: array<int, object>, reason_code: string}
     */
    private function providerSource(EmailMessage $message): array
    {
        $account = $message->account;
        if (! $account || ! $account->is_active) {
            return ['attachments' => [], 'reason_code' => 'provider_account_unavailable'];
        }

        $placement = $message->placements
            ->filter(function (EmailMailboxPlacement $candidate) use ($message): bool {
                $folder = $candidate->folder;

                return $candidate->local_state === EmailMailboxPlacement::LOCAL_ACTIVE
                    && $candidate->provider_missing_at === null
                    && (int) $candidate->account_id === (int) $message->account_id
                    && (int) $candidate->email_message_id === (int) $message->id
                    && (int) $candidate->imap_uid > 0
                    && (int) $candidate->imap_uid_validity > 0
                    && $folder !== null
                    && (int) $folder->account_id === (int) $candidate->account_id
                    && $folder->is_selectable
                    && $folder->sync_enabled
                    && (int) $folder->uid_validity === (int) $candidate->imap_uid_validity
                    && (string) $folder->path === (string) $candidate->folder_path;
            })
            ->sortByDesc(fn (EmailMailboxPlacement $candidate): string => sprintf(
                '%020d-%020d',
                $candidate->last_reconciled_at?->getTimestamp() ?? 0,
                $candidate->id,
            ))
            ->first();

        if (! $placement) {
            return ['attachments' => [], 'reason_code' => 'provider_identity_unavailable'];
        }

        $providerLock = EmailAccountProviderLock::acquire((int) $account->id, 180);
        if (! $providerLock) {
            return ['attachments' => [], 'reason_code' => 'provider_account_busy'];
        }

        $client = null;
        $expectedBindingVersion = app(EmailAccountProviderRuntimeResolver::class)
            ->captureBindingVersion($account);

        try {
            $client = app()->makeWith(ImapClient::class, [
                'account' => $account,
                'expectedProviderBindingVersion' => $expectedBindingVersion,
            ]);
            $client->connect();
            $folderState = $client->folderState((string) $placement->folder_path);

            if ((int) ($folderState['uid_validity'] ?? 0) !== (int) $placement->imap_uid_validity) {
                return ['attachments' => [], 'reason_code' => 'provider_uidvalidity_mismatch'];
            }

            $providerMessage = $client->fetchByUid(
                (int) $placement->imap_uid,
                (string) $placement->folder_path,
            );

            if (! $providerMessage) {
                return ['attachments' => [], 'reason_code' => 'provider_message_missing'];
            }

            if (method_exists($providerMessage, 'getUid')) {
                $observedUid = (int) $providerMessage->getUid();
                if ($observedUid > 0 && $observedUid !== (int) $placement->imap_uid) {
                    return ['attachments' => [], 'reason_code' => 'provider_uid_mismatch'];
                }
            }

            $storedMessageId = $this->providerIdentity->normalizeMessageId($message->message_id);
            $providerMessageIdValue = null;
            try {
                // Webklex exposes header getters dynamically through __call(),
                // so method_exists() cannot be used for Message-ID evidence.
                $providerMessageIdValue = $providerMessage->getMessageId();
            } catch (Throwable) {
                // Missing provider evidence is handled as an asymmetric mismatch below.
            }
            $providerMessageId = $this->providerIdentity->normalizeMessageId($providerMessageIdValue);

            if (($storedMessageId !== '' || $providerMessageId !== '')
                && ($storedMessageId === ''
                    || $providerMessageId === ''
                    || ! hash_equals($storedMessageId, $providerMessageId))) {
                return ['attachments' => [], 'reason_code' => 'provider_message_id_mismatch'];
            }

            $confirmedFolderState = $client->folderState((string) $placement->folder_path);
            if ((int) ($confirmedFolderState['uid_validity'] ?? 0) !== (int) $placement->imap_uid_validity) {
                return ['attachments' => [], 'reason_code' => 'provider_uidvalidity_changed_during_read'];
            }

            if (! method_exists($providerMessage, 'getAttachments')) {
                return ['attachments' => [], 'reason_code' => 'provider_payload_invalid'];
            }

            $attachments = $this->objects($providerMessage->getAttachments());

            return [
                'attachments' => $attachments,
                'reason_code' => $attachments === [] ? 'provider_snapshot_empty' : 'provider_snapshot_ready',
            ];
        } catch (Throwable $exception) {
            Log::warning('Email attachment recovery provider read failed.', [
                'email_message_id' => $message->id,
                'account_id' => $message->account_id,
                'exception' => $exception::class,
            ]);

            return ['attachments' => [], 'reason_code' => 'provider_read_failed'];
        } finally {
            try {
                if ($client) {
                    $client->disconnect();
                }
            } catch (Throwable $exception) {
                Log::notice('Email attachment recovery provider disconnect failed.', [
                    'email_message_id' => $message->id,
                    'account_id' => $message->account_id,
                    'exception' => $exception::class,
                ]);
            } finally {
                $providerLock->release();
            }
        }
    }

    /**
     * @return array<int, object>
     */
    private function legacyStoredSource(EmailMessage $message, int $existingRows): array
    {
        $expectedCount = (int) $message->attachments_count;
        if ($existingRows !== 0
            || $expectedCount < 1
            || $expectedCount > $this->attachmentPolicy->maxCount()
            || (int) $message->account_id < 1
            || (int) $message->imap_uid < 1) {
            return [];
        }

        $disk = Storage::disk(EmailPrivateStorage::DISK);
        $directory = sprintf(
            'email/attachments/%d/%d',
            (int) $message->account_id,
            (int) $message->imap_uid,
        );

        try {
            if (! $disk->directoryExists($directory)) {
                return [];
            }

            $paths = collect($disk->files($directory))
                ->filter(fn (string $path): bool => dirname($path) === $directory)
                ->sort()
                ->values();

            if ($paths->count() !== $expectedCount) {
                return [];
            }

            $attachmentRoot = realpath($disk->path('email/attachments'));
            if ($attachmentRoot === false) {
                return [];
            }

            $attachments = [];
            foreach ($paths as $path) {
                $absolutePath = $disk->path($path);
                $resolvedPath = realpath($absolutePath);
                if ($resolvedPath === false
                    || is_link($absolutePath)
                    || ! str_starts_with($resolvedPath, $attachmentRoot.DIRECTORY_SEPARATOR)) {
                    return [];
                }

                $size = $disk->size($path);
                if ($size < 1 || $size > $this->attachmentPolicy->maxBytes()) {
                    return [];
                }

                $content = $disk->get($path);
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: null;
                $mime = $this->attachmentPolicy->normalizeMimeType($mime);
                if (! $this->attachmentPolicy->allowsMimeType($mime)) {
                    return [];
                }

                $filename = basename($path);
                $attachments[] = new class($content, $filename, $mime)
                {
                    public function __construct(
                        private readonly string $content,
                        private readonly string $filename,
                        private readonly ?string $mime,
                    ) {}

                    public function getContent(): string
                    {
                        return $this->content;
                    }

                    public function getName(): string
                    {
                        return $this->filename;
                    }

                    public function getMimeType(): ?string
                    {
                        return $this->mime;
                    }

                    public function getDisposition(): string
                    {
                        return 'attachment';
                    }

                    public function getId(): null
                    {
                        return null;
                    }
                };
            }

            return $attachments;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, object>  $attachments
     * @return array{message_id: int, status: string, reason_code: string, source: string|null, source_count: int, processed_count: int, before_count: int, after_count: int, counter_before: int, counter_after: int}
     */
    private function persist(EmailMessage $message, array $attachments, string $source): array
    {
        $result = DB::transaction(function () use ($message, $attachments, $source): array {
            $locked = EmailMessage::query()->lockForUpdate()->find($message->id);
            if (! $locked) {
                return $this->report(
                    $message->id,
                    'failed',
                    'message_unavailable',
                    null,
                    count($attachments),
                    0,
                    0,
                    0,
                    (int) $message->attachments_count,
                    (int) $message->attachments_count,
                );
            }

            $beforeCount = $locked->attachments()->count();
            $counterBefore = (int) $locked->attachments_count;
            $processedCount = $this->persister->persist($locked, $attachments);
            $afterCount = $locked->attachments()->count();

            if ((int) $locked->attachments_count !== $afterCount) {
                $locked->forceFill(['attachments_count' => $afterCount])->save();
            }

            $complete = $processedCount === count($attachments);
            $status = ! $complete
                ? 'partial'
                : ($afterCount > $beforeCount ? 'recovered' : 'unchanged');

            return $this->report(
                $locked->id,
                $status,
                $source.($complete ? '_complete' : '_partial'),
                $source,
                count($attachments),
                $processedCount,
                $beforeCount,
                $afterCount,
                $counterBefore,
                $afterCount,
            );
        }, 3);

        $this->logReport($result);

        return $result;
    }

    /**
     * @return array{message_id: int, status: string, reason_code: string, source: string|null, source_count: int, processed_count: int, before_count: int, after_count: int, counter_before: int, counter_after: int}
     */
    private function withoutSource(EmailMessage $message, int $beforeCount, string $reasonCode): array
    {
        $sourceProvedEmpty = in_array($reasonCode, ['local_snapshot_empty', 'provider_snapshot_empty'], true);
        $counts = DB::transaction(function () use ($message, $sourceProvedEmpty): array {
            $locked = EmailMessage::query()->lockForUpdate()->find($message->id);
            if (! $locked) {
                return [
                    'rows' => 0,
                    'counter_before' => (int) $message->attachments_count,
                    'counter_after' => (int) $message->attachments_count,
                ];
            }

            $actual = $locked->attachments()->count();
            $counterBefore = (int) $locked->attachments_count;

            // A higher stored counter with fewer rows is evidence of missing
            // metadata until a parsed source proves the authoritative count.
            $mayRecompute = $sourceProvedEmpty || $actual >= $counterBefore;
            if ($mayRecompute && $counterBefore !== $actual) {
                $locked->forceFill(['attachments_count' => $actual])->save();
            }

            return [
                'rows' => $actual,
                'counter_before' => $counterBefore,
                'counter_after' => $mayRecompute ? $actual : $counterBefore,
            ];
        }, 3);

        $status = $sourceProvedEmpty ? 'unchanged' : 'failed';
        $result = $this->report(
            $message->id,
            $status,
            $reasonCode,
            null,
            0,
            0,
            $beforeCount,
            $counts['rows'],
            $counts['counter_before'],
            $counts['counter_after'],
        );
        $this->logReport($result);

        return $result;
    }

    /**
     * @return array{message_id: int, status: string, reason_code: string, source: string|null, source_count: int, processed_count: int, before_count: int, after_count: int, counter_before: int, counter_after: int}
     */
    private function failWithoutMutation(EmailMessage $message, int $rowCount, string $reasonCode): array
    {
        $counter = (int) $message->attachments_count;
        $result = $this->report(
            $message->id,
            'failed',
            $reasonCode,
            null,
            0,
            0,
            $rowCount,
            $rowCount,
            $counter,
            $counter,
        );
        $this->logReport($result);

        return $result;
    }

    private function safeRawPath(FilesystemAdapter $disk, string $path): ?string
    {
        $path = trim($path);

        if ($path === ''
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || ! str_starts_with($path, 'email/raw/')) {
            return null;
        }

        $segments = explode('/', $path);
        if (in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || ! $disk->exists($path)) {
            return null;
        }

        $root = realpath($disk->path('email/raw'));
        $absolutePath = realpath($disk->path($path));

        return $root !== false
            && $absolutePath !== false
            && is_file($absolutePath)
            && str_starts_with($absolutePath, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
                ? $path
                : null;
    }

    /** @return array<int, object> */
    private function objects(mixed $attachments): array
    {
        if (! is_iterable($attachments)) {
            return [];
        }

        $objects = [];
        foreach ($attachments as $attachment) {
            if (is_object($attachment)) {
                $objects[] = $attachment;
            }
        }

        return $objects;
    }

    /**
     * @return array{message_id: int, status: string, reason_code: string, source: string|null, source_count: int, processed_count: int, before_count: int, after_count: int, counter_before: int, counter_after: int}
     */
    private function report(
        int $messageId,
        string $status,
        string $reasonCode,
        ?string $source,
        int $sourceCount,
        int $processedCount,
        int $beforeCount,
        int $afterCount,
        int $counterBefore,
        int $counterAfter,
    ): array {
        return [
            'message_id' => $messageId,
            'status' => $status,
            'reason_code' => $reasonCode,
            'source' => $source,
            'source_count' => $sourceCount,
            'processed_count' => $processedCount,
            'before_count' => $beforeCount,
            'after_count' => $afterCount,
            'counter_before' => $counterBefore,
            'counter_after' => $counterAfter,
        ];
    }

    /** @param array<string, int|string|null> $report */
    private function logReport(array $report): void
    {
        $level = in_array($report['status'], ['recovered', 'unchanged'], true) ? 'info' : 'warning';

        Log::{$level}('Email attachment recovery finished.', $report);
    }
}
