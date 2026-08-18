<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailLiveInvalidator;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Ticket\Actions\LinkInboundEmailToTicket;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkEmailConversationToTicket
{
    public function __construct(
        private readonly LinkInboundEmailToTicket $linkInboundEmailToTicket,
        private readonly EmailConversationProjector $conversations,
        private readonly EmailLiveInvalidator $invalidator,
    ) {}

    public function handle(
        EmailMailboxPlacement $placement,
        Ticket $ticket,
        User $actor,
        string $relationshipRole = EmailTicketConversationLink::ROLE_SECONDARY,
        string $audience = EmailTicketConversationLink::AUDIENCE_CUSTOMER,
    ): EmailTicketConversationLink {
        $placement->loadMissing(['message', 'account', 'folder']);

        if (! $placement->message) {
            throw ValidationException::withMessages([
                'ticketLinkTarget' => 'Select a stored mail placement before linking a Ticket.',
            ]);
        }

        $role = $relationshipRole === EmailTicketConversationLink::ROLE_PRIMARY
            ? EmailTicketConversationLink::ROLE_PRIMARY
            : EmailTicketConversationLink::ROLE_SECONDARY;
        $audience = $audience === EmailTicketConversationLink::AUDIENCE_INTERNAL
            ? EmailTicketConversationLink::AUDIENCE_INTERNAL
            : EmailTicketConversationLink::AUDIENCE_CUSTOMER;

        return DB::transaction(function () use ($placement, $ticket, $actor, $role, $audience): EmailTicketConversationLink {
            $message = $placement->message;
            $conversation = $this->conversations->assignPlacement($placement);
            $conversationKey = $conversation?->conversation_key ?: $this->conversationKey($message);

            if ($role === EmailTicketConversationLink::ROLE_PRIMARY) {
                $primaryLinks = EmailTicketConversationLink::query()
                    ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
                    ->where('relationship_role', EmailTicketConversationLink::ROLE_PRIMARY)
                    ->where('ticket_id', '<>', $ticket->id);

                if ($conversation) {
                    $primaryLinks->where('email_conversation_id', $conversation->id);
                } else {
                    $primaryLinks
                        ->where('conversation_key', $conversationKey)
                        ->where('account_id', $placement->account_id);
                }

                $primaryLinks->update([
                    'relationship_role' => EmailTicketConversationLink::ROLE_SECONDARY,
                    'updated_at' => now(),
                ]);
            }

            EmailTicketConversationLink::query()
                ->where('email_message_id', $message->id)
                ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
                ->where('ticket_id', '<>', $ticket->id)
                ->update([
                    'status' => EmailTicketConversationLink::STATUS_UNLINKED,
                    'unlinked_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->linkInboundEmailToTicket->handle($message->fresh(), $ticket);

            $link = EmailTicketConversationLink::query()->updateOrCreate(
                [
                    'ticket_id' => $ticket->id,
                    'email_message_id' => $message->id,
                    'status' => EmailTicketConversationLink::STATUS_ACTIVE,
                ],
                [
                    'email_mailbox_placement_id' => $placement->id,
                    'account_id' => $placement->account_id,
                    'email_conversation_id' => $conversation?->id,
                    'linked_by' => $actor->id,
                    'conversation_key' => $conversationKey,
                    'relationship_role' => $role,
                    'audience' => $audience,
                    'metadata' => [
                        'message_id' => $message->message_id,
                        'in_reply_to' => $message->in_reply_to,
                        'references' => $message->references,
                        'subject' => $message->subject,
                        'folder_path' => $placement->folder?->path ?: $placement->folder_path,
                    ],
                    'linked_at' => now(),
                    'unlinked_at' => null,
                ],
            );

            $this->invalidator->record([
                'account' => [
                    $placement->account_id => [EmailLiveProjectionChange::TYPE_TICKET_LINK],
                ],
                'conversations' => $conversation ? [$conversation->id] : [],
            ]);

            return $link;
        });
    }

    public function conversationKey(EmailMessage $message): string
    {
        return $this->conversations->conversationKey($message);
    }
}
