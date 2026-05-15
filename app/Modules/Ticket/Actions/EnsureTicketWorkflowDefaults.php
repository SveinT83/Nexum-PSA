<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketWorkflow;
use Illuminate\Support\Str;

class EnsureTicketWorkflowDefaults
{
    public function handle(): ?TicketWorkflow
    {
        $statuses = TicketStatus::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($statuses->isEmpty()) {
            return null;
        }

        $workflow = TicketWorkflow::query()->firstOrCreate(
            ['slug' => 'default-ticket-workflow'],
            [
                'name' => 'Default Ticket Workflow',
                'description' => 'Default operational workflow generated from ticket statuses.',
                'is_active' => true,
                'is_default' => true,
                'sort_order' => 10,
            ]
        );

        TicketWorkflow::query()
            ->whereKeyNot($workflow->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        foreach ($statuses as $index => $status) {
            $workflow->states()->updateOrCreate(
                ['ticket_status_id' => $status->id],
                [
                    'name' => $status->name,
                    'is_initial' => (bool) $status->is_default,
                    'is_terminal' => (bool) $status->is_closed,
                    'sort_order' => ($index + 1) * 10,
                ]
            );
        }

        $this->ensureTransition($workflow, 'new', 'in-progress', 'Start work', 10);
        $this->ensureTransition($workflow, 'in-progress', 'waiting-customer', 'Wait for customer', 20);
        $this->ensureTransition($workflow, 'waiting-customer', 'in-progress', 'Resume work', 30);
        $this->ensureTransition($workflow, 'in-progress', 'resolved', 'Resolve', 40, requiresResolution: true);
        $this->ensureTransition($workflow, 'new', 'resolved', 'Resolve', 45, requiresResolution: true);
        $this->ensureTransition($workflow, 'waiting-customer', 'resolved', 'Resolve', 46, requiresResolution: true);
        $this->ensureTransition($workflow, 'resolved', 'closed', 'Close', 50);
        $this->ensureTransition($workflow, 'closed', 'in-progress', 'Reopen', 60);

        // Preserve the existing quick-close behavior while workflows are introduced.
        $closed = $statuses->firstWhere('is_closed', true);
        if ($closed) {
            foreach ($statuses->where('is_closed', false) as $status) {
                $this->ensureTransitionByStatus($workflow, $status, $closed, 'Close', 900 + (int) $status->sort_order);
            }
        }

        return $workflow->fresh(['states.status', 'transitions.fromStatus', 'transitions.toStatus']);
    }

    private function ensureTransition(TicketWorkflow $workflow, string $fromSlug, string $toSlug, string $label, int $sortOrder, bool $requiresResolution = false): void
    {
        $from = TicketStatus::query()->where('slug', $fromSlug)->first();
        $to = TicketStatus::query()->where('slug', $toSlug)->first();

        if (! $from || ! $to) {
            return;
        }

        $this->ensureTransitionByStatus($workflow, $from, $to, $label, $sortOrder, $requiresResolution);
    }

    private function ensureTransitionByStatus(TicketWorkflow $workflow, TicketStatus $from, TicketStatus $to, string $label, int $sortOrder, bool $requiresResolution = false): void
    {
        $workflow->transitions()->updateOrCreate(
            [
                'from_status_id' => $from->id,
                'to_status_id' => $to->id,
            ],
            [
                'label' => $label ?: Str::headline($to->slug),
                'is_active' => true,
                'requires_resolution' => $requiresResolution,
                'sort_order' => $sortOrder,
            ]
        );
    }
}
