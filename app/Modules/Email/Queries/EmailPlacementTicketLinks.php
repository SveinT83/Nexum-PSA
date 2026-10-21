<?php

namespace App\Modules\Email\Queries;

use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\EmailConversationProjector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** One source-scoped query retains both durable and legacy Ticket provenance. */
final class EmailPlacementTicketLinks
{
    public function forPlacement(EmailMailboxPlacement $placement): Collection
    {
        $conversations = app(EmailConversationProjector::class);
        $conversationKey = $conversations->conversationKey($placement->message);
        $conversationId = $conversations->available()
            ? ((int) $placement->email_conversation_id ?: null)
            : null;

        return EmailTicketConversationLink::query()
            ->with('ticket')
            ->where(function (Builder $links) use ($conversationId, $conversationKey, $placement): void {
                if ($conversationId) {
                    $links->where('email_conversation_id', $conversationId);
                }

                $legacyScope = function (Builder $legacy) use ($conversationKey, $placement): void {
                    $legacy
                        ->where('conversation_key', $conversationKey)
                        ->where(function (Builder $accountScope) use ($placement): void {
                            $accountScope
                                ->where('account_id', $placement->account_id)
                                ->orWhereNull('account_id');
                        });
                };

                $conversationId
                    ? $links->orWhere($legacyScope)
                    : $links->where($legacyScope);
            })
            ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
            ->latest('linked_at')
            ->latest('id')
            ->limit(6)
            ->get();
    }
}
