<?php

namespace App\Modules\Economy\Support;

use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Ticket\Contracts\WorkflowFactProvider;
use App\Modules\Ticket\Models\Ticket;

class EconomyWorkflowFacts implements WorkflowFactProvider
{
    public function supports(string $fact): bool
    {
        return str_starts_with($fact, 'economy.');
    }

    public function catalog(): array
    {
        return [
            'economy.order_generated' => ['label' => 'Economy order was generated', 'operators' => ['is_true', 'is_false'], 'value_type' => 'none'],
            'economy.actuals_ready' => ['label' => 'Actual time and costs are ready', 'operators' => ['is_true', 'is_false'], 'value_type' => 'none'],
            'economy.no_billing_outcome' => ['label' => 'Ticket has a no-billing close outcome', 'operators' => ['is_true', 'is_false'], 'value_type' => 'none'],
        ];
    }

    public function resolve(Ticket $ticket, string $fact, array $condition = []): array
    {
        $value = match ($fact) {
            'economy.order_generated' => EconomyOrderLine::query()->where('ticket_id', $ticket->id)->exists(),
            'economy.actuals_ready' => ! $ticket->costEntries()->whereNotIn('status', ['manual', 'picked'])->exists()
                && ! $ticket->timeEntries()->where('billing_status', 'draft')->exists(),
            'economy.no_billing_outcome' => in_array($ticket->close_outcome, ['customer_declined', 'cancelled', 'no_sale'], true),
            default => false,
        };

        return ['value' => $value, 'evidence' => ['ticket_id' => $ticket->id]];
    }
}
