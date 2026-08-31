<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailConversationTicketSuppression;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use Illuminate\Support\Facades\Schema;

class EmailTicketCorrelationSuppressionService
{
    public function __construct(
        private readonly EmailConversationProjector $conversations,
    ) {}

    public function isSuppressed(EmailMessage $message): bool
    {
        if (! Schema::hasTable('email_conversation_ticket_suppressions')) {
            return false;
        }

        $placement = EmailMailboxPlacement::query()
            ->where('email_message_id', $message->id)
            ->where('account_id', $message->account_id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('provider_missing_at')
            ->latest('id')
            ->first();

        $conversation = $placement
            ? $this->conversations->assignPlacement($placement)
            : null;
        $conversationKey = $conversation?->conversation_key
            ?: $this->conversations->conversationKey($message);

        return EmailConversationTicketSuppression::query()
            ->where('account_id', $message->account_id)
            ->where('conversation_key', $conversationKey)
            ->where('status', EmailConversationTicketSuppression::STATUS_ACTIVE)
            ->exists();
    }
}
