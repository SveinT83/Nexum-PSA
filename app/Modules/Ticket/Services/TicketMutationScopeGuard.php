<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\WorkContext\Models\WorkContext;
use App\Modules\WorkContext\Support\WorkContextType;
use Illuminate\Validation\ValidationException;

/**
 * Fail closed when a Ticket points across its Client-owned work context.
 */
final class TicketMutationScopeGuard
{
    public function assert(Ticket $ticket): void
    {
        if (! $ticket->exists || $ticket->trashed()) {
            throw ValidationException::withMessages([
                'ticket' => 'A current Ticket is required for this mutation.',
            ]);
        }

        if ($ticket->work_context_id === null && $ticket->client_id === null) {
            // Legacy internal Tickets predate the work-context column.
            return;
        }

        $context = $ticket->work_context_id
            ? WorkContext::query()->find($ticket->work_context_id)
            : null;

        $matches = $ticket->client_id === null
            ? $context?->type === WorkContextType::INTERNAL && $context->client_id === null
            : $context?->type === WorkContextType::CLIENT
                && (int) $context->client_id === (int) $ticket->client_id;

        if (! $matches) {
            throw ValidationException::withMessages([
                'ticket' => 'The Ticket work context does not match its Client.',
            ]);
        }
    }
}
