<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;

/** Shared browser/API action preserves the existing audit and workflow behavior. */
class AcknowledgeTicketMessage
{
    public function handle(Ticket $ticket, TicketMessage $message, ?User $actor = null): Ticket
    {
        $messageWasUnread = blank($message->read_at);
        abort_unless((int) $message->ticket_id === (int) $ticket->id, 404);

        DB::transaction(function () use ($ticket, $message, $actor) {
            $wasUnread = blank($message->read_at);

            if ($wasUnread) {
                $message->forceFill(['read_at' => now()])->save();
            }

            // Keep the ticket unread until every customer-authored reply has been handled.
            $hasUnreadCustomerReplies = $ticket->messages()
                ->whereIn('author_type', ['contact', 'portal_user'])
                ->whereNull('read_at')
                ->exists();

            $ticket->forceFill([
                'is_unread' => $hasUnreadCustomerReplies,
                'updated_by' => $actor?->id,
            ])->save();

            if ($wasUnread) {
                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'actor_id' => $actor?->id,
                    'type' => 'message_marked_read',
                    'message' => 'Ticket reply marked as read.',
                    'after' => [
                        'message_id' => $message->id,
                        'is_unread' => $hasUnreadCustomerReplies,
                    ],
                ]);
            }
        });

        if ($messageWasUnread) {
            app(\App\Modules\Ticket\Actions\ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::MARK_READ, $actor);
        }

        return $ticket->refresh();
    }
}
