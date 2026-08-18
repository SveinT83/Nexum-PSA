<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailSmartInboxSuggestionEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class EmailSmartInboxSuggestionStateService
{
    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly EmailConversationFingerprint $conversationFingerprint,
        private readonly EmailSmartInboxSuggestionEventRecorder $eventRecorder,
    ) {}

    /**
     * Re-evaluate a suggestion against current mailbox authority and source
     * state. Revoked and stale are terminal for this suggestion instance.
     *
     * @throws AuthorizationException
     */
    public function refresh(EmailSmartInboxSuggestion $suggestion, User $actor): EmailSmartInboxSuggestion
    {
        return DB::transaction(function () use ($suggestion, $actor): EmailSmartInboxSuggestion {
            $locked = EmailSmartInboxSuggestion::query()
                ->lockForUpdate()
                ->findOrFail($suggestion->id);

            return $this->evaluateLocked($locked, $actor);
        });
    }

    /**
     * The caller must hold a row lock when this is used inside another action.
     *
     * @throws AuthorizationException
     */
    public function evaluateLocked(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
    ): EmailSmartInboxSuggestion {
        if (! $suggestion->user_id || (int) $suggestion->user_id !== (int) $actor->id) {
            throw new AuthorizationException('Smart Inbox suggestion not found.');
        }

        $suggestion->loadMissing(['account:id,account_kind,owner_id,is_active', 'conversation']);
        $account = $suggestion->account;
        $conversation = $suggestion->conversation;

        if ($suggestion->status === EmailSmartInboxSuggestion::STATUS_REVOKED) {
            return $suggestion;
        }

        if (! $account
            || ! $conversation
            || ! $account->is_active
            || (int) $conversation->account_id !== (int) $suggestion->account_id
            || ! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::VIEW)) {
            $before = $this->eventRecorder->snapshot($suggestion);
            $suggestion->forceFill([
                'status' => EmailSmartInboxSuggestion::STATUS_REVOKED,
                'revoked_at' => now(),
            ])->save();
            $this->eventRecorder->record(
                $suggestion,
                EmailSmartInboxSuggestionEvent::TYPE_REVOKED,
                $actor,
                $before,
                'mailbox_view_revoked',
            );

            return $suggestion->refresh();
        }

        if ($suggestion->status !== EmailSmartInboxSuggestion::STATUS_PENDING) {
            return $suggestion;
        }

        try {
            $current = $this->conversationFingerprint->forConversation(
                $conversation,
                $suggestion->source_fingerprint_schema
                    ?: EmailConversationFingerprint::LEGACY_SCHEMA_VERSION,
            );
        } catch (\InvalidArgumentException) {
            return $this->markStale(
                $suggestion,
                $actor,
                'conversation_fingerprint_schema_unsupported',
            );
        }

        if ($current['source_message_ids'] === []
            || ! hash_equals((string) $suggestion->source_fingerprint, $current['fingerprint'])) {
            return $this->markStale(
                $suggestion,
                $actor,
                'conversation_fingerprint_changed',
            );
        }

        return $suggestion->refresh();
    }

    private function markStale(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        string $reasonCode,
    ): EmailSmartInboxSuggestion {
        $before = $this->eventRecorder->snapshot($suggestion);
        $suggestion->forceFill([
            'status' => EmailSmartInboxSuggestion::STATUS_STALE,
            'stale_at' => now(),
        ])->save();
        $this->eventRecorder->record(
            $suggestion,
            EmailSmartInboxSuggestionEvent::TYPE_STALE,
            $actor,
            $before,
            $reasonCode,
        );

        return $suggestion->refresh();
    }
}
