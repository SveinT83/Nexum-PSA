<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Actions\LinkEmailConversationToTicket;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BackfillEmailTicketConversationLinks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $limit = 500)
    {
    }

    public function handle(LinkEmailConversationToTicket $linkAction): void
    {
        $messages = EmailMessage::query()
            ->whereNotNull('ticket_id')
            ->whereDoesntHave('ticketLinks')
            ->orderBy('id', 'desc')
            ->limit($this->limit)
            ->get();

        if ($messages->isEmpty()) {
            return;
        }

        Log::info("Starting backfill for {$messages->count()} email-ticket links.");

        foreach ($messages as $message) {
            $ticket = Ticket::find($message->ticket_id);
            if (!$ticket) {
                continue;
            }

            // Find the best placement to represent this link
            $placement = EmailMailboxPlacement::query()
                ->where('email_message_id', $message->id)
                ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                ->orderBy('id')
                ->first();

            if (!$placement) {
                continue;
            }

            try {
                $linkAction->handle(
                    $placement,
                    $ticket,
                    User::first(), // System actor or first admin
                    EmailTicketConversationLink::ROLE_PRIMARY
                );
            } catch (\Exception $e) {
                Log::error("Failed to backfill link for message {$message->id}: {$e->getMessage()}");
            }
        }
    }
}
