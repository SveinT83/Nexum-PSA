<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderDeletionCleanupAttempt;
use App\Modules\Email\Models\EmailProviderPlacementFinding;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Services\EmailLiveInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

class EmailProviderDeletionCleanupService
{
    public const REASON_GRACE_PERIOD = 'provider_deletion_grace_period';

    public const REASON_SURVIVING_PLACEMENT = 'surviving_provider_placement';

    public const REASON_TOMBSTONE_CHANGED = 'provider_missing_tombstone_changed';

    public const REASON_PROVIDER_BINDING_CHANGED = 'provider_binding_changed_after_inventory';

    public function __construct(
        private readonly EmailConversationProjector $conversations,
        private readonly EmailLiveInvalidator $invalidator,
    ) {}

    /**
     * Purge only provider-confirmed, placementless Mail cache after both the
     * reconciliation grace period and the ordinary retention policy permit it.
     *
     * @return array{scanned: int, purged: int, protected: int, skipped: int, failed: int}
     */
    public function cleanupDue(
        EmailRetentionEligibilityService $eligibility,
        int $retentionMonths,
        int $limit = 100,
    ): array {
        $stats = ['scanned' => 0, 'purged' => 0, 'protected' => 0, 'skipped' => 0, 'failed' => 0];
        $cutoff = $eligibility->cutoff(max(1, $retentionMonths));
        $findings = EmailProviderPlacementFinding::query()
            ->with('run')
            ->whereIn('finding_type', [
                EmailProviderPlacementFinding::TYPE_CONFIRMED_MISSING,
                EmailProviderPlacementFinding::TYPE_CONFIRMED_MOVE,
            ])
            ->whereNotNull('cleanup_due_at')
            ->where('cleanup_due_at', '<=', now())
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('email_provider_placement_findings as later_provider_findings')
                    ->whereColumn(
                        'later_provider_findings.source_placement_id',
                        'email_provider_placement_findings.source_placement_id',
                    )
                    ->where(
                        'later_provider_findings.finding_type',
                        EmailProviderPlacementFinding::TYPE_REAPPEARED,
                    )
                    ->whereColumn(
                        'later_provider_findings.id',
                        '>',
                        'email_provider_placement_findings.id',
                    );
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('email_provider_deletion_cleanup_attempts')
                    ->whereColumn(
                        'email_provider_deletion_cleanup_attempts.email_provider_placement_finding_id',
                        'email_provider_placement_findings.id',
                    )
                    ->whereIn('email_provider_deletion_cleanup_attempts.status', [
                        EmailProviderDeletionCleanupAttempt::STATUS_PURGED,
                        EmailProviderDeletionCleanupAttempt::STATUS_SKIPPED,
                    ]);
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('email_provider_deletion_cleanup_attempts')
                    ->whereColumn(
                        'email_provider_deletion_cleanup_attempts.email_provider_placement_finding_id',
                        'email_provider_placement_findings.id',
                    )
                    ->where(function ($query): void {
                        $query
                            ->where('email_provider_deletion_cleanup_attempts.status', EmailProviderDeletionCleanupAttempt::STATUS_CHECKING)
                            ->orWhere('email_provider_deletion_cleanup_attempts.retry_after', '>', now());
                    });
            })
            ->orderBy('cleanup_due_at')
            ->limit(max(1, $limit) * 4)
            ->get()
            ->unique('email_message_id')
            ->take(max(1, $limit));

        foreach ($findings as $finding) {
            $messageId = (int) $finding->email_message_id;
            $stats['scanned']++;

            $providerBindingVersion = (int) $finding->run?->provider_binding_version;
            if ($providerBindingVersion < 1) {
                $stats['protected']++;

                continue;
            }

            $attemptAttributes = [
                'email_provider_placement_finding_id' => $finding->id,
                'account_id' => $finding->account_id,
                'email_message_id' => $messageId,
                'status' => EmailProviderDeletionCleanupAttempt::STATUS_CHECKING,
                'started_at' => now(),
            ];
            if (Schema::hasColumn('email_provider_deletion_cleanup_attempts', 'provider_binding_version')) {
                $attemptAttributes['provider_binding_version'] = $providerBindingVersion;
            }
            $attempt = EmailProviderDeletionCleanupAttempt::query()->create($attemptAttributes);

            $account = EmailAccount::query()->find($finding->account_id);
            if (! $account
                || app(EmailAccountProviderRuntimeResolver::class)->bindingVersion($account)
                    !== $providerBindingVersion) {
                $attempt->forceFill([
                    'status' => EmailProviderDeletionCleanupAttempt::STATUS_PROTECTED,
                    'reasons_json' => [self::REASON_PROVIDER_BINDING_CHANGED],
                    'retry_after' => now()->addDay(),
                    'finished_at' => now(),
                ])->save();
                $stats['protected']++;

                continue;
            }

            try {
                $result = DB::transaction(function () use (
                    $messageId,
                    $attempt,
                    $eligibility,
                    $cutoff,
                ): array {
                    $message = EmailMessage::query()
                        ->withTrashed()
                        ->whereKey($messageId)
                        ->lockForUpdate()
                        ->first();

                    if (! $message) {
                        $attempt->forceFill([
                            'status' => EmailProviderDeletionCleanupAttempt::STATUS_SKIPPED,
                            'reasons_json' => ['already_missing'],
                            'finished_at' => now(),
                        ])->save();

                        return [
                            'status' => EmailProviderDeletionCleanupAttempt::STATUS_SKIPPED,
                            'conversation_ids' => [],
                        ];
                    }

                    $terminalFindings = EmailProviderPlacementFinding::query()
                        ->where('email_message_id', $messageId)
                        ->whereIn('finding_type', [
                            EmailProviderPlacementFinding::TYPE_CONFIRMED_MISSING,
                            EmailProviderPlacementFinding::TYPE_CONFIRMED_MOVE,
                        ])
                        ->get();
                    $latestDueAt = $terminalFindings->max('cleanup_due_at');

                    if (! $latestDueAt || now()->lt($latestDueAt)) {
                        $attempt->forceFill([
                            'status' => EmailProviderDeletionCleanupAttempt::STATUS_PROTECTED,
                            'reasons_json' => [self::REASON_GRACE_PERIOD],
                            'retry_after' => $latestDueAt,
                            'finished_at' => now(),
                        ])->save();

                        return [
                            'status' => EmailProviderDeletionCleanupAttempt::STATUS_PROTECTED,
                            'conversation_ids' => [],
                        ];
                    }

                    $terminalByPlacement = $terminalFindings->keyBy('source_placement_id');
                    $tombstones = EmailMailboxPlacement::query()
                        ->whereIn('id', $terminalByPlacement->keys())
                        ->lockForUpdate()
                        ->get();
                    $tombstoneChanged = $tombstones->contains(function (EmailMailboxPlacement $placement) use (
                        $messageId,
                        $terminalByPlacement,
                    ): bool {
                        $terminal = $terminalByPlacement->get($placement->id);

                        return ! $terminal
                            || (int) $placement->account_id !== (int) $terminal->account_id
                            || (int) $placement->email_message_id !== $messageId
                            || $placement->local_state !== EmailMailboxPlacement::LOCAL_HIDDEN
                            || $placement->provider_missing_at === null
                            || $placement->sync_status !== EmailMailboxPlacement::SYNC_SYNCED;
                    });

                    if ($tombstoneChanged) {
                        $attempt->forceFill([
                            'status' => EmailProviderDeletionCleanupAttempt::STATUS_PROTECTED,
                            'reasons_json' => [self::REASON_TOMBSTONE_CHANGED],
                            'retry_after' => now()->addDay(),
                            'finished_at' => now(),
                        ])->save();

                        return [
                            'status' => EmailProviderDeletionCleanupAttempt::STATUS_PROTECTED,
                            'conversation_ids' => [],
                        ];
                    }

                    if ($this->hasUnresolvedRemoteOperation($tombstones->pluck('id')->all())) {
                        $attempt->forceFill([
                            'status' => EmailProviderDeletionCleanupAttempt::STATUS_PROTECTED,
                            'reasons_json' => [EmailRetentionEligibilityService::REASON_REMOTE_OPERATION],
                            'retry_after' => now()->addDay(),
                            'finished_at' => now(),
                        ])->save();

                        return [
                            'status' => EmailProviderDeletionCleanupAttempt::STATUS_PROTECTED,
                            'conversation_ids' => [],
                        ];
                    }

                    $conversationIds = $tombstones
                        ->pluck('email_conversation_id')
                        ->filter()
                        ->map(fn (mixed $id): int => (int) $id)
                        ->unique()
                        ->values()
                        ->all();

                    foreach ($tombstones as $tombstone) {
                        $tombstone->delete();
                    }

                    if (EmailMailboxPlacement::query()
                        ->where('email_message_id', $messageId)
                        ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                        ->exists()) {
                        $attempt->forceFill([
                            'status' => EmailProviderDeletionCleanupAttempt::STATUS_SKIPPED,
                            'reasons_json' => [self::REASON_SURVIVING_PLACEMENT],
                            'finished_at' => now(),
                        ])->save();

                        return [
                            'status' => EmailProviderDeletionCleanupAttempt::STATUS_SKIPPED,
                            'conversation_ids' => $conversationIds,
                        ];
                    }

                    $message->load([
                        'attachments:id,message_id,disk,path',
                        'placements:id,email_message_id,email_conversation_id,local_state,sync_status,provider_deleted,provider_missing_at',
                    ]);
                    $assessment = $eligibility->assess($message, $cutoff);

                    if (! $assessment['eligible']) {
                        $attempt->forceFill([
                            'status' => EmailProviderDeletionCleanupAttempt::STATUS_PROTECTED,
                            'reasons_json' => $assessment['reasons'],
                            'retry_after' => now()->addDay(),
                            'finished_at' => now(),
                        ])->save();

                        return [
                            'status' => EmailProviderDeletionCleanupAttempt::STATUS_PROTECTED,
                            'conversation_ids' => $conversationIds,
                        ];
                    }

                    $localAttachmentCount = $message->attachments
                        ->where('disk', 'local')
                        ->filter(fn ($attachment): bool => filled($attachment->path))
                        ->count();
                    $suggestionCount = $this->purgeSmartInboxSuggestions($message);
                    $attempt->forceFill([
                        'had_raw_payload' => filled($message->raw_path),
                        'local_attachment_file_count' => $localAttachmentCount,
                        'smart_inbox_suggestion_count' => $suggestionCount,
                    ])->save();

                    $this->deleteLocalPayloads($message);
                    $message->tags()->detach();

                    $accountId = $message->account_id;

                    if (! $message->forceDelete()) {
                        throw new RuntimeException('database_delete_failed');
                    }

                    $this->invalidator->record([
                        'account' => [
                            $accountId => [EmailLiveProjectionChange::TYPE_MAIL_PROJECTION],
                        ],
                        'conversations' => $conversationIds,
                    ]);

                    $attempt->forceFill([
                        'status' => EmailProviderDeletionCleanupAttempt::STATUS_PURGED,
                        'finished_at' => now(),
                    ])->save();

                    return [
                        'status' => EmailProviderDeletionCleanupAttempt::STATUS_PURGED,
                        'conversation_ids' => $conversationIds,
                    ];
                }, 3);

                foreach ($result['conversation_ids'] as $conversationId) {
                    $this->conversations->refreshConversation(
                        EmailConversation::query()->find($conversationId),
                    );
                }

                match ($result['status']) {
                    EmailProviderDeletionCleanupAttempt::STATUS_PURGED => $stats['purged']++,
                    EmailProviderDeletionCleanupAttempt::STATUS_PROTECTED => $stats['protected']++,
                    default => $stats['skipped']++,
                };
            } catch (Throwable $exception) {
                $stats['failed']++;
                $attempt->forceFill([
                    'status' => EmailProviderDeletionCleanupAttempt::STATUS_FAILED,
                    'failure_code' => $exception->getMessage() === 'storage_delete_failed'
                        ? 'storage_delete_failed'
                        : ($exception instanceof JsonException
                            ? 'smart_inbox_source_evidence_invalid'
                            : 'provider_deletion_cleanup_failed'),
                    'retry_after' => now()->addDay(),
                    'finished_at' => now(),
                ])->save();
            }
        }

        return $stats;
    }

    /**
     * Delete only suggestions derived from the expiring source message. When a
     * conversation has no surviving placement, its remaining suggestions have
     * no live provider source and are removed as derived Mail data as well.
     *
     * @throws JsonException
     */
    private function purgeSmartInboxSuggestions(EmailMessage $message): int
    {
        if (! Schema::hasTable('email_smart_inbox_suggestions')) {
            return 0;
        }

        $conversationIds = EmailProviderPlacementFinding::query()
            ->where('email_message_id', $message->id)
            ->whereNotNull('email_conversation_id')
            ->pluck('email_conversation_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();

        if ($conversationIds->isEmpty()) {
            return 0;
        }

        $placementlessConversationIds = $conversationIds
            ->reject(fn (int $conversationId): bool => EmailMailboxPlacement::query()
                ->where('email_conversation_id', $conversationId)
                ->exists())
            ->flip();
        $suggestionIds = DB::table('email_smart_inbox_suggestions')
            ->where('account_id', $message->account_id)
            ->whereIn('email_conversation_id', $conversationIds)
            ->get(['id', 'email_conversation_id', 'source_message_ids_json'])
            ->filter(function (object $suggestion) use ($message, $placementlessConversationIds): bool {
                $sourceIds = json_decode(
                    (string) $suggestion->source_message_ids_json,
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                if (! is_array($sourceIds)) {
                    throw new JsonException('Smart Inbox source identifiers are not an array.');
                }

                return in_array((int) $message->id, array_map('intval', $sourceIds), true)
                    || $placementlessConversationIds->has((int) $suggestion->email_conversation_id);
            })
            ->pluck('id');

        if ($suggestionIds->isEmpty()) {
            return 0;
        }

        return DB::table('email_smart_inbox_suggestions')
            ->whereIn('id', $suggestionIds)
            ->delete();
    }

    /**
     * A provider operation created during the grace window still owns the
     * placement identity it was authorized against. Keep that tombstone until
     * the operation reaches a terminal state so cleanup cannot erase its
     * recovery boundary or bypass the shared retention blocker.
     *
     * @param  array<int, int>  $placementIds
     */
    private function hasUnresolvedRemoteOperation(array $placementIds): bool
    {
        if ($placementIds === []) {
            return false;
        }

        return EmailRemoteOperation::query()
            ->whereIn('email_mailbox_placement_id', $placementIds)
            ->whereNotIn('status', [
                EmailRemoteOperation::STATUS_SUCCEEDED,
                EmailRemoteOperation::STATUS_CANCELLED,
                EmailRemoteOperation::STATUS_SUPERSEDED,
            ])
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    private function deleteLocalPayloads(EmailMessage $message): void
    {
        $disk = Storage::disk('local');

        foreach ($message->attachments as $attachment) {
            if ($attachment->disk !== 'local' || blank($attachment->path)) {
                continue;
            }

            // A previous attempt may have deleted this file before a later
            // storage operation failed and caused the database transaction to
            // roll back. Missing files are therefore already-clean state, but
            // a file that remains after delete must fail closed and be retried.
            if (! $disk->exists($attachment->path)) {
                continue;
            }

            $disk->delete($attachment->path);

            if ($disk->exists($attachment->path)) {
                throw new RuntimeException('storage_delete_failed');
            }
        }

        if (filled($message->raw_path) && $disk->exists($message->raw_path)) {
            $disk->delete($message->raw_path);

            if ($disk->exists($message->raw_path)) {
                throw new RuntimeException('storage_delete_failed');
            }
        }
    }
}
