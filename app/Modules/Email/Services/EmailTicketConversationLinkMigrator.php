<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Jobs\BackfillEmailTicketConversationLinks;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Models\EmailTicketConversationLinkMigrationItem;
use App\Modules\Email\Models\EmailTicketConversationLinkMigrationRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class EmailTicketConversationLinkMigrator
{
    public const DEFAULT_ITEM_CAP = 100;

    public const MAX_ITEM_CAP = 500;

    public const APPLY_BATCH_SIZE = 25;

    public const PREVIEW_TTL_MINUTES = 15;

    /** @var list<string> */
    private const CONFLICT_STATUSES = [
        EmailTicketConversationLinkMigrationItem::STATUS_MISSING_CONVERSATION,
        EmailTicketConversationLinkMigrationItem::STATUS_MISSING_TICKET,
        EmailTicketConversationLinkMigrationItem::STATUS_PRIMARY_CONFLICT,
        EmailTicketConversationLinkMigrationItem::STATUS_AUDIENCE_CONFLICT,
        EmailTicketConversationLinkMigrationItem::STATUS_ACCOUNT_CONFLICT,
    ];

    public function preview(User $actor, int $itemCap = self::DEFAULT_ITEM_CAP): EmailTicketConversationLinkMigrationRun
    {
        $this->authorize($actor);
        $this->assertSchemaReady();

        if ($itemCap < 1 || $itemCap > self::MAX_ITEM_CAP) {
            throw ValidationException::withMessages([
                'limit' => 'The migration preview limit must be between 1 and 500.',
            ]);
        }

        return DB::transaction(function () use ($actor, $itemCap): EmailTicketConversationLinkMigrationRun {
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            $this->authorize($lockedActor);

            $candidateQuery = DB::table('email_messages')
                ->whereNotNull('ticket_id');
            $candidateCount = (int) (clone $candidateQuery)->count();
            $candidateIds = (clone $candidateQuery)
                ->orderBy('id')
                ->limit($itemCap + 1)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $now = now();

            if ($candidateCount > $itemCap) {
                return EmailTicketConversationLinkMigrationRun::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'requested_by' => $lockedActor->id,
                    'status' => EmailTicketConversationLinkMigrationRun::STATUS_BLOCKED,
                    'item_cap' => $itemCap,
                    'candidate_count' => $candidateCount,
                    'ready_count' => 0,
                    'already_mapped_count' => 0,
                    'conflict_count' => $candidateCount,
                    'applied_count' => 0,
                    'failed_count' => 0,
                    'scope_fingerprint' => $this->fingerprint([
                        'candidate_count' => $candidateCount,
                        'candidate_ids_cap_plus_one' => $candidateIds,
                        'item_cap' => $itemCap,
                    ]),
                    'error_code' => 'scope_overflow',
                    'previewed_at' => $now,
                    'completed_at' => $now,
                ]);
            }

            $run = EmailTicketConversationLinkMigrationRun::query()->create([
                'public_id' => (string) Str::uuid(),
                'requested_by' => $lockedActor->id,
                'status' => EmailTicketConversationLinkMigrationRun::STATUS_PREVIEWED,
                'item_cap' => $itemCap,
                'candidate_count' => $candidateCount,
                'scope_fingerprint' => hash('sha256', 'pending'),
                'previewed_at' => $now,
            ]);

            foreach ($candidateIds as $messageId) {
                $evidence = $this->inspectMessage($messageId);

                EmailTicketConversationLinkMigrationItem::query()->create([
                    'run_id' => $run->id,
                    'email_message_id' => $messageId,
                    'ticket_id' => $evidence['ticket_id'],
                    'account_id' => $evidence['account_id'],
                    'email_mailbox_placement_id' => $evidence['placement_id'],
                    'email_conversation_id' => $evidence['conversation_id'],
                    'ticket_message_id' => $evidence['ticket_message_id'],
                    'applied_link_id' => $evidence['link_id'],
                    'status' => $evidence['status'],
                    'reason_code' => $evidence['reason_code'],
                    'audience' => $evidence['audience'],
                    'base_fingerprint' => $evidence['base_fingerprint'],
                    'source_fingerprint' => $evidence['source_fingerprint'],
                    'evidence' => $evidence['evidence'],
                ]);
            }

            $items = $run->items()->orderBy('id')->get();
            $run->forceFill([
                'scope_fingerprint' => $this->frozenScopeFingerprint($items),
            ])->save();
            $this->refreshCounts($run);

            if ($candidateCount === 0) {
                $run->forceFill([
                    'status' => EmailTicketConversationLinkMigrationRun::STATUS_COMPLETED,
                    'completed_at' => $now,
                ])->save();
            } elseif ($run->conflict_count > 0 || $run->failed_count > 0) {
                $run->forceFill([
                    'status' => EmailTicketConversationLinkMigrationRun::STATUS_BLOCKED,
                    'error_code' => 'preview_conflicts',
                    'completed_at' => $now,
                ])->save();
            } elseif ($run->ready_count === 0) {
                $run->forceFill([
                    'status' => EmailTicketConversationLinkMigrationRun::STATUS_COMPLETED,
                    'completed_at' => $now,
                ])->save();
            }

            return $run->fresh('items');
        });
    }

    public function queueApply(
        EmailTicketConversationLinkMigrationRun $run,
        User $actor,
    ): EmailTicketConversationLinkMigrationRun {
        $this->authorize($actor);
        $this->assertSchemaReady();

        $queued = DB::transaction(function () use ($actor, $run): EmailTicketConversationLinkMigrationRun {
            $lockedRun = EmailTicketConversationLinkMigrationRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            $this->authorize($lockedActor);

            if ((int) $lockedRun->requested_by !== (int) $lockedActor->id) {
                throw new AuthorizationException('The migration preview belongs to another operator.');
            }

            // Recalculate the mutable progress counters under the run lock. The
            // frozen item identities and hashes remain bound by the scope hash.
            $this->refreshCounts($lockedRun);
            if ($lockedRun->status !== EmailTicketConversationLinkMigrationRun::STATUS_PREVIEWED
                || $lockedRun->conflict_count !== 0
                || $lockedRun->failed_count !== 0
                || $lockedRun->ready_count < 1) {
                throw ValidationException::withMessages([
                    'apply' => 'Only an unblocked frozen preview with ready items can be applied.',
                ]);
            }
            if ($lockedRun->previewed_at?->lt(now()->subMinutes(self::PREVIEW_TTL_MINUTES))) {
                throw ValidationException::withMessages([
                    'apply' => 'The migration preview has expired. Create and review a new preview.',
                ]);
            }

            $items = $lockedRun->items()->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($lockedRun->scope_fingerprint, $this->frozenScopeFingerprint($items))) {
                throw new RuntimeException('email_ticket_link_migration_preview_tampered');
            }

            $lockedRun->forceFill([
                'status' => EmailTicketConversationLinkMigrationRun::STATUS_QUEUED,
                'queued_at' => now(),
                'error_code' => null,
            ])->save();

            return $lockedRun->fresh();
        });

        try {
            BackfillEmailTicketConversationLinks::dispatch($queued->id, $actor->id);
        } catch (Throwable $exception) {
            if ($this->markInitialDispatchFailed((int) $queued->id)) {
                throw new RuntimeException(
                    'email_ticket_link_migration_dispatch_failed',
                    0,
                    $exception,
                );
            }

            // A synchronous queue may have run and durably failed the item. Do
            // not hide its more precise terminal evidence as a dispatch error.
            throw $exception;
        }

        return $queued->fresh();
    }

    /**
     * Apply one bounded page. The caller dispatches another page only after this
     * transaction has committed and the durable run still has ready items.
     */
    public function processBatch(int $runId, int $actorId): bool
    {
        $this->assertSchemaReady();
        $currentItemId = null;

        try {
            $result = DB::transaction(function () use ($actorId, $runId, &$currentItemId): array {
                $run = EmailTicketConversationLinkMigrationRun::query()
                    ->whereKey($runId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($run->status === EmailTicketConversationLinkMigrationRun::STATUS_COMPLETED) {
                    return ['more' => false, 'error_code' => null];
                }
                if (! in_array($run->status, [
                    EmailTicketConversationLinkMigrationRun::STATUS_QUEUED,
                    EmailTicketConversationLinkMigrationRun::STATUS_RUNNING,
                ], true)) {
                    throw new RuntimeException('email_ticket_link_migration_run_not_runnable');
                }

                $actor = User::query()->whereKey($actorId)->lockForUpdate()->first();
                $this->authorize($actor);
                if ((int) $run->requested_by !== (int) $actor->id) {
                    throw new AuthorizationException('The migration actor no longer matches the preview.');
                }
                if ($run->started_at === null
                    && $run->previewed_at?->lt(now()->subMinutes(self::PREVIEW_TTL_MINUTES))) {
                    throw new RuntimeException('email_ticket_link_migration_preview_expired');
                }

                $allItems = $run->items()->orderBy('id')->get();
                if (! hash_equals($run->scope_fingerprint, $this->frozenScopeFingerprint($allItems))) {
                    throw new RuntimeException('email_ticket_link_migration_preview_tampered');
                }

                $run->forceFill([
                    'status' => EmailTicketConversationLinkMigrationRun::STATUS_RUNNING,
                    'started_at' => $run->started_at ?: now(),
                    'error_code' => null,
                ])->save();

                $items = $run->items()
                    ->where('status', EmailTicketConversationLinkMigrationItem::STATUS_READY)
                    ->orderBy('id')
                    ->limit(self::APPLY_BATCH_SIZE)
                    ->lockForUpdate()
                    ->get();

                foreach ($items as $item) {
                    $currentItemId = (int) $item->id;
                    $current = $this->inspectMessage((int) $item->email_message_id, true);

                    if ($current['status'] === EmailTicketConversationLinkMigrationItem::STATUS_ALREADY_MAPPED
                        && hash_equals($item->base_fingerprint, $current['base_fingerprint'])) {
                        $item->forceFill([
                            'status' => EmailTicketConversationLinkMigrationItem::STATUS_ALREADY_MAPPED,
                            'reason_code' => 'concurrent_authoritative_link',
                            'applied_link_id' => $current['link_id'],
                            'applied_at' => now(),
                        ])->save();

                        continue;
                    }

                    if ($current['status'] !== EmailTicketConversationLinkMigrationItem::STATUS_READY
                        || ! hash_equals($item->base_fingerprint, $current['base_fingerprint'])
                        || ! hash_equals($item->source_fingerprint, $current['source_fingerprint'])) {
                        $item->forceFill([
                            'status' => EmailTicketConversationLinkMigrationItem::STATUS_STALE,
                            'reason_code' => 'source_changed_after_preview',
                        ])->save();
                        $this->refreshCounts($run);
                        $run->forceFill([
                            'status' => EmailTicketConversationLinkMigrationRun::STATUS_FAILED,
                            'error_code' => 'item_stale',
                            'completed_at' => now(),
                        ])->save();

                        return ['more' => false, 'error_code' => 'item_stale'];
                    }

                    // Serialize every backfill writer for this account-scoped conversation.
                    DB::table('email_conversations')
                        ->where('id', $current['conversation_id'])
                        ->lockForUpdate()
                        ->first();
                    $lockedCurrent = $this->inspectMessage((int) $item->email_message_id, true);
                    if ($lockedCurrent['status'] !== EmailTicketConversationLinkMigrationItem::STATUS_READY
                        || ! hash_equals($item->source_fingerprint, $lockedCurrent['source_fingerprint'])) {
                        $item->forceFill([
                            'status' => EmailTicketConversationLinkMigrationItem::STATUS_STALE,
                            'reason_code' => 'source_changed_while_locking',
                        ])->save();
                        $this->refreshCounts($run);
                        $run->forceFill([
                            'status' => EmailTicketConversationLinkMigrationRun::STATUS_FAILED,
                            'error_code' => 'item_stale',
                            'completed_at' => now(),
                        ])->save();

                        return ['more' => false, 'error_code' => 'item_stale'];
                    }

                    $linkId = $this->insertLink($item, $actor, $lockedCurrent);
                    $item->forceFill([
                        'status' => EmailTicketConversationLinkMigrationItem::STATUS_APPLIED,
                        'reason_code' => null,
                        'applied_link_id' => $linkId,
                        'applied_at' => now(),
                    ])->save();
                }

                $this->refreshCounts($run);
                $more = $run->ready_count > 0;

                if (! $more) {
                    $run->forceFill([
                        'status' => EmailTicketConversationLinkMigrationRun::STATUS_COMPLETED,
                        'completed_at' => now(),
                    ])->save();
                }

                return ['more' => $more, 'error_code' => null];
            });
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof AuthorizationException
                ? 'actor_unavailable'
                : 'apply_failed';
            if ($exception instanceof RuntimeException
                && str_starts_with($exception->getMessage(), 'email_ticket_link_migration_')) {
                $errorCode = Str::after($exception->getMessage(), 'email_ticket_link_migration_');
            }
            $this->markFailed($runId, $currentItemId, $errorCode);

            throw new RuntimeException('email_ticket_link_migration_'.$errorCode, 0, $exception);
        }

        if ($result['error_code'] !== null) {
            throw new RuntimeException('email_ticket_link_migration_'.$result['error_code']);
        }

        return $result['more'];
    }

    /**
     * Record the uncertain queue boundary after a committed bounded page.
     * Ready rows remain untouched so a newly reviewed frozen preview can
     * classify completed links as mapped and safely resume the remainder.
     */
    public function markContinuationDispatchFailed(int $runId): bool
    {
        $this->assertSchemaReady();

        return DB::transaction(function () use ($runId): bool {
            $run = EmailTicketConversationLinkMigrationRun::query()
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();
            if (! $run || $run->status !== EmailTicketConversationLinkMigrationRun::STATUS_RUNNING) {
                return false;
            }

            $this->refreshCounts($run);
            if ($run->ready_count < 1) {
                return false;
            }

            $run->forceFill([
                'status' => EmailTicketConversationLinkMigrationRun::STATUS_FAILED,
                'error_code' => 'continuation_dispatch_failed',
                'completed_at' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * The queue's final-attempt hook covers failures before processBatch can
     * establish its own precise outcome. Existing terminal evidence wins.
     */
    public function markWorkerFailed(int $runId): bool
    {
        $this->assertSchemaReady();

        return DB::transaction(function () use ($runId): bool {
            $run = EmailTicketConversationLinkMigrationRun::query()
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();
            if (! $run || ! in_array($run->status, [
                EmailTicketConversationLinkMigrationRun::STATUS_QUEUED,
                EmailTicketConversationLinkMigrationRun::STATUS_RUNNING,
            ], true)) {
                return false;
            }

            $this->refreshCounts($run);
            $run->forceFill([
                'status' => EmailTicketConversationLinkMigrationRun::STATUS_FAILED,
                'error_code' => 'worker_failed',
                'completed_at' => now(),
            ])->save();

            return true;
        });
    }

    private function insertLink(
        EmailTicketConversationLinkMigrationItem $item,
        User $actor,
        array $current,
    ): int {
        $now = now();
        DB::table('email_ticket_conversation_links')->insertOrIgnore([
            'ticket_id' => $current['ticket_id'],
            'email_message_id' => $item->email_message_id,
            'email_mailbox_placement_id' => $current['placement_id'],
            'account_id' => $current['account_id'],
            'email_conversation_id' => $current['conversation_id'],
            'linked_by' => $actor->id,
            'conversation_key' => $current['conversation_key'],
            'relationship_role' => EmailTicketConversationLink::ROLE_PRIMARY,
            'audience' => $current['audience'],
            'status' => EmailTicketConversationLink::STATUS_ACTIVE,
            'metadata' => json_encode([
                'source' => 'legacy_ticket_pointer_migration',
                'migration_run_id' => $item->run_id,
                'migration_item_id' => $item->id,
                'ticket_message_id' => $current['ticket_message_id'],
                'evidence_fingerprint' => $current['source_fingerprint'],
            ], JSON_THROW_ON_ERROR),
            'linked_at' => $now,
            'unlinked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $link = DB::table('email_ticket_conversation_links')
            ->where('ticket_id', $current['ticket_id'])
            ->where('email_message_id', $item->email_message_id)
            ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
            ->first();

        if (! $link
            || (int) $link->account_id !== (int) $current['account_id']
            || (int) $link->email_conversation_id !== (int) $current['conversation_id']
            || $link->relationship_role !== EmailTicketConversationLink::ROLE_PRIMARY) {
            throw new RuntimeException('email_ticket_link_migration_insert_conflict');
        }

        return (int) $link->id;
    }

    /**
     * Inspect metadata and durable IDs only. Message/Ticket bodies, subjects,
     * participants, attachment names and provider credentials never enter the ledger.
     *
     * @return array<string, mixed>
     */
    private function inspectMessage(int $messageId, bool $lock = false): array
    {
        $messageQuery = DB::table('email_messages')->where('id', $messageId);
        $message = ($lock ? $messageQuery->lockForUpdate() : $messageQuery)->first();
        $base = ['message' => $this->rowEvidence($message, [
            'id', 'account_id', 'ticket_id', 'mailbox', 'imap_uid_validity', 'imap_uid',
            'state', 'deleted_at', 'updated_at',
        ])];

        if (! $message) {
            return $this->inspectionResult(
                EmailTicketConversationLinkMigrationItem::STATUS_FAILED,
                'missing_message',
                $base,
                messageId: $messageId,
            );
        }
        if ($message->deleted_at !== null) {
            return $this->inspectionResult(
                EmailTicketConversationLinkMigrationItem::STATUS_FAILED,
                'message_deleted',
                $base,
                messageId: $messageId,
                ticketId: (int) $message->ticket_id,
                accountId: (int) $message->account_id,
            );
        }

        $ticketQuery = DB::table('tickets')->where('id', $message->ticket_id);
        $ticket = ($lock ? $ticketQuery->lockForUpdate() : $ticketQuery)->first();
        $base['ticket'] = $this->rowEvidence($ticket, [
            'id', 'merged_into_ticket_id', 'deleted_at',
        ]);
        if (! $ticket || $ticket->deleted_at !== null || $ticket->merged_into_ticket_id !== null) {
            return $this->inspectionResult(
                EmailTicketConversationLinkMigrationItem::STATUS_MISSING_TICKET,
                $ticket ? 'ticket_inactive' : 'ticket_missing',
                $base,
                messageId: $messageId,
                ticketId: (int) $message->ticket_id,
                accountId: (int) $message->account_id,
            );
        }

        $placementQuery = DB::table('email_mailbox_placements')
            ->where('email_message_id', $messageId)
            ->where('account_id', $message->account_id)
            ->where('local_state', 'active')
            ->whereNull('provider_missing_at')
            ->whereNotNull('email_conversation_id')
            ->orderBy('id');
        $placements = ($lock ? $placementQuery->lockForUpdate() : $placementQuery)->get();
        $base['placements'] = $placements->map(fn (object $row): array => $this->rowEvidence($row, [
            'id', 'email_message_id', 'account_id', 'email_conversation_id', 'folder_path',
            'imap_uid_validity', 'imap_uid', 'local_state', 'provider_missing_at', 'updated_at',
        ]))->all();

        if ($placements->pluck('email_conversation_id')->unique()->count() > 1) {
            return $this->inspectionResult(
                EmailTicketConversationLinkMigrationItem::STATUS_ACCOUNT_CONFLICT,
                'active_placement_conversation_conflict',
                $base,
                messageId: $messageId,
                ticketId: (int) $message->ticket_id,
                accountId: (int) $message->account_id,
            );
        }

        $placement = $this->selectExactPlacement($message, $placements);

        if (! $placement) {
            $status = $placements->isEmpty()
                ? EmailTicketConversationLinkMigrationItem::STATUS_MISSING_CONVERSATION
                : EmailTicketConversationLinkMigrationItem::STATUS_ACCOUNT_CONFLICT;

            return $this->inspectionResult(
                $status,
                $placements->isEmpty() ? 'active_conversation_placement_missing' : 'active_placement_ambiguous',
                $base,
                messageId: $messageId,
                ticketId: (int) $message->ticket_id,
                accountId: (int) $message->account_id,
            );
        }

        $conversationQuery = DB::table('email_conversations')->where('id', $placement->email_conversation_id);
        $conversation = ($lock ? $conversationQuery->lockForUpdate() : $conversationQuery)->first();
        $base['conversation'] = $this->rowEvidence($conversation, [
            'id', 'account_id', 'conversation_key', 'status', 'updated_at',
        ]);
        if (! $conversation) {
            return $this->inspectionResult(
                EmailTicketConversationLinkMigrationItem::STATUS_MISSING_CONVERSATION,
                'conversation_missing',
                $base,
                messageId: $messageId,
                ticketId: (int) $message->ticket_id,
                accountId: (int) $message->account_id,
                placementId: (int) $placement->id,
            );
        }
        if ((int) $conversation->account_id !== (int) $message->account_id
            || (int) $placement->account_id !== (int) $message->account_id
            || $conversation->status !== 'active') {
            return $this->inspectionResult(
                EmailTicketConversationLinkMigrationItem::STATUS_ACCOUNT_CONFLICT,
                'conversation_account_or_status_conflict',
                $base,
                messageId: $messageId,
                ticketId: (int) $message->ticket_id,
                accountId: (int) $message->account_id,
                placementId: (int) $placement->id,
                conversationId: (int) $conversation->id,
            );
        }

        $primaryTicketIds = $this->primaryTicketIdsForConversation((int) $conversation->id, $lock);
        $linksQuery = DB::table('email_ticket_conversation_links')
            ->where('email_conversation_id', $conversation->id)
            ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
            ->orderBy('id');
        $links = ($lock ? $linksQuery->lockForUpdate() : $linksQuery)->get();
        $source = $base + [
            'primary_ticket_ids' => $primaryTicketIds,
            'active_links' => $links->map(fn (object $row): array => $this->rowEvidence($row, [
                'id', 'ticket_id', 'email_message_id', 'account_id', 'email_conversation_id',
                'relationship_role', 'audience', 'status', 'updated_at',
            ]))->all(),
        ];

        if ($links->contains(
            fn (object $link): bool => (int) $link->account_id !== (int) $message->account_id,
        )) {
            return $this->inspectionResult(
                EmailTicketConversationLinkMigrationItem::STATUS_ACCOUNT_CONFLICT,
                'active_link_account_conflict',
                $source,
                messageId: $messageId,
                ticketId: (int) $message->ticket_id,
                accountId: (int) $message->account_id,
                placementId: (int) $placement->id,
                conversationId: (int) $conversation->id,
                conversationKey: (string) $conversation->conversation_key,
            );
        }

        if ($primaryTicketIds !== [(int) $message->ticket_id]) {
            return $this->inspectionResult(
                EmailTicketConversationLinkMigrationItem::STATUS_PRIMARY_CONFLICT,
                'competing_primary_ticket_evidence',
                $source,
                messageId: $messageId,
                ticketId: (int) $message->ticket_id,
                accountId: (int) $message->account_id,
                placementId: (int) $placement->id,
                conversationId: (int) $conversation->id,
                conversationKey: (string) $conversation->conversation_key,
            );
        }

        $sameTargetPrimary = $links
            ->first(fn (object $link): bool => (int) $link->ticket_id === (int) $message->ticket_id
                && (int) $link->account_id === (int) $message->account_id
                && $link->relationship_role === EmailTicketConversationLink::ROLE_PRIMARY);
        if ($sameTargetPrimary) {
            return $this->inspectionResult(
                EmailTicketConversationLinkMigrationItem::STATUS_ALREADY_MAPPED,
                'authoritative_conversation_link_exists',
                $source,
                messageId: $messageId,
                ticketId: (int) $message->ticket_id,
                accountId: (int) $message->account_id,
                placementId: (int) $placement->id,
                conversationId: (int) $conversation->id,
                conversationKey: (string) $conversation->conversation_key,
                linkId: (int) $sameTargetPrimary->id,
            );
        }

        $sameMessageReference = $links->first(
            fn (object $link): bool => (int) $link->ticket_id === (int) $message->ticket_id
                && (int) $link->email_message_id === $messageId,
        );
        if ($sameMessageReference) {
            return $this->inspectionResult(
                EmailTicketConversationLinkMigrationItem::STATUS_PRIMARY_CONFLICT,
                'existing_reference_requires_review',
                $source,
                messageId: $messageId,
                ticketId: (int) $message->ticket_id,
                accountId: (int) $message->account_id,
                placementId: (int) $placement->id,
                conversationId: (int) $conversation->id,
                conversationKey: (string) $conversation->conversation_key,
                linkId: (int) $sameMessageReference->id,
            );
        }

        $ticketMessageQuery = DB::table('ticket_messages')
            ->where('ticket_id', $message->ticket_id)
            ->where('source_inbound_email_message_id', $messageId)
            ->where('inbound_email_message_id', $messageId)
            ->orderBy('id');
        $ticketMessages = ($lock ? $ticketMessageQuery->lockForUpdate() : $ticketMessageQuery)->get();
        $source['ticket_messages'] = $ticketMessages->map(fn (object $row): array => $this->rowEvidence($row, [
            'id', 'ticket_id', 'source_inbound_email_message_id', 'inbound_email_message_id',
            'visibility', 'deleted_at', 'updated_at',
        ]))->all();

        if ($ticketMessages->count() !== 1 || $ticketMessages->first()->deleted_at !== null) {
            return $this->inspectionResult(
                EmailTicketConversationLinkMigrationItem::STATUS_FAILED,
                'missing_capture_provenance',
                $source,
                messageId: $messageId,
                ticketId: (int) $message->ticket_id,
                accountId: (int) $message->account_id,
                placementId: (int) $placement->id,
                conversationId: (int) $conversation->id,
                conversationKey: (string) $conversation->conversation_key,
            );
        }

        $ticketMessage = $ticketMessages->first();
        $audience = match ($ticketMessage->visibility) {
            'public' => EmailTicketConversationLink::AUDIENCE_CUSTOMER,
            'internal' => EmailTicketConversationLink::AUDIENCE_INTERNAL,
            default => null,
        };
        if ($audience === null) {
            return $this->inspectionResult(
                EmailTicketConversationLinkMigrationItem::STATUS_AUDIENCE_CONFLICT,
                'capture_audience_unrecognized',
                $source,
                messageId: $messageId,
                ticketId: (int) $message->ticket_id,
                accountId: (int) $message->account_id,
                placementId: (int) $placement->id,
                conversationId: (int) $conversation->id,
                conversationKey: (string) $conversation->conversation_key,
                ticketMessageId: (int) $ticketMessage->id,
            );
        }

        return $this->inspectionResult(
            EmailTicketConversationLinkMigrationItem::STATUS_READY,
            'legacy_pointer_and_capture_proven',
            $source,
            messageId: $messageId,
            ticketId: (int) $message->ticket_id,
            accountId: (int) $message->account_id,
            placementId: (int) $placement->id,
            conversationId: (int) $conversation->id,
            conversationKey: (string) $conversation->conversation_key,
            ticketMessageId: (int) $ticketMessage->id,
            audience: $audience,
        );
    }

    /** @param Collection<int, object> $placements */
    private function selectExactPlacement(object $message, Collection $placements): ?object
    {
        if ($placements->count() === 1) {
            return $placements->first();
        }

        $exact = $placements->filter(function (object $placement) use ($message): bool {
            if ((string) $placement->folder_path !== (string) $message->mailbox
                || (int) $placement->imap_uid !== (int) $message->imap_uid) {
                return false;
            }

            return $message->imap_uid_validity === null
                || $placement->imap_uid_validity === null
                || (int) $placement->imap_uid_validity === (int) $message->imap_uid_validity;
        });

        return $exact->count() === 1 ? $exact->first() : null;
    }

    /** @return list<int> */
    private function primaryTicketIdsForConversation(int $conversationId, bool $lock): array
    {
        $legacyQuery = DB::table('email_messages')
            ->join(
                'email_mailbox_placements',
                'email_mailbox_placements.email_message_id',
                '=',
                'email_messages.id',
            )
            ->where('email_mailbox_placements.email_conversation_id', $conversationId)
            ->where('email_mailbox_placements.local_state', 'active')
            ->whereNull('email_mailbox_placements.provider_missing_at')
            ->whereNull('email_messages.deleted_at')
            ->whereNotNull('email_messages.ticket_id')
            ->select('email_messages.ticket_id');
        $linkQuery = DB::table('email_ticket_conversation_links')
            ->where('email_conversation_id', $conversationId)
            ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
            ->where('relationship_role', EmailTicketConversationLink::ROLE_PRIMARY)
            ->select('ticket_id');

        $legacyIds = ($lock ? $legacyQuery->lockForUpdate() : $legacyQuery)
            ->pluck('ticket_id');
        $linkIds = ($lock ? $linkQuery->lockForUpdate() : $linkQuery)
            ->pluck('ticket_id');

        return $legacyIds
            ->merge($linkIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function inspectionResult(
        string $status,
        string $reasonCode,
        array $evidence,
        int $messageId,
        ?int $ticketId = null,
        ?int $accountId = null,
        ?int $placementId = null,
        ?int $conversationId = null,
        ?string $conversationKey = null,
        ?int $ticketMessageId = null,
        ?int $linkId = null,
        ?string $audience = null,
    ): array {
        // Base identity deliberately excludes relationship and Ticket-message
        // projections. It therefore remains comparable when an authoritative
        // link wins concurrently after preview, while source_fingerprint still
        // detects every relevant projection change before this migrator writes.
        $baseEvidence = array_intersect_key($evidence, array_flip([
            'message',
            'ticket',
            'placements',
            'conversation',
        ]));

        return [
            'status' => $status,
            'reason_code' => $reasonCode,
            'message_id' => $messageId,
            'ticket_id' => $ticketId,
            'account_id' => $accountId,
            'placement_id' => $placementId,
            'conversation_id' => $conversationId,
            'conversation_key' => $conversationKey,
            'ticket_message_id' => $ticketMessageId,
            'link_id' => $linkId,
            'audience' => $audience,
            'base_fingerprint' => $this->fingerprint($baseEvidence),
            'source_fingerprint' => $this->fingerprint($evidence),
            'evidence' => $this->sanitizedEvidence($evidence),
        ];
    }

    private function authorize(?User $actor): void
    {
        if (! $actor?->isActive()
            || $actor->isSystemActor()
            || ! $actor->can('email.account_manage')
            || ! $actor->can('email.mailbox_sync_manage')
            || ! $actor->can('ticket.update')) {
            throw new AuthorizationException('Email/Ticket relationship migration is unavailable.');
        }
    }

    private function assertSchemaReady(): void
    {
        foreach ([
            'email_ticket_conversation_link_migration_runs',
            'email_ticket_conversation_link_migration_items',
            'email_ticket_conversation_links',
            'email_conversations',
            'email_mailbox_placements',
            'email_messages',
            'ticket_messages',
            'tickets',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('email_ticket_link_migration_schema_missing');
            }
        }
    }

    /** @param Collection<int, EmailTicketConversationLinkMigrationItem> $items */
    private function frozenScopeFingerprint(Collection $items): string
    {
        return $this->fingerprint($items->map(fn (EmailTicketConversationLinkMigrationItem $item): array => [
            'email_message_id' => (int) $item->email_message_id,
            'ticket_id' => $item->ticket_id,
            'account_id' => $item->account_id,
            'placement_id' => $item->email_mailbox_placement_id,
            'conversation_id' => $item->email_conversation_id,
            'ticket_message_id' => $item->ticket_message_id,
            'base_fingerprint' => $item->base_fingerprint,
            'source_fingerprint' => $item->source_fingerprint,
        ])->all());
    }

    private function refreshCounts(EmailTicketConversationLinkMigrationRun $run): void
    {
        $counts = $run->items()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count);

        $run->forceFill([
            'ready_count' => $counts->get(EmailTicketConversationLinkMigrationItem::STATUS_READY, 0),
            'already_mapped_count' => $counts->get(EmailTicketConversationLinkMigrationItem::STATUS_ALREADY_MAPPED, 0),
            'conflict_count' => collect(self::CONFLICT_STATUSES)->sum(fn (string $status): int => $counts->get($status, 0)),
            'applied_count' => $counts->get(EmailTicketConversationLinkMigrationItem::STATUS_APPLIED, 0),
            'failed_count' => $counts->get(EmailTicketConversationLinkMigrationItem::STATUS_FAILED, 0)
                + $counts->get(EmailTicketConversationLinkMigrationItem::STATUS_STALE, 0),
        ])->save();
        $run->refresh();
    }

    private function markFailed(int $runId, ?int $itemId, string $errorCode): void
    {
        DB::transaction(function () use ($errorCode, $itemId, $runId): void {
            $run = EmailTicketConversationLinkMigrationRun::query()
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();
            if (! $run || in_array($run->status, [
                EmailTicketConversationLinkMigrationRun::STATUS_COMPLETED,
                EmailTicketConversationLinkMigrationRun::STATUS_FAILED,
            ], true)) {
                return;
            }

            if ($itemId !== null) {
                $item = $run->items()->whereKey($itemId)->lockForUpdate()->first();
                if ($item && $item->status === EmailTicketConversationLinkMigrationItem::STATUS_READY) {
                    $item->forceFill([
                        'status' => EmailTicketConversationLinkMigrationItem::STATUS_FAILED,
                        'reason_code' => $errorCode,
                    ])->save();
                }
            }

            $this->refreshCounts($run);
            $run->forceFill([
                'status' => EmailTicketConversationLinkMigrationRun::STATUS_FAILED,
                'error_code' => Str::limit($errorCode, 80, ''),
                'completed_at' => now(),
            ])->save();
        });
    }

    private function markInitialDispatchFailed(int $runId): bool
    {
        return DB::transaction(function () use ($runId): bool {
            $run = EmailTicketConversationLinkMigrationRun::query()
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();
            if (! $run || $run->status !== EmailTicketConversationLinkMigrationRun::STATUS_QUEUED) {
                return false;
            }

            $run->forceFill([
                'status' => EmailTicketConversationLinkMigrationRun::STATUS_FAILED,
                'error_code' => 'dispatch_failed',
                'completed_at' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * Keep the durable ledger metadata-only. Provider occurrence identifiers
     * and private folder/conversation paths still participate in the transient
     * source hash, but their plaintext values are never persisted here.
     *
     * @return array<string|int, mixed>
     */
    private function sanitizedEvidence(array $evidence): array
    {
        $sensitiveKeys = [
            'conversation_key',
            'folder_path',
            'imap_uid',
            'imap_uid_validity',
            'mailbox',
        ];
        $sanitized = [];

        foreach ($evidence as $key => $value) {
            if (in_array((string) $key, $sensitiveKeys, true)) {
                continue;
            }

            $sanitized[$key] = is_array($value)
                ? $this->sanitizedEvidence($value)
                : $value;
        }

        return $sanitized;
    }

    /** @param list<string> $columns */
    private function rowEvidence(?object $row, array $columns): ?array
    {
        if (! $row) {
            return null;
        }

        $evidence = [];
        foreach ($columns as $column) {
            $evidence[$column] = $row->{$column} ?? null;
        }

        return $evidence;
    }

    private function fingerprint(array $evidence): string
    {
        return hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
