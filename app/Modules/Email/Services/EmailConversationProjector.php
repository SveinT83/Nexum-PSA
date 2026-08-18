<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailTicketConversationLink;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EmailConversationProjector
{
    public const ISSUE_AMBIGUOUS_REFERENCE = 'ambiguous_referenced_message';

    public const ISSUE_CONFLICTING_REFERENCES = 'conflicting_referenced_conversations';

    public const ISSUE_REUSED_MESSAGE_ID = 'reused_message_id_conflict';

    public const ISSUE_COMPETING_PRIMARY = 'competing_primary_ticket_links';

    public const ISSUE_ACCOUNT_MISMATCH = 'placement_message_account_mismatch';

    public function available(): bool
    {
        return Schema::hasTable('email_conversations')
            && Schema::hasColumn('email_mailbox_placements', 'email_conversation_id');
    }

    public function assignPlacement(EmailMailboxPlacement $placement): ?EmailConversation
    {
        return $this->assignPlacementWithRefresh($placement, true);
    }

    /**
     * Attach durable conversation identity without publishing pending content
     * through an existing conversation's visible aggregate or preview.
     */
    public function assignPendingPlacement(EmailMailboxPlacement $placement): ?EmailConversation
    {
        return $this->assignPlacementWithRefresh($placement, false);
    }

    private function assignPlacementWithRefresh(
        EmailMailboxPlacement $placement,
        bool $refresh,
    ): ?EmailConversation {
        if (! $this->available()) {
            return null;
        }

        $placement->loadMissing('message');

        if (! $placement->message || ! $placement->account_id) {
            return null;
        }

        $decision = $this->identityDecision($placement, $refresh);

        foreach ($decision['issues'] as $issue) {
            $this->recordCorrelationIssue(
                $issue['type'],
                $placement,
                $placement->email_conversation_id ? (int) $placement->email_conversation_id : null,
                $decision['conversation']?->id,
                $issue['evidence'],
            );
        }

        $conversation = $decision['conversation'];

        if (! $conversation) {
            return $placement->email_conversation_id
                ? $this->conversationAfterAssignment(
                    EmailConversation::query()->find($placement->email_conversation_id),
                    $refresh,
                )
                : null;
        }

        if (! $decision['may_move']) {
            return $placement->email_conversation_id
                ? $this->conversationAfterAssignment(
                    EmailConversation::query()->find($placement->email_conversation_id),
                    $refresh,
                )
                : $this->conversationAfterAssignment($conversation, $refresh);
        }

        $move = $this->relocatePlacement($placement, $conversation, $refresh);

        if ($move['issue']) {
            $this->recordCorrelationIssue(
                $move['issue']['type'],
                $placement,
                $move['old_conversation_id'],
                $conversation->id,
                $move['issue']['evidence'],
                $move['issue']['ticket_link_id'],
            );
        }

        if (! $move['moved'] && $move['old_conversation_id']) {
            return $this->conversationAfterAssignment(
                EmailConversation::query()->find($move['old_conversation_id']),
                $refresh,
            );
        }

        return $this->conversationAfterAssignment($conversation->fresh(), $refresh);
    }

    /**
     * Resolve the durable identity without changing the placement or any provider/user state.
     *
     * @return array{
     *     conversation: ?EmailConversation,
     *     may_move: bool,
     *     issues: list<array{type: string, evidence: array<string, mixed>}>
     * }
     */
    public function identityDecision(
        EmailMailboxPlacement $placement,
        bool $publishInitialContent = true,
    ): array {
        $placement->loadMissing('message');
        $message = $placement->message;

        if (! $message || ! $placement->account_id) {
            return ['conversation' => null, 'may_move' => false, 'issues' => []];
        }

        if ((int) $message->account_id !== (int) $placement->account_id) {
            return [
                'conversation' => null,
                'may_move' => false,
                'issues' => [[
                    'type' => self::ISSUE_ACCOUNT_MISMATCH,
                    'evidence' => [
                        'placement_account_id' => (int) $placement->account_id,
                        'message_account_id' => (int) $message->account_id,
                    ],
                ]],
            ];
        }

        $current = $placement->email_conversation_id
            ? EmailConversation::query()
                ->whereKey($placement->email_conversation_id)
                ->where('account_id', $placement->account_id)
                ->first()
            : null;

        $referenced = $this->referencedConversationDecision($message, (int) $placement->account_id);

        if ($referenced['ambiguous']) {
            $target = $current ?: $this->conversationFor(
                $placement,
                $message,
                $this->ambiguousConversationKey($placement, $message),
                ['projection_mode' => 'isolated_ambiguous_reference'],
                $publishInitialContent,
            );

            return [
                'conversation' => $target,
                'may_move' => $current === null,
                'issues' => [[
                    'type' => $referenced['issue_type'],
                    'evidence' => $referenced['evidence'],
                ]],
            ];
        }

        if ($referenced['conversation']) {
            return [
                'conversation' => $referenced['conversation'],
                'may_move' => true,
                'issues' => [],
            ];
        }

        $key = $this->conversationKey($message);
        $issues = [];

        if ($this->isRootMessage($message)) {
            $collision = $this->rootCollisionDecision($message, (int) $placement->account_id, $key);
            $key = $collision['conversation_key'];

            if ($collision['evidence'] !== null) {
                $issues[] = [
                    'type' => self::ISSUE_REUSED_MESSAGE_ID,
                    'evidence' => $collision['evidence'],
                ];
            }
        }

        return [
            'conversation' => $this->conversationFor(
                $placement,
                $message,
                $key,
                Str::startsWith($key, 'collision:')
                    ? ['projection_mode' => 'isolated_message_id_collision']
                    : [],
                $publishInitialContent,
            ),
            'may_move' => true,
            'issues' => $issues,
        ];
    }

    /**
     * Atomically move a placement and its placement-bound Ticket pointers.
     *
     * @return array{
     *     moved: bool,
     *     old_conversation_id: ?int,
     *     issue: ?array{type: string, evidence: array<string, mixed>, ticket_link_id: ?int}
     * }
     */
    public function relocatePlacement(
        EmailMailboxPlacement $placement,
        EmailConversation $target,
        bool $refresh = true,
    ): array {
        $result = DB::transaction(function () use ($placement, $target): array {
            $lockedPlacement = EmailMailboxPlacement::query()
                ->lockForUpdate()
                ->find($placement->id);
            $lockedTarget = EmailConversation::query()
                ->where('account_id', $placement->account_id)
                ->lockForUpdate()
                ->find($target->id);

            if (! $lockedPlacement || ! $lockedTarget) {
                return [
                    'moved' => false,
                    'old_conversation_id' => $placement->email_conversation_id
                        ? (int) $placement->email_conversation_id
                        : null,
                    'issue' => null,
                ];
            }

            $oldConversationId = $lockedPlacement->email_conversation_id
                ? (int) $lockedPlacement->email_conversation_id
                : null;

            if ((int) $lockedPlacement->account_id !== (int) $lockedTarget->account_id) {
                return [
                    'moved' => false,
                    'old_conversation_id' => $oldConversationId,
                    'issue' => [
                        'type' => self::ISSUE_ACCOUNT_MISMATCH,
                        'evidence' => [
                            'placement_account_id' => (int) $lockedPlacement->account_id,
                            'target_conversation_account_id' => (int) $lockedTarget->account_id,
                        ],
                        'ticket_link_id' => null,
                    ],
                ];
            }

            if ($oldConversationId === (int) $lockedTarget->id) {
                return [
                    'moved' => false,
                    'old_conversation_id' => $oldConversationId,
                    'issue' => null,
                ];
            }

            $primaryConflict = $this->primaryTicketConflict($lockedPlacement, $lockedTarget);

            if ($primaryConflict !== null) {
                return [
                    'moved' => false,
                    'old_conversation_id' => $oldConversationId,
                    'issue' => [
                        'type' => self::ISSUE_COMPETING_PRIMARY,
                        'evidence' => $primaryConflict['evidence'],
                        'ticket_link_id' => $primaryConflict['ticket_link_id'],
                    ],
                ];
            }

            $lockedPlacement->forceFill([
                'email_conversation_id' => $lockedTarget->id,
            ])->save();

            $this->movePlacementBoundTicketPointers($lockedPlacement, $lockedTarget);

            return [
                'moved' => true,
                'old_conversation_id' => $oldConversationId,
                'issue' => null,
            ];
        });

        if ($refresh && $result['old_conversation_id']) {
            $this->refreshConversation(
                EmailConversation::query()->find($result['old_conversation_id']),
            );
        }

        if ($refresh) {
            $this->refreshConversation($target->fresh());
        }

        $placement->refresh();

        return $result;
    }

    private function conversationAfterAssignment(
        ?EmailConversation $conversation,
        bool $refresh,
    ): ?EmailConversation {
        return $refresh ? $this->refreshConversation($conversation) : $conversation?->refresh();
    }

    public function refreshForPlacement(?EmailMailboxPlacement $placement): ?EmailConversation
    {
        if (! $placement || ! $this->available()) {
            return null;
        }

        if ($placement->email_conversation_id) {
            return $this->refreshConversation(
                EmailConversation::query()->find($placement->email_conversation_id),
            );
        }

        return $this->assignPlacement($placement);
    }

    /**
     * Backwards-compatible activation entry point. A pending placement never
     * publishes content, so activation must refresh the complete active-only
     * projection rather than counters alone.
     */
    public function refreshActiveCountersForPlacement(
        ?EmailMailboxPlacement $placement,
    ): ?EmailConversation {
        return $this->refreshForPlacement($placement);
    }

    public function refreshConversation(?EmailConversation $conversation): ?EmailConversation
    {
        if (! $conversation || ! $this->available()) {
            return $conversation;
        }

        return DB::transaction(function () use ($conversation): ?EmailConversation {
            $locked = EmailConversation::query()
                ->lockForUpdate()
                ->find($conversation->id);
            if (! $locked) {
                return null;
            }

            $aggregate = DB::table('email_mailbox_placements')
                ->join(
                    'email_messages',
                    'email_messages.id',
                    '=',
                    'email_mailbox_placements.email_message_id',
                )
                ->where('email_mailbox_placements.email_conversation_id', $locked->id)
                ->where('email_mailbox_placements.local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                ->whereNull('email_mailbox_placements.provider_missing_at')
                ->whereNull('email_messages.deleted_at')
                ->selectRaw('COUNT(*) AS placement_count')
                ->selectRaw('COUNT(DISTINCT email_mailbox_placements.email_message_id) AS message_count')
                ->selectRaw(
                    'SUM(CASE WHEN email_mailbox_placements.provider_seen = ? THEN 1 ELSE 0 END) AS provider_unread_count',
                    [false],
                )
                ->first();

            if (! $aggregate || (int) $aggregate->placement_count === 0) {
                $locked->forceFill([
                    'subject' => null,
                    'message_count' => 0,
                    'active_placement_count' => 0,
                    'provider_unread_count' => 0,
                    'has_attachments' => false,
                    'latest_email_message_id' => null,
                    'latest_email_mailbox_placement_id' => null,
                    'first_email_message_id' => null,
                    'first_message_at' => null,
                    'last_message_at' => null,
                    'metadata' => $this->metadataWithoutLatestFolder($locked->metadata),
                ])->save();

                return $locked->refresh();
            }

            $first = $this->orderedConversationPlacement((int) $locked->id, false);
            $latest = $this->orderedConversationPlacement((int) $locked->id, true);

            if (! $first || ! $latest) {
                throw new \RuntimeException('Active conversation aggregate has no visible endpoint.');
            }

            $hasAttachments = DB::table('email_attachments')
                ->join(
                    'email_mailbox_placements',
                    'email_mailbox_placements.email_message_id',
                    '=',
                    'email_attachments.message_id',
                )
                ->where('email_mailbox_placements.email_conversation_id', $locked->id)
                ->where('email_mailbox_placements.local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                ->whereNull('email_mailbox_placements.provider_missing_at')
                ->join(
                    'email_messages',
                    'email_messages.id',
                    '=',
                    'email_mailbox_placements.email_message_id',
                )
                ->whereNull('email_messages.deleted_at')
                ->exists();

            $locked->forceFill([
                'status' => EmailConversation::STATUS_ACTIVE,
                'subject' => $this->subject($latest->message),
                'first_email_message_id' => $first->email_message_id,
                'latest_email_message_id' => $latest->email_message_id,
                'latest_email_mailbox_placement_id' => $latest->id,
                'message_count' => (int) $aggregate->message_count,
                'active_placement_count' => (int) $aggregate->placement_count,
                'provider_unread_count' => (int) $aggregate->provider_unread_count,
                'has_attachments' => $hasAttachments,
                'first_message_at' => $this->messageDate($first->message),
                'last_message_at' => $this->messageDate($latest->message),
                'metadata' => array_filter([
                    ...($locked->metadata ?? []),
                    'source' => 'mail_header_projection',
                    'latest_folder_path' => $latest->folder_path,
                ]),
            ])->save();

            return $locked->refresh();
        }, 3);
    }

    /**
     * Resolve one endpoint placement without hydrating the whole provider-
     * controlled conversation. Soft-deleted messages remain excluded exactly
     * as they were when the Eloquent relationship supplied the old ordering.
     */
    private function orderedConversationPlacement(
        int $conversationId,
        bool $latest,
    ): ?EmailMailboxPlacement {
        $direction = $latest ? 'desc' : 'asc';

        return EmailMailboxPlacement::query()
            ->select('email_mailbox_placements.*')
            ->join(
                'email_messages',
                'email_messages.id',
                '=',
                'email_mailbox_placements.email_message_id',
            )
            ->with('message')
            ->where('email_mailbox_placements.email_conversation_id', $conversationId)
            ->where('email_mailbox_placements.local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('email_mailbox_placements.provider_missing_at')
            ->whereNull('email_messages.deleted_at')
            ->orderByRaw(
                'COALESCE(email_messages.received_at, email_messages.created_at) '.$direction,
            )
            ->orderBy('email_mailbox_placements.id', $direction)
            ->first();
    }

    public function conversationKey(EmailMessage $message): string
    {
        $identifier = $this->referencesIdentifiers($message)[0]
            ?? $this->inReplyToIdentifiers($message)[0]
            ?? $this->normalizeMessageIdentifier($message->message_id);

        if ($identifier !== '') {
            return 'msg:'.hash('sha256', $identifier);
        }

        $receivedDate = $message->received_at?->toDateString()
            ?? $message->created_at?->toDateString()
            ?? 'unknown-date';

        $fallback = Str::lower((string) $message->from_email).'|'
            .Str::lower((string) $message->subject).'|'
            .$receivedDate;

        return 'fallback:'.hash('sha256', $fallback);
    }

    /**
     * Store an idempotent, reviewable explanation whenever projection cannot safely guess.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function recordCorrelationIssue(
        string $issueType,
        EmailMailboxPlacement $placement,
        ?int $sourceConversationId,
        ?int $targetConversationId,
        array $evidence,
        ?int $ticketLinkId = null,
    ): ?int {
        if (! Schema::hasTable('email_conversation_correlation_issues')) {
            return null;
        }

        $fingerprintPayload = $this->canonicalize([
            'issue_type' => $issueType,
            'account_id' => (int) $placement->account_id,
            'email_message_id' => $placement->email_message_id
                ? (int) $placement->email_message_id
                : null,
            'email_mailbox_placement_id' => (int) $placement->id,
            'source_email_conversation_id' => $sourceConversationId,
            'target_email_conversation_id' => $targetConversationId,
            'email_ticket_conversation_link_id' => $ticketLinkId,
            'evidence' => $evidence,
        ]);
        $fingerprint = hash('sha256', json_encode(
            $fingerprintPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        $existing = DB::table('email_conversation_correlation_issues')
            ->where('fingerprint', $fingerprint)
            ->first(['id', 'occurrences']);
        $now = now();

        if ($existing) {
            DB::table('email_conversation_correlation_issues')
                ->where('id', $existing->id)
                ->update([
                    'status' => 'open',
                    'occurrences' => ((int) $existing->occurrences) + 1,
                    'evidence_json' => json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'last_detected_at' => $now,
                    'resolved_at' => null,
                    'updated_at' => $now,
                ]);

            return (int) $existing->id;
        }

        return (int) DB::table('email_conversation_correlation_issues')->insertGetId([
            'fingerprint' => $fingerprint,
            'issue_type' => $issueType,
            'status' => 'open',
            'account_id' => $placement->account_id,
            'email_message_id' => $placement->email_message_id,
            'email_mailbox_placement_id' => $placement->id,
            'source_email_conversation_id' => $sourceConversationId,
            'target_email_conversation_id' => $targetConversationId,
            'email_ticket_conversation_link_id' => $ticketLinkId,
            'occurrences' => 1,
            'evidence_json' => json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'first_detected_at' => $now,
            'last_detected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array{
     *     conversation: ?EmailConversation,
     *     ambiguous: bool,
     *     issue_type: string,
     *     evidence: array<string, mixed>
     * }
     */
    private function referencedConversationDecision(EmailMessage $message, int $accountId): array
    {
        $referenceEntries = collect($this->referencesIdentifiers($message))
            ->map(fn (string $identifier, int $index): array => [
                'identifier' => $identifier,
                'source' => 'references',
                'position' => $index,
            ])
            ->merge(collect($this->inReplyToIdentifiers($message))->map(
                fn (string $identifier, int $index): array => [
                    'identifier' => $identifier,
                    'source' => 'in_reply_to',
                    'position' => $index,
                ],
            ))
            ->unique('identifier')
            ->values();

        $resolved = [];
        $ambiguous = [];

        foreach ($referenceEntries as $entry) {
            $matches = $this->messagesMatchingIdentifier(
                $accountId,
                $entry['identifier'],
                (int) $message->id,
            );

            if ($matches->count() > 1) {
                $ambiguous[] = [
                    ...$entry,
                    'matched_message_ids' => $matches->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                ];

                continue;
            }

            $matchedMessage = $matches->first();

            if (! $matchedMessage) {
                continue;
            }

            $conversationIds = $matchedMessage->placements
                ->pluck('email_conversation_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            if ($conversationIds->count() > 1) {
                $ambiguous[] = [
                    ...$entry,
                    'matched_message_ids' => [(int) $matchedMessage->id],
                    'matched_conversation_ids' => $conversationIds->all(),
                ];

                continue;
            }

            if ($conversationIds->count() === 1) {
                $resolved[] = [
                    ...$entry,
                    'matched_message_id' => (int) $matchedMessage->id,
                    'conversation_id' => (int) $conversationIds->first(),
                ];
            }
        }

        if ($ambiguous !== []) {
            return [
                'conversation' => null,
                'ambiguous' => true,
                'issue_type' => self::ISSUE_AMBIGUOUS_REFERENCE,
                'evidence' => [
                    'references' => $referenceEntries->all(),
                    'ambiguous_matches' => $ambiguous,
                    'resolved_matches' => $resolved,
                ],
            ];
        }

        $conversationIds = collect($resolved)
            ->pluck('conversation_id')
            ->unique()
            ->values();

        if ($conversationIds->count() > 1) {
            return [
                'conversation' => null,
                'ambiguous' => true,
                'issue_type' => self::ISSUE_CONFLICTING_REFERENCES,
                'evidence' => [
                    'references' => $referenceEntries->all(),
                    'resolved_matches' => $resolved,
                    'candidate_conversation_ids' => $conversationIds->all(),
                ],
            ];
        }

        $conversation = $conversationIds->count() === 1
            ? EmailConversation::query()
                ->whereKey($conversationIds->first())
                ->where('account_id', $accountId)
                ->first()
            : null;

        if ($conversationIds->count() === 1 && ! $conversation) {
            return [
                'conversation' => null,
                'ambiguous' => true,
                'issue_type' => self::ISSUE_ACCOUNT_MISMATCH,
                'evidence' => [
                    'references' => $referenceEntries->all(),
                    'resolved_matches' => $resolved,
                    'candidate_conversation_ids' => $conversationIds->all(),
                ],
            ];
        }

        return [
            'conversation' => $conversation,
            'ambiguous' => false,
            'issue_type' => '',
            'evidence' => [],
        ];
    }

    /**
     * @return array{conversation_key: string, evidence: ?array<string, mixed>}
     */
    private function rootCollisionDecision(EmailMessage $message, int $accountId, string $baseKey): array
    {
        $identifier = $this->normalizeMessageIdentifier($message->message_id);

        if ($identifier === '') {
            return ['conversation_key' => $baseKey, 'evidence' => null];
        }

        $matches = $this->messagesMatchingIdentifier($accountId, $identifier, (int) $message->id);

        if ($matches->isEmpty()) {
            return ['conversation_key' => $baseKey, 'evidence' => null];
        }

        $canonical = $matches
            ->push($message)
            ->sortBy(fn (EmailMessage $candidate): int => (int) $candidate->id)
            ->first();
        $conflicting = $matches
            ->filter(fn (EmailMessage $candidate): bool => ! $this->messagesAreCompatible($message, $candidate))
            ->values();

        if ($conflicting->isEmpty()) {
            return ['conversation_key' => $baseKey, 'evidence' => null];
        }

        $usesCanonicalIdentity = $canonical && $this->messagesAreCompatible($message, $canonical);
        $conversationKey = $usesCanonicalIdentity
            ? $baseKey
            : 'collision:'.hash('sha256', json_encode(
                $this->messageIdentityEvidence($message),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

        return [
            'conversation_key' => $conversationKey,
            'evidence' => [
                'normalized_message_id' => $identifier,
                'canonical_message_id' => $canonical ? (int) $canonical->id : null,
                'conflicting_message_ids' => $conflicting
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
                'current_evidence' => $this->messageIdentityEvidence($message),
                'conflicting_evidence' => $conflicting
                    ->map(fn (EmailMessage $candidate): array => [
                        'email_message_id' => (int) $candidate->id,
                        ...$this->messageIdentityEvidence($candidate),
                    ])
                    ->all(),
            ],
        ];
    }

    /**
     * @return EloquentCollection<int, EmailMessage>
     */
    private function messagesMatchingIdentifier(
        int $accountId,
        string $identifier,
        int $excludeMessageId,
    ): EloquentCollection {
        return EmailMessage::withTrashed()
            ->with(['placements' => fn ($query) => $query->where('account_id', $accountId)])
            ->where('account_id', $accountId)
            ->whereKeyNot($excludeMessageId)
            ->whereRaw('LOWER(TRIM(message_id)) IN (?, ?)', [$identifier, '<'.$identifier.'>'])
            ->get()
            ->filter(
                fn (EmailMessage $candidate): bool => $this->normalizeMessageIdentifier($candidate->message_id) === $identifier,
            )
            ->values();
    }

    private function conversationFor(
        EmailMailboxPlacement $placement,
        EmailMessage $message,
        string $conversationKey,
        array $metadata = [],
        bool $publishInitialContent = true,
    ): EmailConversation {
        return EmailConversation::query()->firstOrCreate(
            [
                'account_id' => $placement->account_id,
                'conversation_key' => $conversationKey,
            ],
            $publishInitialContent ? [
                'status' => EmailConversation::STATUS_ACTIVE,
                'subject' => $this->subject($message),
                'first_email_message_id' => $message->id,
                'latest_email_message_id' => $message->id,
                'latest_email_mailbox_placement_id' => $placement->id,
                'first_message_at' => $this->messageDate($message),
                'last_message_at' => $this->messageDate($message),
                'metadata' => [
                    'source' => 'mail_header_projection',
                    ...$metadata,
                ],
            ] : [
                'status' => EmailConversation::STATUS_ACTIVE,
                'subject' => null,
                'first_email_message_id' => null,
                'latest_email_message_id' => null,
                'latest_email_mailbox_placement_id' => null,
                'message_count' => 0,
                'active_placement_count' => 0,
                'provider_unread_count' => 0,
                'has_attachments' => false,
                'first_message_at' => null,
                'last_message_at' => null,
                'metadata' => [
                    'source' => 'mail_header_projection',
                ],
            ],
        );
    }

    /**
     * @return ?array{evidence: array<string, mixed>, ticket_link_id: ?int}
     */
    private function primaryTicketConflict(
        EmailMailboxPlacement $placement,
        EmailConversation $target,
    ): ?array {
        if (! Schema::hasTable('email_ticket_conversation_links')) {
            return null;
        }

        $movingPrimaryLinks = DB::table('email_ticket_conversation_links')
            ->where('email_mailbox_placement_id', $placement->id)
            ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
            ->where('relationship_role', EmailTicketConversationLink::ROLE_PRIMARY)
            ->lockForUpdate()
            ->get(['id', 'ticket_id']);

        if ($movingPrimaryLinks->isEmpty()) {
            return null;
        }

        $targetPrimaryQuery = DB::table('email_ticket_conversation_links')
            ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
            ->where('relationship_role', EmailTicketConversationLink::ROLE_PRIMARY)
            ->whereNotIn('id', $movingPrimaryLinks->pluck('id'));

        if (Schema::hasColumn('email_ticket_conversation_links', 'email_conversation_id')) {
            $targetPrimaryQuery->where('email_conversation_id', $target->id);
        } else {
            $targetPrimaryQuery
                ->where('account_id', $target->account_id)
                ->where('conversation_key', $target->conversation_key);
        }

        $movingTicketIds = $movingPrimaryLinks->pluck('ticket_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $targetPrimaryLinks = $targetPrimaryQuery->lockForUpdate()->get(['id', 'ticket_id']);
        $targetTicketIds = $targetPrimaryLinks->pluck('ticket_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $combinedTicketIds = $movingTicketIds->merge($targetTicketIds)->unique()->values();

        if ($combinedTicketIds->count() <= 1) {
            return null;
        }

        return [
            'ticket_link_id' => $movingPrimaryLinks->first()?->id
                ? (int) $movingPrimaryLinks->first()->id
                : null,
            'evidence' => [
                'moving_primary_link_ids' => $movingPrimaryLinks->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'moving_ticket_ids' => $movingTicketIds->all(),
                'target_primary_link_ids' => $targetPrimaryLinks->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'target_ticket_ids' => $targetTicketIds->all(),
            ],
        ];
    }

    private function movePlacementBoundTicketPointers(
        EmailMailboxPlacement $placement,
        EmailConversation $target,
    ): void {
        if (! Schema::hasTable('email_ticket_conversation_links')) {
            return;
        }

        $updates = [
            'conversation_key' => $target->conversation_key,
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('email_ticket_conversation_links', 'email_conversation_id')) {
            $updates['email_conversation_id'] = $target->id;
        }

        DB::table('email_ticket_conversation_links')
            ->where('email_mailbox_placement_id', $placement->id)
            ->update($updates);
    }

    private function isRootMessage(EmailMessage $message): bool
    {
        return $this->referencesIdentifiers($message) === []
            && $this->inReplyToIdentifiers($message) === [];
    }

    /** @return list<string> */
    private function referencesIdentifiers(EmailMessage $message): array
    {
        return $this->identifiersFromHeader($message->references);
    }

    /** @return list<string> */
    private function inReplyToIdentifiers(EmailMessage $message): array
    {
        return $this->identifiersFromHeader($message->in_reply_to);
    }

    /** @return list<string> */
    private function identifiersFromHeader(?string $header): array
    {
        if (trim((string) $header) === '') {
            return [];
        }

        preg_match_all('/<([^<>]+)>/u', (string) $header, $bracketedMatches);

        if (($bracketedMatches[1] ?? []) !== []) {
            $values = $bracketedMatches[1];
        } else {
            $values = preg_split('/\s+/', trim((string) $header)) ?: [];
        }

        return collect($values)
            ->map(fn (string $value): string => $this->normalizeMessageIdentifier($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function ambiguousConversationKey(
        EmailMailboxPlacement $placement,
        EmailMessage $message,
    ): string {
        return 'ambiguous:'.hash('sha256', json_encode([
            'account_id' => (int) $placement->account_id,
            'email_message_id' => (int) $message->id,
            'message_id' => $this->normalizeMessageIdentifier($message->message_id),
            'references' => $this->referencesIdentifiers($message),
            'in_reply_to' => $this->inReplyToIdentifiers($message),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function messagesAreCompatible(EmailMessage $left, EmailMessage $right): bool
    {
        foreach (['sender', 'subject', 'checksum_sha1'] as $field) {
            $leftValue = $this->messageIdentityEvidence($left)[$field];
            $rightValue = $this->messageIdentityEvidence($right)[$field];

            if ($leftValue !== '' && $rightValue !== '' && $leftValue !== $rightValue) {
                return false;
            }
        }

        return true;
    }

    /** @return array{sender: string, subject: string, checksum_sha1: string} */
    private function messageIdentityEvidence(EmailMessage $message): array
    {
        return [
            'sender' => Str::lower(trim((string) $message->from_email)),
            'subject' => Str::of((string) $message->subject)->lower()->squish()->toString(),
            'checksum_sha1' => Str::lower(trim((string) $message->checksum_sha1)),
        ];
    }

    private function subject(?EmailMessage $message): ?string
    {
        if (! $message) {
            return null;
        }

        return Str::limit((string) $message->subject, 512, '');
    }

    private function messageDate(?EmailMessage $message): mixed
    {
        return $message?->received_at ?? $message?->created_at;
    }

    /** @param array<string, mixed>|null $metadata */
    private function metadataWithoutLatestFolder(?array $metadata): array
    {
        $metadata ??= [];
        unset($metadata['latest_folder_path']);

        return $metadata;
    }

    private function normalizeMessageIdentifier(?string $value): string
    {
        $value = trim((string) $value, " \t\n\r\0\x0B<>,;");

        if ($value === '') {
            return '';
        }

        return Str::lower($value);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
