<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Email\Actions\SuppressEmailConversationTicketCorrelation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarkTicketAsNotTicket
{
    public function __construct(
        private readonly InboundEmailNotificationFanoutReadiness $fanoutReadiness,
        private readonly SuppressEmailConversationTicketCorrelation $suppressCorrelation,
    ) {}

    public function handle(Ticket $ticket, ?User $actor = null): int
    {
        if (! $actor) {
            throw new InvalidArgumentException('A current user is required to return Ticket mail to Inbox.');
        }

        if (! $this->fanoutReadiness->ready()
            || DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)
                ->where('id', 1)
                ->value('status') !== AdvanceInboundEmailTicketMessageRepair::STATUS_COMPLETED) {
            throw new InvalidArgumentException(
                'Inbound Ticket-message pointer repair must complete before returning mail to Inbox.',
            );
        }

        return DB::transaction(function () use ($ticket, $actor): int {
            $emails = $this->linkedEmails($ticket);

            if ($emails->isEmpty()) {
                throw new InvalidArgumentException('This ticket has no linked inbound email to return to Inbox.');
            }

            foreach ($emails as $email) {
                $placement = EmailMailboxPlacement::query()
                    ->where('email_message_id', $email->id)
                    ->where('account_id', $email->account_id)
                    ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                    ->whereNull('provider_missing_at')
                    ->latest('id')
                    ->first();

                if (! $placement) {
                    throw new InvalidArgumentException('Every linked email needs an active provider placement before its conversation can be suppressed.');
                }

                $this->suppressCorrelation->handle($placement, $actor, $ticket);
            }

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor->id,
                'type' => 'marked_not_ticket',
                'message' => 'Ticket returned to Inbox as not ticket.',
                'after' => [
                    'email_message_ids' => $emails->pluck('id')->values()->all(),
                    'tag' => 'not-ticket',
                    'scope' => 'mail_conversation',
                ],
            ]);

            $metadata = $ticket->metadata ?? [];
            $metadata['not_ticket'] = [
                'by_user_id' => $actor->id,
                'at' => now()->toIso8601String(),
                'email_message_ids' => $emails->pluck('id')->values()->all(),
                'scope' => 'mail_conversation',
            ];

            $ticket->forceFill([
                'metadata' => $metadata,
                'updated_by' => $actor->id,
            ])->save();

            $ticket->delete();

            return $emails->count();
        });
    }

    private function linkedEmails(Ticket $ticket): Collection
    {
        $messageEmailIds = $ticket->messages()
            ->pluck('source_inbound_email_message_id')
            ->map(fn ($emailMessageId): int => (int) $emailMessageId)
            ->filter()
            ->values();

        return EmailMessage::query()
            ->where('ticket_id', $ticket->id)
            ->whereIn('id', $messageEmailIds)
            ->get()
            ->unique('id')
            ->values();
    }
}
