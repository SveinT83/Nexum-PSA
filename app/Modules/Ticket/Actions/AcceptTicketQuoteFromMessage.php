<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Actions\AcceptSalesQuote;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Validation\ValidationException;

class AcceptTicketQuoteFromMessage
{
    public function __construct(
        private readonly TicketActionGuard $guard,
        private readonly AcceptSalesQuote $acceptQuote,
    ) {}

    public function handle(Ticket $ticket, TicketMessage $message, SalesQuoteVersion $version, array $data, User $actor): SalesQuoteVersion
    {
        if ($reason = $this->guard->reason($ticket, TicketAction::MARK_QUOTE_ACCEPTANCE, $actor)) {
            throw ValidationException::withMessages(['quote' => $reason]);
        }

        abort_unless((int) $message->ticket_id === (int) $ticket->id, 404);
        $ticket->loadMissing('salesContext.opportunity');
        abort_unless((int) $version->quote->opportunity_id === (int) $ticket->salesContext?->opportunity_id, 404);

        if ($message->author_type === 'user') {
            throw ValidationException::withMessages(['message' => 'A technician-authored message cannot be customer acceptance evidence.']);
        }

        $accepted = $this->acceptQuote->handle($version, [
            'name' => $data['name'],
            'method' => 'email_confirmed_by_staff',
            'ticket_message_id' => $message->id,
            'metadata' => ['confirmation_comment' => $data['comment'] ?? null],
        ], $actor);

        app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::MARK_QUOTE_ACCEPTANCE, $actor);

        return $accepted;
    }
}
