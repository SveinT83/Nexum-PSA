<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Notifications\TicketAssigned;
use App\Modules\Signal\Actions\RecordSignal;
use App\Modules\Signal\Models\Signal;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketWorkflowVersion;
use App\Modules\Ticket\Services\TicketAssignmentEngine;
use App\Modules\Ticket\Services\TicketRuleEngine;
use App\Modules\Ticket\Services\TicketRuleExecutionCoordinator;
use App\Modules\Ticket\Services\TicketRuleRuntimeGate;
use App\Modules\Ticket\Services\TicketSlaResolver;
use App\Modules\Ticket\Services\TicketWorkflowDefinitionService;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StoreTicket
{
    public function __construct(
        private readonly EnsureTicketDefaults $defaults,
        private readonly SuggestTicketKey $suggestTicketKey,
        private readonly TicketRuleEngine $ticketRuleEngine,
        private readonly TicketAssignmentEngine $ticketAssignmentEngine,
        private readonly TicketRuleRuntimeGate $ticketRuleRuntimeGate,
        private readonly TicketRuleExecutionCoordinator $ticketRuleExecutionCoordinator,
        private readonly TicketSlaResolver $ticketSlaResolver,
        private readonly TicketWorkflowDefinitionService $workflowDefinitions,
        private readonly AddTicketMessage $addTicketMessage,
        private readonly MutateTicketTags $mutateTicketTags,
        private readonly ResolveWorkContext $workContexts,
        private readonly RecordSignal $recordSignal,
        private readonly \App\Modules\Calendar\Actions\StoreCalendarEvent $storeCalendarEvent,
        private readonly \App\Modules\Calendar\Actions\LinkCalendarEvent $linkCalendarEvent,
    ) {}

    public function handle(array $data, ?User $actor = null): Ticket
    {
        $usesRuleV2 = false;
        $signalEmissions = [];
        $ruleExecutionResult = null;

        $ticket = DB::transaction(function () use ($data, $actor, &$usesRuleV2, &$signalEmissions, &$ruleExecutionResult) {
            $defaults = $this->defaults->handle();
            $data = array_merge([
                'channel' => 'manual',
                'ticket_type_id' => $defaults['type']->id,
                'queue_id' => $defaults['queue']->id,
                'priority_id' => $defaults['priority']->id,
            ], $data);
            $usesRuleV2 = $this->ticketRuleRuntimeGate->creationUsesV2();

            if (! $usesRuleV2) {
                $data = $this->ticketRuleEngine->apply('on_create', $data);
            }

            $signalEmissions = $data['_signal_emissions'] ?? [];
            $ticketType = TicketType::find($data['ticket_type_id'] ?? null) ?? $defaults['type'];
            $priority = TicketPriority::find($data['priority_id'] ?? null) ?? $defaults['priority'];
            $sla = $usesRuleV2
                ? [
                    'sla_id' => $this->positiveInteger($data['sla_id'] ?? null),
                    'sla_source' => null,
                    'sla_source_id' => null,
                    'sla_snapshot' => null,
                    'first_response_due_at' => null,
                    'resolve_due_at' => null,
                ]
                : $this->ticketSlaResolver->resolve($this->slaContext($data), $priority);
            $clientId = $data['client_id'] ?? null;
            $createdById = array_key_exists('_created_by_id', $data)
                ? $this->positiveInteger($data['_created_by_id'])
                : $actor?->id;
            $workContext = $this->workContexts->fromClientId($clientId);
            $fallbackStatusId = isset($data['status_id'])
                ? (int) $data['status_id']
                : (int) $defaults['status']->id;
            $workflowState = $this->workflowDefinitions->initialTicketState(
                isset($data['workflow_id']) ? (int) $data['workflow_id'] : null,
                $fallbackStatusId,
            );
            if ($pinnedVersionId = $this->positiveInteger($data['_workflow_version_id'] ?? null)) {
                $workflowState = $this->pinnedWorkflowState(
                    $pinnedVersionId,
                    isset($data['workflow_id']) ? (int) $data['workflow_id'] : null,
                    $fallbackStatusId,
                );
            }

            $ticket = $this->createWithUniqueTicketKey([
                'type' => $ticketType->slug,
                'ticket_type_id' => $ticketType->id,
                'queue_id' => $data['queue_id'] ?? $defaults['queue']->id,
                'status_id' => $workflowState['status_id'] ?? $data['status_id'] ?? $defaults['status']->id,
                'priority_id' => $priority->id,
                'sla_id' => $sla['sla_id'],
                'sla_source' => $sla['sla_source'],
                'sla_source_id' => $sla['sla_source_id'],
                'sla_snapshot' => $sla['sla_snapshot'],
                'workflow_id' => $workflowState['workflow_id'],
                'workflow_version_id' => $workflowState['workflow_version_id'],
                'workflow_state_key' => $workflowState['workflow_state_key'],
                'category_id' => $data['category_id'] ?? null,
                'client_id' => $clientId === '' ? null : $clientId,
                'work_context_id' => $workContext->id,
                'site_id' => $data['site_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'asset_id' => $data['asset_id'] ?? null,
                'owner_id' => array_key_exists('owner_id', $data) ? $data['owner_id'] : $actor?->id,
                'created_by' => $createdById,
                'updated_by' => $actor?->id,
                'channel' => $data['channel'] ?? 'manual',
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'impact' => $data['impact'] ?? null,
                'urgency' => $data['urgency'] ?? null,
                'is_unread' => false,
                'metadata' => $data['metadata'] ?? null,
                'first_response_due_at' => $sla['first_response_due_at'],
                'resolve_due_at' => $sla['resolve_due_at'],
            ]);

            if ($data['is_scheduled'] ?? false) {
                $schedule = $ticket->schedule()->create([
                    'schedule_type' => $data['schedule_type'] ?? 'one_time',
                    'planned_start_at' => $data['planned_start_at'] ?? null,
                    'planned_end_at' => $data['planned_end_at'] ?? null,
                    'timezone' => $data['timezone'] ?? 'UTC',
                    'recurrence_rule' => $data['recurrence_rule'] ?? null,
                    'recurrence_ends_at' => $data['recurrence_ends_at'] ?? null,
                    'sla_mode' => $data['sla_mode'] ?? 'defer_until_planned_start',
                    'status' => 'scheduled',
                    'created_by' => $createdById,
                    'updated_by' => $actor?->id ?? $createdById,
                ]);

                if (($data['link_to_calendar'] ?? false) && $actor && ($calendarId = $data['calendar_id'] ?? null)) {
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
            }

            if (! ($data['_skip_initial_description_note'] ?? false)
                && ! empty($data['description'])
                && ($data['channel'] ?? 'manual') !== 'email') {
                $this->addTicketMessage->handle($ticket, [
                    'type' => 'internal_note',
                    'visibility' => 'internal',
                    'subject' => $ticket->subject,
                    'body' => $data['description'],
                    'metadata' => [
                        'created_from' => 'ticket_initial_description',
                        'is_default_initial_note' => true,
                    ],
                    'suppress_notifications' => true,
                    'suppress_workflow_trigger' => true,
                    '_suppress_reply_delivery' => true,
                    '_suppress_assignment_claim' => true,
                    '_suppress_ticket_rule_dispatch' => true,
                    '_event_source_channel' => (string) ($data['channel'] ?? 'manual'),
                    '_event_source_action' => 'StoreTicket.initial_description',
                ], $actor);
            }

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'created',
                'message' => 'Ticket created.',
                'after' => [
                    'ticket_key' => $ticket->ticket_key,
                    'subject' => $ticket->subject,
                ],
            ]);

            if (array_key_exists('tag_ids', $data)) {
                $this->mutateTicketTags->initialize(
                    $ticket,
                    $this->normalizeTagIds($data['tag_ids']),
                    $actor,
                    [
                        'source_channel' => (string) ($data['channel'] ?? 'manual'),
                        'source_action' => 'StoreTicket.initial_tags',
                        '_suppress_ticket_rule_dispatch' => true,
                    ],
                );
            }

            if ($usesRuleV2) {
                $ruleExecutionResult = $this->ticketRuleExecutionCoordinator->executeCreated(
                    $ticket,
                    $data,
                    $actor,
                );
                $ticket->refresh();
                if (! (bool) data_get($ruleExecutionResult->summary, 'sla_decision', false)) {
                    $priority = TicketPriority::query()->findOrFail((int) $ticket->priority_id);
                    $slaContext = $data;
                    $slaContext['sla_id'] = $ticket->sla_id;
                    $sla = $this->ticketSlaResolver->resolve($this->slaContext($slaContext), $priority, $ticket->created_at);
                    $ticket->forceFill([
                        'sla_id' => $sla['sla_id'],
                        'sla_source' => $sla['sla_source'],
                        'sla_source_id' => $sla['sla_source_id'],
                        'sla_snapshot' => $sla['sla_snapshot'],
                        'first_response_due_at' => $sla['first_response_due_at'],
                        'resolve_due_at' => $sla['resolve_due_at'],
                    ])->save();
                }
            }

            // Assignment is intentionally last unless a successful rule made
            // an explicit Queue or Owner decision. Only rerun_assignment asks
            // the engine to reassess inside the ordered rule branch.
            if (! (bool) data_get($ruleExecutionResult?->summary, 'assignment_decision', false)) {
                $this->ticketAssignmentEngine->assign($ticket);
            }

            $ticket->refresh();
            if ($ruleExecutionResult) {
                $this->ticketRuleExecutionCoordinator->finalizeCreated($ticket, $ruleExecutionResult);
            }

            // External work must wait for the outermost transaction, including nested callers.
            if (! ($data['suppress_notifications'] ?? false)
                && $ticket->owner_id
                && $ticket->owner_id !== $actor?->id) {
                $ticketId = (int) $ticket->id;
                $ownerId = (int) $ticket->owner_id;
                $assignedBy = $actor?->name ?? 'System';
                DB::afterCommit(static function () use ($ticketId, $ownerId, $assignedBy): void {
                    $committedTicket = Ticket::query()->find($ticketId);
                    $owner = User::query()->find($ownerId);

                    if ($committedTicket && $owner) {
                        $owner->notify(new TicketAssigned(
                            ticket: $committedTicket,
                            assignedBy: $assignedBy,
                        ));
                    }
                });
            }

            return $ticket->fresh(['tags']);
        });

        if ($signalEmissions !== []) {
            $ticketId = (int) $ticket->id;
            DB::afterCommit(function () use ($ticketId, $signalEmissions): void {
                $committedTicket = Ticket::query()->find($ticketId);

                if ($committedTicket) {
                    $this->recordTicketRuleSignals($committedTicket, $signalEmissions);
                }
            });
        }

        return $ticket->fresh(['tags']);
    }

    private function recordTicketRuleSignals(Ticket $ticket, array $emissions): void
    {
        if (empty($emissions)) {
            return;
        }

        $ticket->loadMissing(['tags', 'contact']);
        $contactId = $ticket->contact?->contact_id;

        foreach ($emissions as $emission) {
            $signalType = (string) ($emission['signal_type'] ?? '');

            if ($signalType === '') {
                continue;
            }

            $existing = Signal::query()
                ->where('source_domain', 'ticket')
                ->where('source_type', $ticket->getMorphClass())
                ->where('source_id', $ticket->id)
                ->where('signal_type', $signalType)
                ->where('payload->ticket_rule_id', $emission['ticket_rule_id'] ?? null)
                ->where('payload->ticket_rule_action_index', $emission['ticket_rule_action_index'] ?? null)
                ->first();

            if ($existing) {
                continue;
            }

            $this->recordSignal->handle([
                'source_domain' => 'ticket',
                'source_type' => $ticket->getMorphClass(),
                'source_id' => $ticket->id,
                'contact_id' => $contactId,
                'client_id' => $ticket->client_id,
                'signal_type' => $signalType,
                'severity' => $emission['severity'] ?? 'info',
                'confidence' => $emission['confidence'] ?? 100,
                'summary' => $emission['summary'] ?? 'Ticket rule signal: '.str_replace('_', ' ', $signalType),
                'payload' => [
                    'ticket_id' => $ticket->id,
                    'ticket_key' => $ticket->ticket_key,
                    'ticket_rule_id' => $emission['ticket_rule_id'] ?? null,
                    'ticket_rule_name' => $emission['ticket_rule_name'] ?? null,
                    'ticket_rule_action_index' => $emission['ticket_rule_action_index'] ?? null,
                    'channel' => $ticket->channel,
                    'queue_id' => $ticket->queue_id,
                    'ticket_type_id' => $ticket->ticket_type_id,
                    'priority_id' => $ticket->priority_id,
                    'category_id' => $ticket->category_id,
                    'sla_id' => $ticket->sla_id,
                    'sla_source' => $ticket->sla_source,
                    'tags' => $ticket->tags->pluck('name')->values()->all(),
                    'note' => $emission['payload_note'] ?? null,
                ],
                'occurred_at' => $ticket->created_at ?: now(),
            ]);
        }
    }

    private function normalizeTagIds(mixed $tagIds): array
    {
        return collect((array) $tagIds)
            ->filter(fn ($tagId) => is_numeric($tagId))
            ->map(fn ($tagId) => (int) $tagId)
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data */
    private function slaContext(array $data): array
    {
        if (! array_key_exists('_sla_planned_start_at', $data)) {
            return $data;
        }

        $data['is_scheduled'] = true;
        $data['planned_start_at'] = $data['_sla_planned_start_at'];

        return $data;
    }

    /**
     * @return array{workflow_id: int, workflow_version_id: int, workflow_state_key: string, status_id: int}
     */
    private function pinnedWorkflowState(int $versionId, ?int $workflowId, int $fallbackStatusId): array
    {
        $version = TicketWorkflowVersion::query()
            ->whereKey($versionId)
            ->firstOrFail();

        if ($workflowId !== null && (int) $version->ticket_workflow_id !== $workflowId) {
            throw new RuntimeException('The pinned Ticket workflow version does not belong to the selected workflow.');
        }

        $states = collect((array) data_get($version->definition, 'states', []));
        $state = $states->firstWhere('ticket_status_id', $fallbackStatusId)
            ?? $states->firstWhere('is_initial', true)
            ?? $states->sortBy('sort_order')->first();

        if (! is_array($state) || ! isset($state['state_key'], $state['ticket_status_id'])) {
            throw new RuntimeException('The pinned Ticket workflow version has no usable initial state.');
        }

        return [
            'workflow_id' => (int) $version->ticket_workflow_id,
            'workflow_version_id' => (int) $version->id,
            'workflow_state_key' => (string) $state['state_key'],
            'status_id' => (int) $state['ticket_status_id'],
        ];
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $attributes */
    private function createWithUniqueTicketKey(array $attributes): Ticket
    {
        return Ticket::create(array_merge(
            ['ticket_key' => $this->suggestTicketKey->handle()],
            $attributes,
        ));
    }
}
