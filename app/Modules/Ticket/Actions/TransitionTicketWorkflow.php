<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Jobs\SendTicketWorkflowCustomerUpdate;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketWorkflowHistory;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketApprovedScopeGuard;
use App\Modules\Ticket\Services\TicketAssignmentEngine;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketWorkflowCustomerNotificationPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionTicketWorkflow
{
    public function __construct(
        private readonly TicketWorkflowRuntime $runtime,
        private readonly ChangeTicketStatus $changeStatus,
        private readonly TicketActionGuard $guard,
        private readonly TicketAssignmentEngine $assignments,
        private readonly TicketApprovedScopeGuard $approvedScope,
    ) {}

    public function handle(
        Ticket $ticket,
        string $transitionKey,
        ?User $actor = null,
        ?string $idempotencyKey = null,
        bool $enforceActionGuard = true,
        bool $allowTerminal = true,
        bool $syncRelationship = true,
    ): Ticket {
        if ($idempotencyKey && $existing = TicketWorkflowHistory::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing->ticket()->firstOrFail();
        }

        if ($enforceActionGuard && ($reason = $this->guard->reason($ticket, TicketAction::CHANGE_STATUS, $actor))) {
            throw ValidationException::withMessages(['transition' => $reason]);
        }

        return DB::transaction(function () use ($ticket, $transitionKey, $actor, $idempotencyKey, $enforceActionGuard, $allowTerminal, $syncRelationship): Ticket {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $transition = $this->runtime->transitionDefinition($locked, $transitionKey);

            if (! $transition) {
                throw ValidationException::withMessages(['transition' => 'The workflow transition does not exist in this Ticket version.']);
            }

            $decision = $this->runtime->transitionDecision($locked, $transition);
            if (! $decision['allowed']) {
                throw ValidationException::withMessages(['transition' => $decision['disabled_reason']]);
            }

            $target = $decision['target_state'];

            if ((bool) ($target['is_terminal'] ?? false)) {
                if (! $allowTerminal) {
                    throw ValidationException::withMessages(['transition' => 'Automatic workflow actions cannot close a Ticket.']);
                }

                if ($enforceActionGuard && ($reason = $this->guard->reason($locked, TicketAction::CLOSE, $actor))) {
                    throw ValidationException::withMessages(['transition' => $reason]);
                }
                $this->approvedScope->assertWithinApprovedScope($locked);
                $locked->forceFill(['close_outcome' => $locked->close_outcome ?: 'completed'])->save();
            }
            $before = [
                'workflow_id' => $locked->workflow_id,
                'workflow_version_id' => $locked->workflow_version_id,
                'workflow_state_key' => $locked->workflow_state_key,
                'status_id' => $locked->status_id,
            ];

            if (! empty($target['ticket_status_id']) && (int) $target['ticket_status_id'] !== (int) $locked->status_id) {
                $status = TicketStatus::query()->findOrFail((int) $target['ticket_status_id']);
                $this->changeStatus->handle(
                    $locked,
                    $status,
                    $actor,
                    enforceWorkflow: false,
                    syncRelationship: $syncRelationship,
                    notifyCustomerPortal: false,
                );
                $locked->refresh();
            }

            $locked->forceFill([
                'workflow_state_key' => $target['state_key'],
                'updated_by' => $actor?->id,
            ])->save();

            $this->applyAssignmentPolicy($locked, $target['assignment_policy'] ?? []);

            $locked->workflowReviews()
                ->where('state_key', '!=', $target['state_key'])
                ->whereIn('status', ['pending', 'approved'])
                ->whereNull('invalidated_at')
                ->update([
                    'status' => 'invalidated',
                    'invalidated_at' => now(),
                    'invalidation_reason' => 'Ticket moved to another workflow state.',
                    'updated_at' => now(),
                ]);

            $after = [
                'workflow_id' => $locked->workflow_id,
                'workflow_version_id' => $locked->workflow_version_id,
                'workflow_state_key' => $target['state_key'],
                'status_id' => $locked->status_id,
            ];

            $history = TicketWorkflowHistory::query()->create([
                'ticket_id' => $locked->id,
                'actor_id' => $actor?->id,
                'workflow_version_id' => $locked->workflow_version_id,
                'event_type' => 'transitioned',
                'from_state_key' => $before['workflow_state_key'],
                'to_state_key' => $target['state_key'],
                'transition_key' => $transitionKey,
                'idempotency_key' => $idempotencyKey,
                'requirements_snapshot' => $decision['requirements_result'],
                'before' => $before,
                'after' => $after,
                'message' => 'Workflow transitioned to '.$target['name'].'.',
                'metadata' => ['customer_notification' => TicketWorkflowCustomerNotificationPolicy::normalize($transition['customer_notification'] ?? null)],
            ]);

            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'actor_id' => $actor?->id,
                'type' => 'workflow_state_changed',
                'before' => $before,
                'after' => $after,
                'message' => 'Workflow state changed to '.$target['name'].'.',
                'metadata' => ['transition_key' => $transitionKey],
            ]);

            $this->queueCustomerUpdate($locked, $history, $transition, $before, $after, $actor);

            return $locked->refresh();
        });
    }

    public function handleToStatus(
        Ticket $ticket,
        TicketStatus $targetStatus,
        ?User $actor = null,
        ?string $idempotencyKey = null,
        bool $enforceActionGuard = true,
        bool $allowTerminal = true,
        bool $syncRelationship = true,
    ): Ticket {
        if ((int) $ticket->status_id === (int) $targetStatus->id) {
            return $ticket;
        }

        $matches = $this->runtime->transitionsToStatus($ticket, $targetStatus);

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages([
                'status_id' => $this->runtime->blockedReason($ticket, $targetStatus),
            ]);
        }

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'status_id' => 'More than one workflow step uses this reporting status. Use a specific workflow next-step action.',
            ]);
        }

        return $this->handle(
            $ticket,
            (string) $matches->first()['transition_key'],
            $actor,
            $idempotencyKey,
            $enforceActionGuard,
            $allowTerminal,
            $syncRelationship,
        );
    }

    /** @param array<string, mixed> $policy */
    private function applyAssignmentPolicy(Ticket $ticket, array $policy): void
    {
        $strategy = (string) ($policy['strategy'] ?? 'keep_if_eligible');
        $eligible = collect($policy['eligible_user_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $requiredPermissions = array_values(array_filter($policy['required_permissions'] ?? []));

        if ($requiredPermissions !== []) {
            $permissionEligible = User::query()->where('status', User::STATUS_ACTIVE)->get()
                ->filter(fn (User $user) => collect($requiredPermissions)->every(fn (string $permission) => $user->can($permission)))
                ->pluck('id')->map(fn ($id) => (int) $id);
            $eligible = $eligible->isEmpty() ? $permissionEligible : $eligible->intersect($permissionEligible);
        }

        $eligibleIds = $eligible->isEmpty() && $requiredPermissions === [] ? null : $eligible->all();
        $ownerEligible = ! $ticket->owner_id || $eligibleIds === null || in_array((int) $ticket->owner_id, $eligibleIds, true);

        if ($strategy === 'keep_if_eligible' && $ownerEligible) {
            return;
        }

        if ($strategy === 'manual' && $ownerEligible) {
            return;
        }

        $ticket->forceFill(['owner_id' => null])->save();
        if ($strategy === 'auto' || ($strategy === 'keep_if_eligible' && ! $ownerEligible)) {
            $this->assignments->assign($ticket, force: true, eligibleUserIds: $eligibleIds);
        }
    }

    /**
     * Queue a customer-safe public update only after a committed, Published transition.
     * Internal notes and workflow step names are intentionally excluded from the message.
     *
     * @param  array<string, mixed>  $transition
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function queueCustomerUpdate(
        Ticket $ticket,
        TicketWorkflowHistory $history,
        array $transition,
        array $before,
        array $after,
        ?User $actor,
    ): void {
        $policy = TicketWorkflowCustomerNotificationPolicy::normalize($transition['customer_notification'] ?? null);
        if (! $policy['enabled']) {
            return;
        }

        if (! $ticket->isPortalVisible()) {
            $this->setHistoryNotificationMetadata($history, [
                'status' => 'skipped',
                'reason' => 'Ticket is not Published to the customer.',
            ]);
            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'workflow_customer_update_skipped',
                'message' => 'Customer status update skipped because the Ticket is not Published.',
                'metadata' => ['workflow_history_id' => $history->id],
            ]);

            return;
        }

        $previousStatus = TicketStatus::query()->find($before['status_id'] ?? null)?->name ?? 'Previous status';
        $currentStatus = TicketStatus::query()->find($after['status_id'] ?? null)?->name ?? 'Current status';
        $customerMessage = $policy['message'] ?: ($previousStatus === $currentStatus
            ? 'Your Ticket remains '.$currentStatus.' and has received a new update.'
            : 'Your Ticket moved from '.$previousStatus.' to '.$currentStatus.'.');

        $message = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'author_id' => $actor?->id,
            'author_type' => $actor ? 'user' : 'system',
            'type' => 'status_update',
            'visibility' => 'public',
            'subject' => '['.$ticket->ticket_key.'] Status update',
            'body' => 'Status: '.$currentStatus."\n\n".$customerMessage,
            'metadata' => [
                'workflow_history_id' => $history->id,
                'transition_key' => $history->transition_key,
                'customer_status_update' => [
                    'policy' => $policy,
                    'previous_status' => $previousStatus,
                    'current_status' => $currentStatus,
                    'customer_message' => $customerMessage,
                    'delivery' => [],
                ],
            ],
        ]);

        $this->setHistoryNotificationMetadata($history, [
            'status' => 'queued',
            'ticket_message_id' => $message->id,
            'previous_status' => $previousStatus,
            'current_status' => $currentStatus,
        ]);
        TicketEvent::query()->create([
            'ticket_id' => $ticket->id,
            'actor_id' => $actor?->id,
            'type' => 'workflow_customer_update_queued',
            'message' => 'Customer status update queued after workflow transition.',
            'after' => ['ticket_message_id' => $message->id, 'channels' => $policy['channels']],
            'metadata' => ['workflow_history_id' => $history->id],
        ]);

        SendTicketWorkflowCustomerUpdate::dispatch($message->id)->afterCommit();
    }

    /** @param array<string, mixed> $notification */
    private function setHistoryNotificationMetadata(TicketWorkflowHistory $history, array $notification): void
    {
        $metadata = $history->metadata ?? [];
        $metadata['customer_notification'] = array_merge($metadata['customer_notification'] ?? [], $notification);
        $history->forceFill(['metadata' => $metadata])->save();
    }
}
