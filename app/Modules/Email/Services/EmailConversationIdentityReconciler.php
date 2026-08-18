<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmailConversationIdentityReconciler
{
    public function __construct(
        private readonly EmailConversationProjector $conversations,
    ) {}

    /**
     * Re-evaluate existing placements oldest-first so later replies can use an
     * already-corrected ancestor. Only projection and compatible Ticket pointers change.
     *
     * @return array{scanned: int, moved: int, issues: int, removed_shells: int}
     */
    public function reconcileAll(): array
    {
        $totals = [
            'scanned' => 0,
            'moved' => 0,
            'issues' => 0,
            'removed_shells' => 0,
        ];

        if (! $this->conversations->available() || ! Schema::hasTable('email_messages')) {
            return $totals;
        }

        $placementIds = DB::table('email_mailbox_placements')
            ->join('email_messages', 'email_messages.id', '=', 'email_mailbox_placements.email_message_id')
            ->orderBy('email_messages.received_at')
            ->orderBy('email_messages.created_at')
            ->orderBy('email_mailbox_placements.id')
            ->pluck('email_mailbox_placements.id');

        foreach ($placementIds as $placementId) {
            $result = $this->reconcilePlacement((int) $placementId);

            foreach ($totals as $key => $value) {
                $totals[$key] += $result[$key];
            }
        }

        return $totals;
    }

    /**
     * @return array{scanned: int, moved: int, issues: int, removed_shells: int}
     */
    public function reconcilePlacement(int|EmailMailboxPlacement $placement): array
    {
        $result = [
            'scanned' => 0,
            'moved' => 0,
            'issues' => 0,
            'removed_shells' => 0,
        ];

        if (! $this->conversations->available()) {
            return $result;
        }

        $placement = EmailMailboxPlacement::query()
            ->with('message')
            ->find($placement instanceof EmailMailboxPlacement ? $placement->id : $placement);

        if (! $placement || ! $placement->message) {
            return $result;
        }

        $result['scanned'] = 1;
        $decision = $this->conversations->identityDecision($placement);
        $target = $decision['conversation'];
        $sourceConversationId = $placement->email_conversation_id
            ? (int) $placement->email_conversation_id
            : null;

        foreach ($decision['issues'] as $issue) {
            $this->conversations->recordCorrelationIssue(
                $issue['type'],
                $placement,
                $sourceConversationId,
                $target?->id,
                $issue['evidence'],
            );
            $result['issues']++;
        }

        if (! $target || ! $decision['may_move']) {
            if ($sourceConversationId) {
                $this->conversations->refreshConversation(
                    EmailConversation::query()->find($sourceConversationId),
                );
            }

            return $result;
        }

        if ($sourceConversationId === (int) $target->id) {
            $this->conversations->refreshConversation($target);

            return $result;
        }

        $move = $this->conversations->relocatePlacement($placement, $target);

        if ($move['issue']) {
            $this->conversations->recordCorrelationIssue(
                $move['issue']['type'],
                $placement,
                $move['old_conversation_id'],
                $target->id,
                $move['issue']['evidence'],
                $move['issue']['ticket_link_id'],
            );
            $result['issues']++;
        }

        if (! $move['moved']) {
            return $result;
        }

        $result['moved'] = 1;

        if ($move['old_conversation_id']
            && $this->removeEmptyUnreferencedShell((int) $move['old_conversation_id'])) {
            $result['removed_shells'] = 1;
        }

        return $result;
    }

    private function removeEmptyUnreferencedShell(int $conversationId): bool
    {
        return DB::transaction(function () use ($conversationId): bool {
            $conversation = EmailConversation::query()
                ->lockForUpdate()
                ->find($conversationId);

            if (! $conversation
                || EmailMailboxPlacement::query()->where('email_conversation_id', $conversationId)->exists()
                || $this->hasExternalReference($conversationId)) {
                return false;
            }

            return (bool) $conversation->delete();
        });
    }

    private function hasExternalReference(int $conversationId): bool
    {
        $references = [
            ['email_ticket_conversation_links', ['email_conversation_id']],
            ['email_conversation_classifications', ['email_conversation_id']],
            ['email_conversation_classification_events', ['email_conversation_id']],
            ['email_conversation_classification_migration_issues', ['email_conversation_id']],
            // Smart Inbox suggestions and their append-only events are durable
            // review/audit facts. The suggestion FK cascades, so an otherwise
            // empty identity shell must remain while any suggestion references it.
            ['email_smart_inbox_suggestions', ['email_conversation_id']],
            [
                'email_conversation_correlation_issues',
                ['source_email_conversation_id', 'target_email_conversation_id'],
            ],
        ];

        foreach ($references as [$table, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $availableColumns = collect($columns)
                ->filter(fn (string $column): bool => Schema::hasColumn($table, $column))
                ->values();

            if ($availableColumns->isEmpty()) {
                continue;
            }

            $referenced = DB::table($table)
                ->where(function ($query) use ($availableColumns, $conversationId): void {
                    foreach ($availableColumns as $column) {
                        $query->orWhere($column, $conversationId);
                    }
                })
                ->exists();

            if ($referenced) {
                return true;
            }
        }

        return false;
    }
}
