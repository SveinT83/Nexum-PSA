<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Economy\Jobs\GenerateEconomyOrdersJob;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Notification\Notifications\TicketStatusChanged;
use App\Modules\Relationship\Actions\SyncTicketStatusToRelationship;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeTicketStatus
{
    public function __construct(
        private readonly TicketWorkflowRuntime $workflowRuntime,
        private readonly DispatchTicketRuleMutationEvent $dispatchRules,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Status lifecycle foundation
    |--------------------------------------------------------------------------
    |
    | Status changes are the stable low-level operation that future workflows
    | will validate. Keep timestamp side effects here so controllers, rules, and
    | inbound email handlers all get the same lifecycle behavior.
    |
    */
    public function handle(
        Ticket $ticket,
        TicketStatus $status,
        ?User $actor = null,
        bool $enforceWorkflow = true,
        bool $syncRelationship = true,
        bool $notifyCustomerPortal = true,
        bool $notifyOwner = true,
        array $ruleContext = [],
    ): Ticket {
        $this->assertCanChange($ticket, $status, $actor, $enforceWorkflow);

        return DB::transaction(function () use ($ticket, $status, $actor, $syncRelationship, $notifyCustomerPortal, $notifyOwner, $ruleContext) {
            $initialOwnerId = $ticket->owner_id ? (int) $ticket->owner_id : null;
            $claimedTicket = null;
            if ((int) $ticket->status_id !== (int) $status->id) {
                $claimedTicket = app(ClaimUnassignedTicket::class)->handle($ticket, $actor, 'status_changed');
            }

            $before = [
                'status_id' => $ticket->status_id,
                'resolved_at' => $ticket->resolved_at?->toISOString(),
                'closed_at' => $ticket->closed_at?->toISOString(),
            ];

            $updates = [
                'status_id' => $status->id,
                'updated_by' => $actor?->id,
            ];

            if ($status->is_closed) {
                $updates['resolved_at'] = $ticket->resolved_at ?? now();
                $updates['closed_at'] = $ticket->closed_at ?? now();
            } elseif ($status->state === 'resolved') {
                $updates['resolved_at'] = $ticket->resolved_at ?? now();
                $updates['closed_at'] = null;
            } else {
                $updates['resolved_at'] = null;
                $updates['closed_at'] = null;
            }

            $ticket->forceFill($updates)->save();
            $ticket->refresh();

            $after = [
                'status_id' => $ticket->status_id,
                'resolved_at' => $ticket->resolved_at?->toISOString(),
                'closed_at' => $ticket->closed_at?->toISOString(),
            ];

            if ($before !== $after) {
                $history = TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'actor_id' => $actor?->id,
                    'type' => 'status_changed',
                    'before' => $before,
                    'after' => $after,
                    'message' => 'Ticket status changed to '.$status->name.'.',
                ]);
                $ruleBefore = $before;
                $ruleAfter = $after;
                $assignmentChanges = [];
                if ($claimedTicket) {
                    $ruleBefore['owner_id'] = $initialOwnerId;
                    $ruleAfter['owner_id'] = (int) $claimedTicket->owner_id;
                    $assignmentChanges[] = 'owner_assigned';
                }

                if (! ($ruleContext['_suppress_ticket_rule_dispatch'] ?? false)) {
                    $changedFields = collect(array_keys($ruleAfter))
                        ->filter(fn (string $field): bool => ($ruleBefore[$field] ?? null) !== $ruleAfter[$field])
                        ->values()
                        ->all();
                    $sourceChannel = (string) ($ruleContext['_event_source_channel']
                        ?? ($actor?->isSystemActor() ? 'ticket_rule' : ($actor ? 'tech' : 'system')));
                    $sourceAction = (string) ($ruleContext['_event_source_action'] ?? 'ChangeTicketStatus');
                    $eventKeys = [
                        TicketRuleTriggerRegistry::STATUS_CHANGED,
                        TicketRuleTriggerRegistry::UPDATED,
                    ];
                    if ($assignmentChanges !== []) {
                        $eventKeys[] = TicketRuleTriggerRegistry::ASSIGNMENT_CHANGED;
                    }
                    $mutation = TicketRuleMutationEvent::make(
                        ticketId: (int) $ticket->id,
                        eventKey: TicketRuleTriggerRegistry::STATUS_CHANGED,
                        changedFields: $changedFields,
                        before: array_intersect_key($ruleBefore, array_flip($changedFields)),
                        after: array_intersect_key($ruleAfter, array_flip($changedFields)),
                        safeFacts: [
                            'status_id' => (int) $ticket->status_id,
                            'assignment_changes' => $assignmentChanges,
                            'event_source_channel' => $sourceChannel,
                            'event_source_action' => $sourceAction,
                        ],
                        classification: [
                            'event_keys' => $eventKeys,
                            'status_changed' => true,
                            'assignment_changes' => $assignmentChanges,
                            'specialized_triggers_share_root_event' => true,
                        ],
                        sourceChannel: $sourceChannel,
                        sourceAction: $sourceAction,
                        deliveryIdentity: (string) ($ruleContext['_delivery_key'] ?? 'ticket-event:'.$history->id),
                        relatedRecordType: TicketEvent::class,
                        relatedRecordId: (int) $history->id,
                        correlationUuid: $ruleContext['_correlation_uuid'] ?? null,
                        causationUuid: $ruleContext['_causation_uuid'] ?? null,
                    );
                    $this->dispatchRules->handle($ticket, $mutation, $actor);
                }

                // Notify the ticket owner if they didn't change the status themselves
                if ($notifyOwner && $ticket->owner_id && $ticket->owner_id !== $actor?->id) {
                    $owner = User::find($ticket->owner_id);
                    if ($owner) {
                        $oldStatusName = TicketStatus::find($before['status_id'])?->name ?? 'Unknown';
                        $owner->notify(new TicketStatusChanged(
                            ticket: $ticket,
                            oldStatus: $oldStatusName,
                            newStatus: $status->name,
                            changedBy: $actor?->name,
                        ));
                    }
                }

                if ($notifyCustomerPortal && $ticket->isPortalVisible() && $ticket->client_id) {
                    $oldStatusName = TicketStatus::find($before['status_id'])?->name ?? 'Previous status';

                    app(SendCustomerPortalNotification::class)->handle(
                        type: 'portal_ticket_status_changed',
                        clientId: (int) $ticket->client_id,
                        siteId: $ticket->site_id ? (int) $ticket->site_id : null,
                        title: 'Ticket '.$ticket->ticket_key.' status changed',
                        body: $oldStatusName.' changed to '.$status->name.' for '.$ticket->subject.'.',
                        url: route('customer-portal.tickets.show', $ticket),
                        sourceType: Ticket::class,
                        sourceId: $ticket->id,
                        metadata: [
                            'ticket_key' => $ticket->ticket_key,
                            'old_status' => $oldStatusName,
                            'new_status' => $status->name,
                        ],
                    );
                }

                if ($status->is_closed && ! in_array($ticket->close_outcome, ['customer_declined', 'cancelled', 'no_sale'], true)) {
                    GenerateEconomyOrdersJob::dispatch(
                        $ticket->closed_at?->copy()->startOfMonth()->toDateString(),
                        $ticket->closed_at?->copy()->endOfMonth()->toDateString(),
                        $actor?->id,
                    )->onQueue('economy')->afterCommit();
                }

                if ($syncRelationship) {
                    DB::afterCommit(fn () => app(SyncTicketStatusToRelationship::class)->handle($ticket->id));
                }
            }

            return $ticket;
        });
    }

    /**
     * Run lifecycle validation before callers open a wider transaction.
     * This keeps blocked-attempt audit events durable when a caller aborts.
     */
    public function assertCanChange(Ticket $ticket, TicketStatus $status, ?User $actor = null, bool $enforceWorkflow = true): void
    {
        if ($status->is_closed && $this->hasUnresolvedTasks($ticket)) {
            $reason = 'Ticket cannot be closed while it has unresolved tasks.';

            $this->recordBlockedStatusChange($ticket, $status, $actor, 'ticket_close_blocked', $reason);

            throw ValidationException::withMessages(['status_id' => $reason]);
        }

        if ($enforceWorkflow && $reason = $this->workflowRuntime->blockedReason($ticket, $status)) {
            $this->recordBlockedStatusChange($ticket, $status, $actor, 'workflow_transition_blocked', $reason);

            throw ValidationException::withMessages(['status_id' => $reason]);
        }
    }

    private function recordBlockedStatusChange(Ticket $ticket, TicketStatus $status, ?User $actor, string $type, string $reason): void
    {
        TicketEvent::create([
            'ticket_id' => $ticket->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'message' => $reason,
            'before' => ['status_id' => $ticket->status_id],
            'after' => ['status_id' => $status->id],
        ]);
    }

    private function hasUnresolvedTasks(Ticket $ticket): bool
    {
        return $ticket->tasks()
            ->whereNull('completed_at')
            ->whereHas('status', fn ($query) => $query
                ->where('is_done', false)
                ->where('is_cancelled', false)
            )
            ->exists();
    }
}
