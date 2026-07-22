<?php

namespace App\Modules\Ticket\Support;

use App\Modules\Ticket\Models\Ticket;

class TicketWorkflowEvidenceFingerprint
{
    public function forTicket(Ticket $ticket): string
    {
        $ticket->loadMissing(['plannedLines', 'workflowEvidence', 'salesContext.opportunity.currentQuoteVersion']);

        $material = [
            'workflow_version_id' => $ticket->workflow_version_id,
            'workflow_state_key' => $ticket->workflow_state_key,
            'client_id' => $ticket->client_id,
            'site_id' => $ticket->site_id,
            'contact_id' => $ticket->contact_id,
            'asset_id' => $ticket->asset_id,
            'category_id' => $ticket->category_id,
            'ticket_type_id' => $ticket->ticket_type_id,
            'queue_id' => $ticket->queue_id,
            'planned_lines' => $ticket->plannedLines
                ->sortBy('id')
                ->map(fn ($line) => $line->only([
                    'id', 'source_type', 'source_id', 'quantity', 'unit_price_ex_vat',
                    'downstream_type', 'status', 'approved_quote_version_id',
                ]))->values()->all(),
            'quote_version_id' => $ticket->salesContext?->opportunity?->current_quote_version_id,
            'evidence' => $ticket->workflowEvidence
                ->whereNull('invalidated_at')
                ->sortBy('id')
                ->map(fn ($evidence) => [$evidence->id, $evidence->fingerprint, $evidence->scope_key])
                ->values()->all(),
        ];

        return hash('sha256', json_encode($material, JSON_THROW_ON_ERROR));
    }
}
