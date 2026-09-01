<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketMutationResult;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical explicit assignment boundary for one individual Ticket Owner.
 */
final class AssignTicketOwner
{
    public function __construct(
        private readonly TicketActionGuard $actionGuard,
        private readonly TicketWorkflowRuntime $workflow,
        private readonly TicketMutationScopeGuard $scope,
        private readonly DispatchTicketRuleMutationEvent $dispatchRules,
    ) {}

    /**
     * @param  array<string, mixed>  $context  Runtime-only causation metadata.
     */
    public function handle(
        Ticket $ticket,
        ?int $ownerId,
        User $actor,
        array $context = [],
    ): TicketMutationResult {
        return DB::transaction(function () use ($ticket, $ownerId, $actor, $context): TicketMutationResult {
            $ticket = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $this->scope->assert($ticket);

            if ($reason = $this->actionGuard->reason($ticket, TicketAction::ASSIGN_OTHER, $actor)) {
                throw ValidationException::withMessages(['owner_id' => $reason]);
            }

            $owner = $ownerId === null ? null : $this->eligibleOwner($ticket, $ownerId);
            $beforeOwnerId = $ticket->owner_id === null ? null : (int) $ticket->owner_id;
            $afterOwnerId = $owner?->id;

            if ($beforeOwnerId === $afterOwnerId) {
                return TicketMutationResult::noChange($ticket);
            }

            $queueId = $ticket->queue_id === null ? null : (int) $ticket->queue_id;
            $ticket->forceFill([
                'owner_id' => $afterOwnerId,
                'updated_by' => $actor->id,
            ])->save();

            $assignmentChange = $beforeOwnerId === null
                ? 'owner_assigned'
                : ($afterOwnerId === null ? 'owner_unassigned' : 'owner_changed');
            $history = TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor->id,
                'type' => $afterOwnerId === null ? 'unassigned' : 'assigned',
                'message' => $afterOwnerId === null
                    ? 'Ticket Owner unassigned.'
                    : 'Ticket assigned to an eligible Owner.',
                'before' => [
                    'queue_id' => $queueId,
                    'owner_id' => $beforeOwnerId,
                ],
                'after' => [
                    'queue_id' => $queueId,
                    'owner_id' => $afterOwnerId,
                    'assignment_change' => $assignmentChange,
                ],
            ]);

            $event = TicketRuleMutationEvent::make(
                ticketId: (int) $ticket->id,
                eventKey: TicketRuleTriggerRegistry::UPDATED,
                changedFields: ['owner_id'],
                before: ['owner_id' => $beforeOwnerId],
                after: ['owner_id' => $afterOwnerId],
                safeFacts: [
                    'queue_id' => $queueId,
                    'owner_id' => $afterOwnerId,
                    'assignment_changes' => [$assignmentChange],
                    'event_source_channel' => $this->sourceChannel($actor, $context),
                    'event_source_action' => (string) ($context['source_action'] ?? 'AssignTicketOwner'),
                ],
                classification: [
                    'assignment_changes' => [$assignmentChange],
                    'assignment_concept' => 'individual_owner',
                ],
                sourceChannel: $this->sourceChannel($actor, $context),
                sourceAction: (string) ($context['source_action'] ?? 'AssignTicketOwner'),
                deliveryIdentity: (string) ($context['delivery_identity'] ?? 'ticket-event:'.$history->id),
                relatedRecordType: TicketEvent::class,
                relatedRecordId: (int) $history->id,
                correlationUuid: $context['correlation_uuid'] ?? null,
                causationUuid: $context['causation_uuid'] ?? null,
            );

            $suppressRuleDispatch = (bool) ($context['_suppress_ticket_rule_dispatch']
                ?? $context['suppress_rule_dispatch']
                ?? false);
            if (! $suppressRuleDispatch) {
                $this->dispatchRules->handle($ticket, $event, $actor);
            }

            return new TicketMutationResult($ticket->refresh(), $event);
        });
    }

    private function eligibleOwner(Ticket $ticket, int $ownerId): User
    {
        if ($ownerId < 1) {
            throw ValidationException::withMessages(['owner_id' => 'Select an active eligible Owner.']);
        }

        $owner = User::query()
            ->whereKey($ownerId)
            ->where('status', User::STATUS_ACTIVE)
            ->where('is_system_actor', false)
            ->first();
        if (! $owner) {
            throw ValidationException::withMessages(['owner_id' => 'Select an active eligible Owner.']);
        }

        $policy = (array) data_get($this->workflow->currentState($ticket), 'assignment_policy', []);
        $eligibleIds = collect($policy['eligible_user_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique();
        $requiredPermissions = collect($policy['required_permissions'] ?? [])
            ->filter(fn (mixed $permission): bool => is_string($permission) && $permission !== '');

        if (($policy['strategy'] ?? null) === 'unassigned'
            || ($eligibleIds->isNotEmpty() && ! $eligibleIds->contains((int) $owner->id))
            || ! $requiredPermissions->every(fn (string $permission): bool => $owner->can($permission))) {
            throw ValidationException::withMessages([
                'owner_id' => 'The selected Owner is not eligible in the current Workflow state.',
            ]);
        }

        return $owner;
    }

    /** @param array<string, mixed> $context */
    private function sourceChannel(User $actor, array $context): string
    {
        return (string) ($context['source_channel'] ?? ($actor->isSystemActor() ? 'ticket_rule' : 'tech'));
    }
}
