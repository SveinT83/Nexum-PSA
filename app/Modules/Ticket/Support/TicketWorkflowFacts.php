<?php

namespace App\Modules\Ticket\Support;

use App\Modules\Ticket\Contracts\WorkflowFactProvider;
use App\Modules\Ticket\Models\Ticket;

class TicketWorkflowFacts implements WorkflowFactProvider
{
    public function supports(string $fact): bool
    {
        return str_starts_with($fact, 'ticket.')
            || str_starts_with($fact, 'customer.')
            || str_starts_with($fact, 'review.');
    }

    public function catalog(): array
    {
        return [
            'ticket.internal_note' => $this->boolean('Internal note exists'),
            'ticket.technician_response' => $this->boolean('Technician reply exists'),
            'ticket.solution' => $this->boolean('Solution is marked'),
            'ticket.knowledge_follow_up' => $this->boolean('Knowledge follow-up exists'),
            'ticket.time_registered' => $this->boolean('Time is registered'),
            'ticket.cost_registered' => $this->boolean('Actual cost is registered'),
            'ticket.tasks_complete' => $this->boolean('Required tasks are complete'),
            'ticket.client_linked' => $this->boolean('Client is linked'),
            'ticket.contact_linked' => $this->boolean('Customer contact is linked'),
            'ticket.field_present' => ['label' => 'Required Ticket field is filled', 'operators' => ['is_true', 'is_false'], 'value_type' => 'field_name'],
            'customer.response' => $this->boolean('Current customer response evidence exists'),
            'customer.signature' => $this->boolean('Current classified signature exists'),
            'review.approved' => [
                'label' => 'Senior review is approved',
                'operators' => ['is_true', 'is_false'],
                'value_type' => 'gate_key',
            ],
        ];
    }

    public function resolve(Ticket $ticket, string $fact, array $condition = []): array
    {
        $value = match ($fact) {
            'ticket.internal_note' => $this->hasInternalNote($ticket),
            'ticket.technician_response' => $this->hasTechnicianResponse($ticket),
            'ticket.solution' => $this->hasSolution($ticket),
            'ticket.knowledge_follow_up' => $ticket->events()->where('type', 'documentation_requested')->exists(),
            'ticket.time_registered' => $ticket->timeEntries()->exists(),
            'ticket.cost_registered' => $ticket->costEntries()->exists(),
            'ticket.tasks_complete' => ! $ticket->tasks()->whereNull('completed_at')->exists(),
            'ticket.client_linked' => filled($ticket->client_id),
            'ticket.contact_linked' => filled($ticket->contact_id),
            'ticket.field_present' => $this->fieldPresent($ticket, (string) ($condition['value'] ?? '')),
            'customer.response' => $this->activeEvidence($ticket, 'customer_response', $condition),
            'customer.signature' => $this->activeEvidence($ticket, 'signature', $condition),
            'review.approved' => $this->approvedReview($ticket, $condition),
            default => false,
        };

        return [
            'value' => $value,
            'evidence' => ['ticket_id' => $ticket->id, 'fact' => $fact],
        ];
    }

    private function activeEvidence(Ticket $ticket, string $type, array $condition): bool
    {
        return $ticket->workflowEvidence()
            ->where('evidence_type', $type)
            ->whereNull('invalidated_at')
            ->when(filled($condition['scope_key'] ?? null), fn ($query) => $query->where('scope_key', $condition['scope_key']))
            ->exists();
    }

    private function approvedReview(Ticket $ticket, array $condition): bool
    {
        $gateKey = (string) ($condition['gate_key'] ?? $condition['value'] ?? 'senior-review');
        $fingerprint = app(TicketWorkflowEvidenceFingerprint::class)->forTicket($ticket);

        return $ticket->workflowReviews()
            ->where('gate_key', $gateKey)
            ->where('status', 'approved')
            ->where('evidence_fingerprint', $fingerprint)
            ->whereNull('invalidated_at')
            ->exists();
    }

    private function boolean(string $label): array
    {
        return ['label' => $label, 'operators' => ['is_true', 'is_false'], 'value_type' => 'none'];
    }

    private function hasInternalNote(Ticket $ticket): bool
    {
        return $ticket->messages()
            ->where('type', 'internal_note')
            ->get(['metadata'])
            ->contains(fn ($message) => data_get($message->metadata, 'is_default_initial_note') !== true);
    }

    private function hasTechnicianResponse(Ticket $ticket): bool
    {
        return $ticket->messages()
            ->where('author_type', 'user')
            ->where('type', 'customer_reply')
            ->where('visibility', 'public')
            ->exists();
    }

    private function hasSolution(Ticket $ticket): bool
    {
        return $ticket->messages()
            ->where('metadata->is_solution', true)
            ->where(function ($query): void {
                $query->where('type', 'customer_reply');

                if (app(TicketSolutionPolicy::class)->allowsInternalSolutionNotes()) {
                    $query->orWhere('type', 'internal_note');
                }
            })
            ->exists();
    }

    private function fieldPresent(Ticket $ticket, string $field): bool
    {
        $allowed = [
            'subject', 'description', 'client_id', 'site_id', 'contact_id', 'asset_id',
            'category_id', 'ticket_type_id', 'queue_id', 'priority_id', 'owner_id',
            'impact', 'urgency',
        ];

        return in_array($field, $allowed, true) && filled($ticket->getAttribute($field));
    }
}
