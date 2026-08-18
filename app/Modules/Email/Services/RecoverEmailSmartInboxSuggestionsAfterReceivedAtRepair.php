<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailSmartInboxSuggestionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RecoverEmailSmartInboxSuggestionsAfterReceivedAtRepair
{
    public const REASON_TIMESTAMP_REPAIRED = 'received_at_timestamp_repaired';

    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly EmailConversationFingerprint $conversationFingerprint,
        private readonly EmailSmartInboxSuggestionEventRecorder $eventRecorder,
    ) {}

    /**
     * Recover only false-stale suggestions whose complete, schema-specific
     * source evidence matches after the timestamp repair. No provider or
     * proposal action is executed here.
     *
     * @param  array<int, array{observed_at: \DateTimeInterface, repaired_at: \DateTimeInterface}>  $repairWindows
     * @return array{recovered_suggestion_ids: array<int, int>, attributed_counts: array<int, int>}
     */
    public function handle(array $repairWindows): array
    {
        $repairScope = collect($repairWindows)
            ->filter(fn (mixed $window, mixed $id): bool => (int) $id > 0
                && is_array($window)
                && ($window['observed_at'] ?? null) instanceof \DateTimeInterface
                && ($window['repaired_at'] ?? null) instanceof \DateTimeInterface)
            ->mapWithKeys(fn (array $window, mixed $id): array => [(int) $id => $window])
            ->sortKeys();

        if ($repairScope->isEmpty()) {
            return ['recovered_suggestion_ids' => [], 'attributed_counts' => []];
        }

        $candidateIds = EmailSmartInboxSuggestion::query()
            ->where('status', EmailSmartInboxSuggestion::STATUS_STALE)
            ->orderBy('id')
            ->get(['id', 'source_message_ids_json'])
            ->filter(function (EmailSmartInboxSuggestion $suggestion) use ($repairScope): bool {
                $sourceIds = $this->normalizeIds($suggestion->source_message_ids_json);

                return $repairScope->keys()->intersect($sourceIds)->isNotEmpty();
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        $recovered = [];
        $attributedCounts = [];

        foreach ($candidateIds as $candidateId) {
            $attributedMessageId = DB::transaction(function () use ($candidateId, $repairScope): ?int {
                $suggestion = EmailSmartInboxSuggestion::query()
                    ->lockForUpdate()
                    ->find($candidateId);

                if (! $suggestion || $suggestion->status !== EmailSmartInboxSuggestion::STATUS_STALE) {
                    return null;
                }

                $latestEvent = EmailSmartInboxSuggestionEvent::query()
                    ->where('email_smart_inbox_suggestion_id', $suggestion->id)
                    ->latest('id')
                    ->first();

                if (! $latestEvent
                    || $latestEvent->event_type !== EmailSmartInboxSuggestionEvent::TYPE_STALE
                    || $latestEvent->reason_code !== 'conversation_fingerprint_changed') {
                    return null;
                }

                $suggestion->loadMissing([
                    'user:id,status',
                    'account:id,account_kind,owner_id,is_active',
                    'conversation',
                ]);
                $user = $suggestion->user;
                $account = $suggestion->account;
                $conversation = $suggestion->conversation;

                if (! $user instanceof User
                    || $user->status !== User::STATUS_ACTIVE
                    || ! $account
                    || ! $account->is_active
                    || ! $conversation instanceof EmailConversation
                    || $conversation->status !== EmailConversation::STATUS_ACTIVE
                    || (int) $conversation->account_id !== (int) $suggestion->account_id
                    || ! $this->mailboxAccess->canAccessAccount($user, $account, MailboxAccess::VIEW)) {
                    return null;
                }

                $schemaVersion = $suggestion->source_fingerprint_schema
                    ?: EmailConversationFingerprint::LEGACY_SCHEMA_VERSION;

                try {
                    $current = $this->conversationFingerprint->forConversation(
                        $conversation,
                        $schemaVersion,
                    );
                } catch (\InvalidArgumentException) {
                    return null;
                }

                $storedSourceIds = $this->normalizeIds($suggestion->source_message_ids_json);
                $currentSourceIds = $this->normalizeIds($current['source_message_ids']);
                $intersectedRepairIds = $repairScope->keys()->intersect($storedSourceIds)->sort()->values();
                $eligibleRepairIds = $intersectedRepairIds
                    ->filter(fn (int $messageId): bool => $this->eventFallsInsideRepairWindow(
                        $latestEvent,
                        $suggestion,
                        $repairScope->get($messageId),
                    ))
                    ->values();

                if ($storedSourceIds->isEmpty()
                    || $eligibleRepairIds->isEmpty()
                    || $storedSourceIds->all() !== $currentSourceIds->all()
                    || ! is_string($suggestion->source_fingerprint)
                    || ! hash_equals($suggestion->source_fingerprint, $current['fingerprint'])) {
                    return null;
                }

                $before = $this->eventRecorder->snapshot($suggestion);
                $suggestion->forceFill([
                    'status' => EmailSmartInboxSuggestion::STATUS_PENDING,
                    'stale_at' => null,
                ])->save();
                $this->eventRecorder->record(
                    $suggestion,
                    EmailSmartInboxSuggestionEvent::TYPE_RECOVERED,
                    null,
                    $before,
                    self::REASON_TIMESTAMP_REPAIRED,
                );

                return (int) $eligibleRepairIds->first();
            });

            if ($attributedMessageId !== null) {
                $recovered[] = (int) $candidateId;
                $attributedCounts[$attributedMessageId] = ($attributedCounts[$attributedMessageId] ?? 0) + 1;
            }
        }

        return [
            'recovered_suggestion_ids' => $recovered,
            'attributed_counts' => $attributedCounts,
        ];
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    private function normalizeIds(mixed $ids): \Illuminate\Support\Collection
    {
        return collect(is_array($ids) ? $ids : [])
            ->filter(fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();
    }

    /** @param array{observed_at: \DateTimeInterface, repaired_at: \DateTimeInterface}|null $window */
    private function eventFallsInsideRepairWindow(
        EmailSmartInboxSuggestionEvent $event,
        EmailSmartInboxSuggestion $suggestion,
        ?array $window,
    ): bool {
        if (! $window || ! $event->occurred_at || ! $suggestion->stale_at) {
            return false;
        }

        $observedAt = CarbonImmutable::instance($window['observed_at'])->utc();
        $repairedAt = CarbonImmutable::instance($window['repaired_at'])->utc();
        $eventAt = CarbonImmutable::instance($event->occurred_at)->utc();
        $staleAt = CarbonImmutable::instance($suggestion->stale_at)->utc();

        return $eventAt->betweenIncluded($observedAt, $repairedAt)
            && $staleAt->betweenIncluded($observedAt, $repairedAt);
    }
}
