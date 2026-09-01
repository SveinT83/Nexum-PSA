<?php

namespace App\Modules\Ticket\Support;

use App\Modules\Ticket\Models\Ticket;

/**
 * The final Ticket plus the immutable event for its originating mutation.
 */
final readonly class TicketMutationResult
{
    public function __construct(
        public Ticket $ticket,
        public ?TicketRuleMutationEvent $event,
    ) {}

    public static function noChange(Ticket $ticket): self
    {
        return new self($ticket->refresh(), null);
    }

    public function changed(): bool
    {
        return $this->event !== null;
    }
}
