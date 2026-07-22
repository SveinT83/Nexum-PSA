<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Services\TicketWorkflowDefinitionService;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\Schema;
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

        // Once a workflow has states it is administrator-owned. Defaults must never
        // rewrite custom steps, action policies, or stable keys during page loads.
        if (! $workflow->wasRecentlyCreated && $workflow->states()->exists()) {
            if (Schema::hasTable('ticket_workflow_versions') && ! $workflow->published_version_id) {
                app(TicketWorkflowDefinitionService::class)->publish($workflow);
            }

            return $workflow->fresh(['states.status', 'transitions.fromStatus', 'transitions.toStatus', 'publishedVersion']);
        }

        foreach ($statuses as $index => $status) {
            $workflow->states()->updateOrCreate(
                ['ticket_status_id' => $status->id],
                [
                    'state_key' => $status->slug,
                    'name' => $status->name,
                    'is_initial' => (bool) $status->is_default,
                    'is_terminal' => (bool) $status->is_closed,
                    'sort_order' => ($index + 1) * 10,
                    'requirements' => ['match' => 'all', 'groups' => []],
                    'action_policy' => [],
                    'assignment_policy' => [],
                    'commercial_policy' => [],
                ]
            );
        }

        $this->ensureTransition($workflow, 'new', 'in-progress', 'Start work', 10, triggerActions: [
            TicketAction::ADD_INTERNAL_NOTE,
            TicketAction::CUSTOMER_UPDATE,
            TicketAction::REQUEST_CUSTOMER_INPUT,
            TicketAction::SEND_SOLUTION,
        ]);
        $this->ensureTransition($workflow, 'in-progress', 'waiting-customer', 'Request customer input', 20, triggerActions: [
            TicketAction::REQUEST_CUSTOMER_INPUT,
        ]);
        $this->ensureTransition($workflow, 'waiting-customer', 'in-progress', 'Resume work', 30, triggerActions: [
            TicketAction::CUSTOMER_REPLY_RECEIVED,
        ]);
        $this->ensureTransition(
            $workflow, 'in-progress', 'resolved', 'Mark as solved', 40,
            requiresResolution: true, triggerActions: [TicketAction::SEND_SOLUTION],
        );
        $this->ensureTransition(
            $workflow, 'new', 'resolved', 'Mark as solved', 45,
            requiresResolution: true, triggerActions: [TicketAction::SEND_SOLUTION],
        );
        $this->ensureTransition(
            $workflow, 'waiting-customer', 'resolved', 'Mark as solved', 46,
            requiresResolution: true, triggerActions: [TicketAction::SEND_SOLUTION],
        );
        $this->ensureTransition($workflow, 'resolved', 'closed', 'Close', 50);
        $this->ensureTransition($workflow, 'closed', 'in-progress', 'Reopen', 60);

        // Closing must flow through the solved state so workflow conditions cannot be bypassed.
        $closed = $statuses->firstWhere('is_closed', true);
        if ($closed) {
            foreach ($statuses->where('is_closed', false) as $status) {
                if ($status->state !== 'resolved') {
                    $workflow->transitions()
                        ->where('from_status_id', $status->id)
                        ->where('to_status_id', $closed->id)
                        ->update(['is_active' => false]);
                }
            }
        }

        $workflow->refresh();

        if (Schema::hasTable('ticket_workflow_versions') && ! $workflow->published_version_id) {
            app(TicketWorkflowDefinitionService::class)->publish($workflow);
        }

        return $workflow->fresh(['states.status', 'transitions.fromStatus', 'transitions.toStatus', 'publishedVersion']);
    }

    private function ensureTransition(TicketWorkflow $workflow, string $fromSlug, string $toSlug, string $label, int $sortOrder, bool $requiresResponse = false, bool $requiresResolution = false, bool $manualEnabled = true, array $triggerActions = []): void
    {
        $from = TicketStatus::query()->where('slug', $fromSlug)->first();
        $to = TicketStatus::query()->where('slug', $toSlug)->first();

        if (! $from || ! $to) {
            return;
        }

        $this->ensureTransitionByStatus($workflow, $from, $to, $label, $sortOrder, $requiresResponse, $requiresResolution, $manualEnabled, $triggerActions);
    }

    private function ensureTransitionByStatus(TicketWorkflow $workflow, TicketStatus $from, TicketStatus $to, string $label, int $sortOrder, bool $requiresResponse = false, bool $requiresResolution = false, bool $manualEnabled = true, array $triggerActions = []): void
    {
        $transition = $workflow->transitions()->firstOrNew([
            'from_status_id' => $from->id,
            'to_status_id' => $to->id,
        ]);

        $fromState = $workflow->states()->where('ticket_status_id', $from->id)->first();
        $toState = $workflow->states()->where('ticket_status_id', $to->id)->first();

        $transition->fill([
            'label' => $transition->exists ? $transition->label : ($label ?: Str::headline($to->slug)),
            'is_active' => true,
            'sort_order' => $transition->exists ? $transition->sort_order : $sortOrder,
            'transition_key' => $transition->transition_key ?: $from->slug.'-to-'.$to->slug,
            'from_state_key' => $fromState?->state_key,
            'to_state_key' => $toState?->state_key,
        ]);

        if (! $transition->exists) {
            $transition->fill([
                'manual_enabled' => $manualEnabled,
                'trigger_actions' => $triggerActions,
                'requires_response' => $requiresResponse,
                'requires_resolution' => $requiresResolution,
                'requirements' => [
                    'match' => 'all',
                    'groups' => array_values(array_filter([[
                        'match' => 'all',
                        'conditions' => array_values(array_filter([
                            $requiresResponse ? ['fact' => 'ticket.technician_response', 'operator' => 'is_true', 'value' => null] : null,
                            $requiresResolution ? ['fact' => 'ticket.solution', 'operator' => 'is_true', 'value' => null] : null,
                        ])),
                    ]], fn (array $group) => $group['conditions'] !== [])),
                ],
            ]);
        }

        $transition->save();
    }
}
