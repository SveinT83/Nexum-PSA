<?php

namespace App\Modules\Sales\Support;

use App\Modules\Ticket\Contracts\WorkflowFactProvider;
use App\Modules\Ticket\Models\Ticket;

class SalesWorkflowFacts implements WorkflowFactProvider
{
    public function supports(string $fact): bool
    {
        return str_starts_with($fact, 'sales.');
    }

    public function catalog(): array
    {
        return [
            'sales.planned_lines' => $this->boolean('Planned cost lines exist'),
            'sales.quote_created' => $this->boolean('Ticket quote exists'),
            'sales.quote_sent' => $this->boolean('Current quote was sent'),
            'sales.quote_accepted' => $this->boolean('Current quote is accepted'),
            'sales.quote_accepted_amount' => ['label' => 'Accepted quote amount ex VAT', 'operators' => ['equals', 'greater_or_equal', 'less_or_equal'], 'value_type' => 'number'],
            'sales.quote_declined' => $this->boolean('Current quote is declined or lost'),
            'sales.implementation_lines' => $this->boolean('Accepted quote includes implementation'),
        ];
    }

    public function resolve(Ticket $ticket, string $fact, array $condition = []): array
    {
        $ticket->loadMissing('salesContext.opportunity.currentQuoteVersion.lines');
        $context = $ticket->salesContext;
        $opportunity = $context?->opportunity;
        $version = $opportunity?->currentQuoteVersion;

        $value = match ($fact) {
            'sales.planned_lines' => $ticket->plannedLines()->whereIn('status', ['planned', 'quoted', 'approved'])->exists(),
            'sales.quote_created' => $version !== null,
            'sales.quote_sent' => in_array($version?->status, ['sent', 'accepted'], true),
            'sales.quote_accepted' => $version?->status === 'accepted',
            'sales.quote_accepted_amount' => $version?->status === 'accepted' ? (float) $version->total_ex_vat : null,
            'sales.quote_declined' => in_array($version?->status, ['rejected', 'declined', 'expired'], true) || $opportunity?->status === 'lost',
            'sales.implementation_lines' => $version?->status === 'accepted'
                && $version->lines->contains(fn ($line) => $line->downstream_type === 'implementation'),
            default => false,
        };

        return [
            'value' => $value,
            'evidence' => $version ? [
                'opportunity_id' => $opportunity?->id,
                'quote_version_id' => $version->id,
                'status' => $version->status,
            ] : [],
        ];
    }

    private function boolean(string $label): array
    {
        return ['label' => $label, 'operators' => ['is_true', 'is_false'], 'value_type' => 'none'];
    }
}
