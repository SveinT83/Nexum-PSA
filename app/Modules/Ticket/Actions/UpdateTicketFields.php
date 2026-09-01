<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketMutationResult;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateTicketFields
{
    public function __construct(
        private readonly ResolveWorkContext $workContexts,
        private readonly \App\Modules\Calendar\Actions\StoreCalendarEvent $storeCalendarEvent,
        private readonly \App\Modules\Calendar\Actions\LinkCalendarEvent $linkCalendarEvent,
        private readonly ApplyTicketSla $applyTicketSla,
        private readonly DispatchTicketRuleMutationEvent $dispatchRules,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Editable ticket fields
    |--------------------------------------------------------------------------
    |
     | This action captures the editable ticket fields technicians can change
     | before workflows exist. Status has a dedicated action because it drives
     | lifecycle timestamps. Asset is included here because it is ticket context,
     | not a workflow transition.
    |
    */
    public function handle(Ticket $ticket, array $data, ?User $actor = null): Ticket
    {
        return $this->handleWithResult($ticket, $data, $actor)->ticket;
    }

    public function handleWithResult(Ticket $ticket, array $data, ?User $actor = null): TicketMutationResult
    {
        $changed = false;
        $result = DB::transaction(function () use ($ticket, $data, $actor, &$changed): TicketMutationResult {
            $ticket = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $fields = [
                'subject',
                'description',
                'ticket_type_id',
                'queue_id',
                'priority_id',
                'sla_id',
                'category_id',
                'client_id',
                'site_id',
                'contact_id',
                'asset_id',
                'owner_id',
                'impact',
                'urgency',
            ];
            $trackedFields = array_values(array_unique(array_merge($fields, [
                'work_context_id',
                'sla_source',
                'sla_source_id',
                'sla_snapshot',
                'first_response_due_at',
                'resolve_due_at',
            ])));
            $initialState = collect($trackedFields)
                ->mapWithKeys(fn (string $field): array => [$field => $ticket->{$field}])
                ->all();
            $updates = array_intersect_key($data, array_flip($fields));
            $before = [];
            $after = [];

            foreach ($updates as $field => $value) {
                $normalizedValue = $value === '' ? null : $value;

                if (! $this->valuesEquivalent($ticket->{$field}, $normalizedValue)) {
                    $before[$field] = $ticket->{$field};
                    $after[$field] = $normalizedValue;
                }
            }

            if (array_key_exists('client_id', $updates)) {
                $normalizedClientId = $updates['client_id'] === '' ? null : $updates['client_id'];
                $workContext = $this->workContexts->fromClientId($normalizedClientId);

                if (! $this->valuesEquivalent($ticket->work_context_id, $workContext->id)) {
                    $before['work_context_id'] = $ticket->work_context_id;
                    $after['work_context_id'] = $workContext->id;
                }
            }

            $sla = null;
            $slaWasSubmitted = array_key_exists('sla_id', $updates);
            if ($slaWasSubmitted) {
                $slaId = $updates['sla_id'];
                if ((! is_int($slaId) && ! (is_string($slaId) && preg_match('/\A[1-9][0-9]*\z/', $slaId) === 1))
                    || (int) $slaId < 1
                    || ! ($sla = Sla::query()->find((int) $slaId))) {
                    throw ValidationException::withMessages([
                        'sla_id' => 'Select an available SLA policy.',
                    ]);
                }
            }

            if (array_key_exists('is_scheduled', $data)) {
                $isScheduled = (bool) $data['is_scheduled'];
                $schedule = $ticket->schedule()->first(); // Use query to avoid stale cache
                $changed = true;

                if ($isScheduled) {
                    $scheduleData = [
                        'schedule_type' => $data['schedule_type'] ?? 'one_time',
                        'planned_start_at' => $data['planned_start_at'] ?? null,
                        'planned_end_at' => $data['planned_end_at'] ?? null,
                        'timezone' => $data['timezone'] ?? 'UTC',
                        'recurrence_rule' => $data['recurrence_rule'] ?? null,
                        'recurrence_ends_at' => $data['recurrence_ends_at'] ?? null,
                        'sla_mode' => $data['sla_mode'] ?? 'defer_until_planned_start',
                        'status' => 'scheduled',
                        'updated_by' => $actor?->id,
                    ];

                    if ($schedule) {
                        $schedule->update(array_filter($scheduleData, fn ($value) => $value !== null || in_array($value, ['planned_start_at', 'planned_end_at', 'recurrence_rule', 'recurrence_ends_at'])));
                    } else {
                        $scheduleData['created_by'] = $actor?->id;
                        $schedule = $ticket->schedule()->create($scheduleData);
                        $ticket->unsetRelation('schedule');
                    }

                    if (($data['link_to_calendar'] ?? false) && $actor && ($calendarId = $data['calendar_id'] ?? null) && ! $schedule->calendar_event_id) {
                        $event = $this->storeCalendarEvent->handle([
                            'calendar_id' => $calendarId,
                            'title' => 'Ticket: '.$ticket->subject,
                            'description' => $ticket->description,
                            'starts_at' => $schedule->planned_start_at,
                            'ends_at' => $schedule->planned_end_at ?: $schedule->planned_start_at->copy()->addHour(),
                            'timezone' => $schedule->timezone,
                            'metadata' => ['ticket_id' => $ticket->id],
                        ], $actor);

                        $this->linkCalendarEvent->handle($event, $ticket, 'scheduled_for');
                        $schedule->update(['calendar_event_id' => $event->id]);
                    }
                } elseif ($schedule) {
                    $schedule->delete();
                    $ticket->unsetRelation('schedule');
                }
            }

            $directAfter = $after;
            unset($directAfter['sla_id']);
            if ($directAfter === [] && ! $slaWasSubmitted && ! array_key_exists('is_scheduled', $data)) {
                return TicketMutationResult::noChange($ticket);
            }

            $changed = true;
            $wasUnassigned = blank($ticket->owner_id);
            $ownerWasSubmitted = array_key_exists('owner_id', $updates);

            if ($directAfter !== [] || array_key_exists('is_scheduled', $data)) {
                $ticket->forceFill(array_merge($directAfter, [
                    'updated_by' => $actor?->id,
                ]))->save();
            }

            if ($sla) {
                $this->applyTicketSla->handle(
                    $ticket->refresh(),
                    $sla,
                    $actor,
                    source: (string) ($data['_sla_source'] ?? ($actor?->isSystemActor() ? 'ticket_rule' : 'manual')),
                    suppressWorkflowTrigger: true,
                );
            }

            $ticket = $ticket->refresh();
            $ticketChangedBeforeClaim = collect($trackedFields)->contains(
                fn (string $field): bool => ! $this->valuesEquivalent($initialState[$field] ?? null, $ticket->{$field}),
            );
            if (($ticketChangedBeforeClaim || array_key_exists('is_scheduled', $data))
                && ($wasUnassigned || ! $ownerWasSubmitted)) {
                app(ClaimUnassignedTicket::class)->handle($ticket->refresh(), $actor, 'fields_updated');
            }

            $ticket = $ticket->refresh();
            $eventBefore = [];
            $eventAfter = [];
            foreach ($trackedFields as $field) {
                if ($this->valuesEquivalent($initialState[$field] ?? null, $ticket->{$field})) {
                    continue;
                }

                $eventBefore[$field] = $this->eventValue($initialState[$field] ?? null);
                $eventAfter[$field] = $this->eventValue($ticket->{$field});
            }

            if ($eventAfter === [] && ! array_key_exists('is_scheduled', $data)) {
                $changed = false;

                return TicketMutationResult::noChange($ticket);
            }

            $history = TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'fields_updated',
                'before' => $eventBefore,
                'after' => $eventAfter,
                'message' => 'Ticket fields updated.',
            ]);

            if (array_intersect(array_keys($eventAfter), ['client_id', 'site_id', 'contact_id', 'asset_id', 'category_id', 'queue_id', 'owner_id']) !== []) {
                app(InvalidateTicketWorkflowReviews::class)->handle($ticket, 'Material Ticket fields changed.', $actor);
            }

            $mutationEvent = $eventAfter === []
                ? null
                : $this->mutationEvent($ticket, $eventBefore, $eventAfter, $history, $data, $actor);

            if ($mutationEvent && ! ($data['_suppress_ticket_rule_dispatch'] ?? false)) {
                $this->dispatchRules->handle($ticket, $mutationEvent, $actor);
            }

            return new TicketMutationResult($ticket->refresh(), $mutationEvent);
        });

        if ($changed) {
            app(ApplyTicketWorkflowActionTrigger::class)->handle($result->ticket->refresh(), TicketAction::UPDATE_FIELDS, $actor);
        }

        return new TicketMutationResult($result->ticket->refresh(), $result->event);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $data
     */
    private function mutationEvent(
        Ticket $ticket,
        array $before,
        array $after,
        TicketEvent $history,
        array $data,
        ?User $actor,
    ): TicketRuleMutationEvent {
        $sourceChannel = (string) ($data['_event_source_channel'] ?? ($actor?->isSystemActor() ? 'ticket_rule' : ($actor ? 'tech' : 'system')));
        $sourceAction = (string) ($data['_event_source_action'] ?? 'UpdateTicketFields');
        $assignmentChanges = $this->assignmentChanges($before, $after);
        $safeFacts = [
            'event_source_channel' => $sourceChannel,
            'event_source_action' => $sourceAction,
        ];
        if ($assignmentChanges !== []) {
            $safeFacts['assignment_changes'] = $assignmentChanges;
        }

        return TicketRuleMutationEvent::make(
            ticketId: (int) $ticket->id,
            eventKey: TicketRuleTriggerRegistry::UPDATED,
            changedFields: array_keys($after),
            before: $before,
            after: $after,
            safeFacts: $safeFacts,
            classification: [
                'assignment_changes' => $assignmentChanges,
                'specialized_triggers_share_root_event' => true,
            ],
            sourceChannel: $sourceChannel,
            sourceAction: $sourceAction,
            deliveryIdentity: (string) ($data['_delivery_key'] ?? 'ticket-event:'.$history->id),
            relatedRecordType: TicketEvent::class,
            relatedRecordId: (int) $history->id,
            correlationUuid: $data['_correlation_uuid'] ?? null,
            causationUuid: $data['_causation_uuid'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function assignmentChanges(array $before, array $after): array
    {
        $changes = [];
        if (array_key_exists('queue_id', $after)) {
            $changes[] = 'queue_changed';
        }

        if (! array_key_exists('owner_id', $after)) {
            return $changes;
        }

        $beforeOwner = $before['owner_id'] ?? null;
        $afterOwner = $after['owner_id'] ?? null;
        if ($beforeOwner === null && $afterOwner !== null) {
            $changes[] = 'owner_assigned';
        } elseif ($beforeOwner !== null && $afterOwner === null) {
            $changes[] = 'owner_unassigned';
        } else {
            $changes[] = 'owner_changed';
        }

        return $changes;
    }

    private function valuesEquivalent(mixed $before, mixed $after): bool
    {
        if ($before instanceof DateTimeInterface || $after instanceof DateTimeInterface || is_array($before) || is_array($after)) {
            return json_encode($this->eventValue($before), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                === json_encode($this->eventValue($after), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string) $before === (string) $after;
    }

    private function eventValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): mixed => $this->eventValue($item))
                ->all();
        }

        return $value;
    }
}
