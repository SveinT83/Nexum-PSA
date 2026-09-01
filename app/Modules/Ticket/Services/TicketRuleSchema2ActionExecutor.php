<?php

namespace App\Modules\Ticket\Services;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\AddTicketMessage;
use App\Modules\Ticket\Actions\AssignTicketOwner;
use App\Modules\Ticket\Actions\MutateTicketTags;
use App\Modules\Ticket\Actions\UpdateTicketFields;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketMutationResult;
use App\Modules\Ticket\Support\TicketRuleActionFailure;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleEventEnvelope;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Runtime bridge from schema 2 action definitions to authoritative Ticket Actions.
 *
 * The bridge never executes classes or callables selected by rule JSON. It
 * revalidates the declarative provider contract, then invokes one fixed domain
 * boundary and returns only privacy-safe persistence evidence.
 */
final class TicketRuleSchema2ActionExecutor
{
    public function __construct(
        private readonly TicketRuleActionProviderRegistry $providers,
        private readonly TicketActionGuard $guard,
        private readonly TicketRuleAuditSanitizer $sanitizer,
        private readonly TicketWorkflowRuntime $workflow,
        private readonly TicketRuleWorkflowActionExecutor $workflowActions,
        private readonly TicketRuleCustomFieldActionExecutor $customFieldActions,
        private readonly UpdateTicketFields $updateFields,
        private readonly AssignTicketOwner $assignOwner,
        private readonly MutateTicketTags $mutateTags,
        private readonly AddTicketMessage $addMessage,
        private readonly TicketAssignmentEngine $assignmentEngine,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function handle(
        Ticket $ticket,
        array $action,
        User $actor,
        TicketRuleEventEnvelope $event,
        bool $apply,
        string $actionIdempotencyKey,
    ): array {
        $this->assertInvocation($ticket, $actor, $event, $actionIdempotencyKey);

        $canonical = $this->providers->canonicalizeAction($action);
        if (! ($canonical['valid'] ?? false)) {
            throw new TicketRuleActionFailure(
                (string) ($canonical['reason_code'] ?? 'invalid_schema2_action'),
                'The schema 2 Ticket Rule action input is invalid.',
            );
        }

        $action = $canonical['action'];
        $type = (string) $action['type'];
        $input = (array) $action['input'];
        $provider = $this->providers->definition($type);
        if ($provider === null) {
            throw new TicketRuleActionFailure(
                'unknown_action_type',
                'The schema 2 Ticket Rule action provider is unavailable.',
            );
        }
        if (! $this->providers->enabled($type)) {
            throw new TicketRuleActionFailure(
                'action_capability_disabled',
                'The schema 2 Ticket Rule action capability is disabled.',
            );
        }

        if ($this->isWorkflowAction($type)) {
            $result = $this->workflowActions->handle(
                $ticket,
                $type,
                $input,
                $actor,
                $actionIdempotencyKey,
                $event->eventKey === TicketRuleTriggerRegistry::CREATED
                    ? TicketRuleWorkflowActionExecutor::PHASE_CREATION
                    : TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
                $apply,
            );
            $result['authorization'] = array_merge(
                (array) ($result['authorization'] ?? []),
                [
                    'capability' => $provider['capability_key'],
                    'targets_revalidated' => true,
                    'allowed' => true,
                ],
            );
            $result['derived_events'] = array_values((array) ($result['derived_events'] ?? []));
            $result['assignment_decision'] = (bool) ($result['assignment_decision'] ?? false);
            $result['sla_decision'] = false;

            return $result;
        }

        if ($this->isCustomFieldAction($type)) {
            $permission = (string) $provider['runtime_permission'];
            $ticketActions = [TicketAction::UPDATE_FIELDS];
            $this->assertPermission($actor, $permission);
            $this->assertGuards($ticket, $ticketActions, $actor);

            $result = $this->customFieldActions->handle(
                $ticket,
                $type,
                $input,
                $actor,
                $event,
                $apply,
                $actionIdempotencyKey,
            );

            return $this->authorized(
                $result,
                $provider,
                $permission,
                $ticketActions,
                false,
                false,
            );
        }

        $permission = (string) $provider['runtime_permission'];
        $ticketActions = $this->ticketActions($type, $input);
        $this->assertPermission($actor, $permission);
        $this->assertGuards($ticket, $ticketActions, $actor);
        $this->assertTargets($ticket, $type, $input);

        $assignmentDecision = (bool) ($provider['assignment_decision'] ?? false);
        $slaDecision = $type === TicketRuleActionProviderRegistry::SET_TICKET_FIELDS
            && array_key_exists('sla_id', (array) ($input['fields'] ?? []));

        if (! $apply) {
            $result = $this->project($ticket, $type, $input, $event);

            return $this->authorized(
                $result,
                $provider,
                $permission,
                $ticketActions,
                $assignmentDecision,
                $slaDecision,
            );
        }

        try {
            $result = $this->execute(
                $ticket,
                $type,
                $input,
                $actor,
                $event,
                $actionIdempotencyKey,
            );
        } catch (ValidationException) {
            throw new TicketRuleActionFailure(
                'canonical_action_denied',
                'The authoritative Ticket action denied this schema 2 rule action.',
            );
        }

        return $this->authorized(
            $result,
            $provider,
            $permission,
            $ticketActions,
            $assignmentDecision
                || $this->eventChangesAssignment($result['derived_events'] ?? []),
            $slaDecision,
        );
    }

    /**
     * Build the durable action snapshot without retaining private free text.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function snapshot(array $action): array
    {
        $canonical = $this->providers->canonicalizeAction($action);
        if (! ($canonical['valid'] ?? false)) {
            return $this->sanitizer->map($action);
        }

        $action = $canonical['action'];
        $type = (string) $action['type'];
        $input = (array) $action['input'];
        if ($this->isCustomFieldAction($type)) {
            return $this->customFieldActions->snapshot($action);
        }

        $safeInput = $type === TicketRuleActionProviderRegistry::SET_TICKET_FIELDS
            ? ['fields' => $this->sanitizer->map((array) ($input['fields'] ?? []))]
            : $this->sanitizer->map($input);

        return [
            'type' => $type,
            'input' => $safeInput,
        ];
    }

    private function assertInvocation(
        Ticket $ticket,
        User $actor,
        TicketRuleEventEnvelope $event,
        string $idempotencyKey,
    ): void {
        if (! $ticket->exists || (int) $ticket->id < 1 || (int) $ticket->id !== $event->ticketId) {
            throw new TicketRuleActionFailure(
                'event_ticket_mismatch',
                'The Ticket Rule event does not belong to the target Ticket.',
            );
        }
        if (! $actor->isSystemActor()
            || (int) $actor->id < 1
            || (int) $actor->id !== $event->automationActorId) {
            throw new TicketRuleActionFailure(
                'automation_actor_required',
                'Schema 2 Ticket Rule actions require the protected automation actor.',
            );
        }
        if (strlen($idempotencyKey) !== 64 || ! ctype_xdigit($idempotencyKey)) {
            throw new TicketRuleActionFailure(
                'invalid_idempotency_key',
                'A deterministic action-position idempotency key is required.',
            );
        }
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if ($permission === ''
            || ! Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists()
            || ! $actor->can($permission)) {
            throw new TicketRuleActionFailure(
                'automation_permission_denied',
                'The Ticket Rule automation actor lacks the required action permission.',
            );
        }
    }

    /**
     * @param  list<string>  $ticketActions
     */
    private function assertGuards(Ticket $ticket, array $ticketActions, User $actor): void
    {
        foreach ($ticketActions as $ticketAction) {
            $decision = $this->guard->decision($ticket, $ticketAction, $actor);
            if (! ($decision['allowed'] ?? false)) {
                throw new TicketRuleActionFailure(
                    'action_guard_denied',
                    'The current Ticket action policy denied this schema 2 rule action.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function ticketActions(string $type, array $input): array
    {
        $actions = match ($type) {
            TicketRuleActionProviderRegistry::ASSIGN_OWNER,
            TicketRuleActionProviderRegistry::UNASSIGN_OWNER,
            TicketRuleActionProviderRegistry::RERUN_ASSIGNMENT => [TicketAction::ASSIGN_OTHER],
            TicketRuleActionProviderRegistry::ADD_INTERNAL_NOTE => [TicketAction::ADD_INTERNAL_NOTE],
            default => [TicketAction::UPDATE_FIELDS],
        };

        if ($type === TicketRuleActionProviderRegistry::SET_TICKET_FIELDS
            && array_key_exists('sla_id', (array) ($input['fields'] ?? []))) {
            $actions[] = TicketAction::APPLY_SLA;
        }

        return array_values(array_unique($actions));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertTargets(Ticket $ticket, string $type, array $input): void
    {
        match ($type) {
            TicketRuleActionProviderRegistry::SET_TICKET_FIELDS => $this->assertFieldTargets(
                $ticket,
                (array) ($input['fields'] ?? []),
            ),
            TicketRuleActionProviderRegistry::SET_QUEUE => $this->assertActiveModel(
                TicketQueue::class,
                (int) $input['queue_id'],
                'is_active',
            ),
            TicketRuleActionProviderRegistry::ASSIGN_OWNER => $this->assertOwnerTarget(
                $ticket,
                (int) $input['owner_id'],
            ),
            TicketRuleActionProviderRegistry::ADD_TAGS,
            TicketRuleActionProviderRegistry::REMOVE_TAGS => $this->assertTagTargets(
                (array) $input['tag_ids'],
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function assertFieldTargets(Ticket $ticket, array $fields): void
    {
        if (($id = $fields['ticket_type_id'] ?? null) !== null) {
            $this->assertActiveModel(TicketType::class, (int) $id, 'is_active');
        }
        if (($id = $fields['priority_id'] ?? null) !== null) {
            $this->assertActiveModel(TicketPriority::class, (int) $id, 'is_active');
        }
        if (($id = $fields['sla_id'] ?? null) !== null
            && ! Sla::query()->whereKey((int) $id)->exists()) {
            $this->targetMissing();
        }
        if (($id = $fields['category_id'] ?? null) !== null
            && ! Category::query()->forTickets()->active()->whereKey((int) $id)->exists()) {
            $this->targetMissing();
        }

        $contextFields = ['client_id', 'site_id', 'contact_id', 'asset_id'];
        if (array_intersect(array_keys($fields), $contextFields) !== []) {
            $this->assertContextTargets($ticket, $fields);
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function assertContextTargets(Ticket $ticket, array $fields): void
    {
        $clientId = array_key_exists('client_id', $fields)
            ? $fields['client_id']
            : $ticket->client_id;
        $siteId = array_key_exists('site_id', $fields)
            ? $fields['site_id']
            : $ticket->site_id;
        $contactId = array_key_exists('contact_id', $fields)
            ? $fields['contact_id']
            : $ticket->contact_id;
        $assetId = array_key_exists('asset_id', $fields)
            ? $fields['asset_id']
            : $ticket->asset_id;
        $clientId = $clientId === null ? null : (int) $clientId;

        if ($clientId !== null) {
            $client = Client::query()->whereKey($clientId);
            if (array_key_exists('client_id', $fields)) {
                $client->where('active', true);
            }
            if (! $client->exists()) {
                $this->targetMissing();
            }
        }

        if ($siteId !== null
            && ($clientId === null
                || ! ClientSite::query()
                    ->whereKey((int) $siteId)
                    ->where('client_id', $clientId)
                    ->exists())) {
            $this->targetMissing();
        }

        if ($contactId !== null) {
            $contact = ClientUser::query()
                ->with('site')
                ->whereKey((int) $contactId)
                ->where('active', true)
                ->first();
            if (! $contact
                || $clientId === null
                || (int) $contact->site?->client_id !== $clientId) {
                $this->targetMissing();
            }
        }

        if ($assetId !== null) {
            $asset = Asset::query()->whereKey((int) $assetId)->first();
            if (! $asset
                || ($asset->client_id === null) !== ($clientId === null)
                || ($asset->client_id !== null && (int) $asset->client_id !== $clientId)) {
                $this->targetMissing();
            }
        }
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function assertActiveModel(string $model, int $id, string $activeColumn): void
    {
        if ($id < 1
            || ! $model::query()->whereKey($id)->where($activeColumn, true)->exists()) {
            $this->targetMissing();
        }
    }

    private function assertOwnerTarget(Ticket $ticket, int $ownerId): void
    {
        $owner = $ownerId > 0
            ? User::query()
                ->whereKey($ownerId)
                ->where('status', User::STATUS_ACTIVE)
                ->where('is_system_actor', false)
                ->first()
            : null;
        if (! $owner) {
            $this->targetMissing();
        }

        $policy = (array) data_get($this->workflow->currentState($ticket), 'assignment_policy', []);
        $eligibleIds = collect($policy['eligible_user_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique();
        $requiredPermissions = collect($policy['required_permissions'] ?? [])
            ->filter(fn (mixed $permission): bool => is_string($permission) && $permission !== '');

        if (($policy['strategy'] ?? null) === 'unassigned'
            || ($eligibleIds->isNotEmpty() && ! $eligibleIds->contains($ownerId))
            || ! $requiredPermissions->every(fn (string $permission): bool => $owner->can($permission))) {
            $this->targetMissing();
        }
    }

    /** @param list<int> $tagIds */
    private function assertTagTargets(array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        if ($tagIds === []
            || Tag::query()->whereIn('id', $tagIds)->where('active', true)->count() !== count($tagIds)) {
            $this->targetMissing();
        }
    }

    private function targetMissing(): never
    {
        throw new TicketRuleActionFailure(
            'target_missing',
            'A schema 2 Ticket Rule action target is unavailable or outside the Ticket context.',
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function execute(
        Ticket $ticket,
        string $type,
        array $input,
        User $actor,
        TicketRuleEventEnvelope $event,
        string $idempotencyKey,
    ): array {
        return match ($type) {
            TicketRuleActionProviderRegistry::SET_TICKET_FIELDS => $this->update(
                $ticket,
                (array) $input['fields'],
                $actor,
                $event,
                $type,
                $idempotencyKey,
            ),
            TicketRuleActionProviderRegistry::SET_QUEUE => $this->update(
                $ticket,
                ['queue_id' => (int) $input['queue_id']],
                $actor,
                $event,
                $type,
                $idempotencyKey,
            ),
            TicketRuleActionProviderRegistry::ASSIGN_OWNER => $this->assign(
                $ticket,
                (int) $input['owner_id'],
                $actor,
                $event,
                $type,
                $idempotencyKey,
            ),
            TicketRuleActionProviderRegistry::UNASSIGN_OWNER => $this->assign(
                $ticket,
                null,
                $actor,
                $event,
                $type,
                $idempotencyKey,
            ),
            TicketRuleActionProviderRegistry::RERUN_ASSIGNMENT => $this->rerunAssignment(
                $ticket,
                $event,
                $type,
                $idempotencyKey,
            ),
            TicketRuleActionProviderRegistry::ADD_TAGS => $this->tags(
                $ticket,
                (array) $input['tag_ids'],
                [],
                $actor,
                $event,
                $type,
                $idempotencyKey,
            ),
            TicketRuleActionProviderRegistry::REMOVE_TAGS => $this->tags(
                $ticket,
                [],
                (array) $input['tag_ids'],
                $actor,
                $event,
                $type,
                $idempotencyKey,
            ),
            TicketRuleActionProviderRegistry::ADD_INTERNAL_NOTE => $this->internalNote(
                $ticket,
                (string) $input['body'],
                $actor,
                $event,
                $type,
                $idempotencyKey,
            ),
            TicketRuleActionProviderRegistry::EMIT_SIGNAL => $this->signal(
                $ticket,
                $input,
                $event,
                true,
            ),
            default => throw new TicketRuleActionFailure(
                'unknown_action_type',
                'The schema 2 Ticket Rule action provider is unavailable.',
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function update(
        Ticket $ticket,
        array $fields,
        User $actor,
        TicketRuleEventEnvelope $event,
        string $type,
        string $idempotencyKey,
    ): array {
        $result = $this->updateFields->handleWithResult($ticket, $fields + [
            '_sla_source' => 'ticket_rule',
            '_suppress_ticket_rule_dispatch' => true,
            '_event_source_channel' => 'ticket_rule',
            '_event_source_action' => $this->sourceAction($type),
            '_delivery_key' => $idempotencyKey,
            '_correlation_uuid' => $event->correlationUuid,
            '_causation_uuid' => $event->correlationUuid,
        ], $actor);
        $ticket->refresh();

        return $this->mutationResult($result);
    }

    private function assign(
        Ticket $ticket,
        ?int $ownerId,
        User $actor,
        TicketRuleEventEnvelope $event,
        string $type,
        string $idempotencyKey,
    ): array {
        $result = $this->assignOwner->handle($ticket, $ownerId, $actor, [
            '_suppress_ticket_rule_dispatch' => true,
            'source_channel' => 'ticket_rule',
            'source_action' => $this->sourceAction($type),
            'delivery_identity' => $idempotencyKey,
            'correlation_uuid' => $event->correlationUuid,
            'causation_uuid' => $event->correlationUuid,
        ]);
        $ticket->refresh();

        return $this->mutationResult($result);
    }

    /**
     * @param  list<int>  $add
     * @param  list<int>  $remove
     */
    private function tags(
        Ticket $ticket,
        array $add,
        array $remove,
        User $actor,
        TicketRuleEventEnvelope $event,
        string $type,
        string $idempotencyKey,
    ): array {
        $result = $this->mutateTags->handle($ticket, $add, $remove, $actor, [
            '_suppress_ticket_rule_dispatch' => true,
            'source_channel' => 'ticket_rule',
            'source_action' => $this->sourceAction($type),
            'delivery_identity' => $idempotencyKey,
            'correlation_uuid' => $event->correlationUuid,
            'causation_uuid' => $event->correlationUuid,
        ]);
        $ticket->refresh();

        return $this->mutationResult($result);
    }

    private function rerunAssignment(
        Ticket $ticket,
        TicketRuleEventEnvelope $event,
        string $type,
        string $idempotencyKey,
    ): array {
        $ticket->refresh();
        $before = [
            'queue_id' => $ticket->queue_id === null ? null : (int) $ticket->queue_id,
            'owner_id' => $ticket->owner_id === null ? null : (int) $ticket->owner_id,
        ];
        $this->assignmentEngine->assign($ticket, true);
        $ticket->refresh();
        $after = [
            'queue_id' => $ticket->queue_id === null ? null : (int) $ticket->queue_id,
            'owner_id' => $ticket->owner_id === null ? null : (int) $ticket->owner_id,
        ];
        $changedFields = collect(array_keys($after))
            ->filter(fn (string $field): bool => $before[$field] !== $after[$field])
            ->values()
            ->all();
        if ($changedFields === []) {
            return $this->noChange();
        }

        $before = array_intersect_key($before, array_flip($changedFields));
        $after = array_intersect_key($after, array_flip($changedFields));
        $assignmentChanges = $this->assignmentChanges($before, $after);
        $mutation = TicketRuleMutationEvent::make(
            ticketId: (int) $ticket->id,
            eventKey: TicketRuleTriggerRegistry::UPDATED,
            changedFields: $changedFields,
            before: $before,
            after: $after,
            safeFacts: [
                'queue_id' => $ticket->queue_id,
                'owner_id' => $ticket->owner_id,
                'assignment_changes' => $assignmentChanges,
                'event_source_channel' => 'ticket_rule',
                'event_source_action' => $this->sourceAction($type),
            ],
            classification: [
                'assignment_changes' => $assignmentChanges,
                'assignment_concept' => 'queue_and_individual_owner',
            ],
            sourceChannel: 'ticket_rule',
            sourceAction: $this->sourceAction($type),
            deliveryIdentity: $idempotencyKey,
            correlationUuid: $event->correlationUuid,
            causationUuid: $event->correlationUuid,
        );

        return $this->succeeded($mutation);
    }

    private function internalNote(
        Ticket $ticket,
        string $body,
        User $actor,
        TicketRuleEventEnvelope $event,
        string $type,
        string $idempotencyKey,
    ): array {
        $message = $this->addMessage->handle($ticket, [
            'type' => 'internal_note',
            'visibility' => 'internal',
            'body' => $body,
            'suppress_notifications' => true,
            'suppress_workflow_trigger' => true,
            '_suppress_ticket_rule_dispatch' => true,
            '_event_source_channel' => 'ticket_rule',
            '_event_source_action' => $this->sourceAction($type),
            '_delivery_key' => $idempotencyKey,
            '_correlation_uuid' => $event->correlationUuid,
            '_causation_uuid' => $event->correlationUuid,
            'idempotency_key' => hash('sha256', $idempotencyKey.'|internal_note'),
            'idempotency_fingerprint' => hash('sha256', $body),
        ], $actor);
        $ticket->refresh();
        $bodyLength = mb_strlen((string) $message->body);
        $bodySha = hash('sha256', (string) $message->body);
        $mutation = TicketRuleMutationEvent::make(
            ticketId: (int) $ticket->id,
            eventKey: TicketRuleTriggerRegistry::MESSAGE_ADDED,
            changedFields: ['message_id'],
            before: [],
            after: [
                'message_id' => (int) $message->id,
                'message_type' => 'internal_note',
                'message_visibility' => 'internal',
                'attachments_count' => 0,
            ],
            safeFacts: [
                'message_id' => (int) $message->id,
                'message_type' => 'internal_note',
                'message_visibility' => 'internal',
                'attachments_count' => 0,
                'message_body_length' => $bodyLength,
                'message_body_sha256' => $bodySha,
                'message_subject_length' => 0,
                'message_subject_sha256' => hash('sha256', ''),
                'event_source_channel' => 'ticket_rule',
                'event_source_action' => $this->sourceAction($type),
            ],
            classification: [
                'message_type' => 'internal_note',
                'message_visibility' => 'internal',
            ],
            sourceChannel: 'ticket_rule',
            sourceAction: $this->sourceAction($type),
            deliveryIdentity: $idempotencyKey,
            relatedRecordType: TicketMessage::class,
            relatedRecordId: (int) $message->id,
            correlationUuid: $event->correlationUuid,
            causationUuid: $event->correlationUuid,
        );

        return $this->succeeded($mutation);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function signal(
        Ticket $ticket,
        array $input,
        TicketRuleEventEnvelope $event,
        bool $apply,
    ): array {
        if ($ticket->channel === 'signal' || $event->sourceChannel === 'signal') {
            return $this->noChange('signal_source_loop_suppressed');
        }

        return [
            'status' => $apply ? 'queued' : 'planned',
            'changes' => [],
            'after_commit' => [
                'type' => 'emit_signal',
                'signal_type' => $input['signal_type'],
                'severity' => $input['severity'],
                'confidence' => $input['confidence'],
                'summary' => $input['summary'],
                'payload_note' => $input['payload_note'],
            ],
            'reason_code' => null,
            'derived_events' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function project(
        Ticket $ticket,
        string $type,
        array $input,
        TicketRuleEventEnvelope $event,
    ): array {
        return match ($type) {
            TicketRuleActionProviderRegistry::SET_TICKET_FIELDS => $this->projectFields(
                $ticket,
                (array) $input['fields'],
            ),
            TicketRuleActionProviderRegistry::SET_QUEUE => $this->projectFields(
                $ticket,
                ['queue_id' => (int) $input['queue_id']],
            ),
            TicketRuleActionProviderRegistry::ASSIGN_OWNER => $this->projectFields(
                $ticket,
                ['owner_id' => (int) $input['owner_id']],
            ),
            TicketRuleActionProviderRegistry::UNASSIGN_OWNER => $this->projectFields(
                $ticket,
                ['owner_id' => null],
            ),
            TicketRuleActionProviderRegistry::RERUN_ASSIGNMENT => $this->projectFields(
                $ticket,
                ['owner_id' => $this->assignmentEngine->plan($ticket, true)],
            ),
            TicketRuleActionProviderRegistry::ADD_TAGS => $this->projectTags(
                $ticket,
                (array) $input['tag_ids'],
                [],
            ),
            TicketRuleActionProviderRegistry::REMOVE_TAGS => $this->projectTags(
                $ticket,
                [],
                (array) $input['tag_ids'],
            ),
            TicketRuleActionProviderRegistry::ADD_INTERNAL_NOTE => [
                'status' => 'planned',
                'changes' => [
                    'message_id' => [
                        'before' => null,
                        'after' => ['type' => 'planned_identifier'],
                    ],
                ],
                'after_commit' => null,
                'reason_code' => null,
                'derived_events' => [],
            ],
            TicketRuleActionProviderRegistry::EMIT_SIGNAL => $this->signal(
                $ticket,
                $input,
                $event,
                false,
            ),
            default => [
                'status' => 'planned',
                'changes' => [],
                'after_commit' => null,
                'reason_code' => null,
                'derived_events' => [],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function projectFields(Ticket $ticket, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field => $after) {
            $before = $ticket->{$field};
            if ((string) $before === (string) $after) {
                continue;
            }
            $changes[$field] = [
                'before' => $this->sanitizer->value($field, $before),
                'after' => $this->sanitizer->value($field, $after),
            ];
        }

        return [
            'status' => $changes === [] ? 'no_change' : 'planned',
            'changes' => $changes,
            'after_commit' => null,
            'reason_code' => null,
            'derived_events' => [],
        ];
    }

    /**
     * @param  list<int>  $add
     * @param  list<int>  $remove
     * @return array<string, mixed>
     */
    private function projectTags(Ticket $ticket, array $add, array $remove): array
    {
        $before = $ticket->tags()
            ->pluck('tags.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $after = array_values(array_unique(array_merge(array_diff($before, $remove), $add)));
        sort($after);

        return [
            'status' => $before === $after ? 'no_change' : 'planned',
            'changes' => $before === $after ? [] : [
                'tag_ids' => ['before' => $before, 'after' => $after],
            ],
            'after_commit' => null,
            'reason_code' => null,
            'derived_events' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mutationResult(TicketMutationResult $result): array
    {
        return $result->event ? $this->succeeded($result->event) : $this->noChange();
    }

    /**
     * @return array<string, mixed>
     */
    private function succeeded(TicketRuleMutationEvent $event): array
    {
        $changes = [];
        foreach ($event->changedFields as $field) {
            $changes[$field] = [
                'before' => $this->sanitizer->value($field, $event->before[$field] ?? null),
                'after' => $this->sanitizer->value($field, $event->after[$field] ?? null),
            ];
        }

        return [
            'status' => 'succeeded',
            'changes' => $changes,
            'after_commit' => null,
            'reason_code' => null,
            'derived_events' => [$event],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function noChange(?string $reasonCode = null): array
    {
        return [
            'status' => 'no_change',
            'changes' => [],
            'after_commit' => null,
            'reason_code' => $reasonCode,
            'derived_events' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $provider
     * @param  list<string>  $ticketActions
     * @return array<string, mixed>
     */
    private function authorized(
        array $result,
        array $provider,
        string $permission,
        array $ticketActions,
        bool $assignmentDecision,
        bool $slaDecision,
    ): array {
        $result['authorization'] = [
            'permission' => $permission,
            'ticket_action' => $ticketActions[0],
            'ticket_actions' => $ticketActions,
            'execution_phase' => $provider['execution_phase'],
            'capability' => $provider['capability_key'],
            'targets_revalidated' => true,
            'allowed' => true,
        ];
        $result['derived_events'] = array_values((array) ($result['derived_events'] ?? []));
        $result['assignment_decision'] = $assignmentDecision;
        $result['sla_decision'] = $slaDecision;

        return $result;
    }

    /** @param list<mixed> $events */
    private function eventChangesAssignment(array $events): bool
    {
        foreach ($events as $event) {
            if ($event instanceof TicketRuleMutationEvent
                && array_intersect($event->changedFields, ['queue_id', 'owner_id']) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function assignmentChanges(array $before, array $after): array
    {
        $changes = [];
        if (array_key_exists('queue_id', $after)
            && ($before['queue_id'] ?? null) !== $after['queue_id']) {
            $changes[] = 'queue_changed';
        }
        if (! array_key_exists('owner_id', $after)
            || ($before['owner_id'] ?? null) === $after['owner_id']) {
            return $changes;
        }

        if (($before['owner_id'] ?? null) === null) {
            $changes[] = 'owner_assigned';
        } elseif ($after['owner_id'] === null) {
            $changes[] = 'owner_unassigned';
        } else {
            $changes[] = 'owner_changed';
        }

        return $changes;
    }

    private function isWorkflowAction(string $type): bool
    {
        return in_array($type, [
            TicketRuleActionProviderRegistry::SELECT_WORKFLOW,
            TicketRuleActionProviderRegistry::TRANSITION_WORKFLOW,
            TicketRuleActionProviderRegistry::SWITCH_WORKFLOW,
            TicketRuleActionProviderRegistry::PAUSE_WORKFLOW_AUTOMATION,
            TicketRuleActionProviderRegistry::RESUME_WORKFLOW_AUTOMATION,
        ], true);
    }

    private function isCustomFieldAction(string $type): bool
    {
        return in_array($type, [
            TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
            TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD,
        ], true);
    }

    private function sourceAction(string $type): string
    {
        return 'TicketRuleSchema2ActionExecutor.'.$type;
    }
}
