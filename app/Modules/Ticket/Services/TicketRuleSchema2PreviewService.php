<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Support\TicketRuleActionFailure;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleEventEnvelope;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class TicketRuleSchema2PreviewService
{
    public function __construct(
        private readonly TicketRulePublishedDefinitionValidator $validator,
        private readonly TicketRuleSchema2ConditionEvaluator $conditions,
        private readonly TicketRuleSchema2ActionExecutor $actions,
        private readonly TicketRuleRuntimeGate $runtimeGate,
        private readonly TicketRuleFrozenPublishedSet $frozenPublishedSet,
        private readonly TicketRuleAuditSanitizer $sanitizer,
        private readonly TicketRulePublicationTargetValidator $targets,
        private readonly TicketRuleTriggerRegistry $triggers,
    ) {}

    /**
     * Preview one unsaved definition through the same typed evaluators and
     * action executors as runtime, always with apply=false.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function draft(
        Ticket $requested,
        array $definition,
        User $requestedOperator,
        array $syntheticContext = [],
    ): array {
        if (! (bool) config('ticket_rules.v2_enabled', false)) {
            throw new RuntimeException('Ticket Rule v2 capability is disabled.');
        }

        return DB::transaction(function () use ($requested, $definition, $requestedOperator, $syntheticContext): array {
            $operator = $this->operator($requestedOperator);
            $ticket = $this->ticket($requested);
            $actor = $this->runtimeGate->requireExistingActor();
            $validation = $this->validator->validateStored($definition);

            if (($validation['status'] ?? null) !== TicketRulePublishedDefinitionValidator::STATUS_VALID
                || (int) ($validation['schema_version'] ?? 0)
                    !== TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION) {
                throw new RuntimeException((string) ($validation['message'] ?? 'The draft definition is invalid.'));
            }

            $definition = $validation['definition'];
            $this->targets->validate($definition);
            $this->targets->validateCustomFieldAccess($definition, $operator);
            $fence = TicketRuleAuthorityFence::query()
                ->whereKey(TicketRuleAuthorityFence::SCOPE)
                ->sharedLock()
                ->firstOrFail();
            $frozen = $this->frozenPublishedSet->capture();
            $envelope = $this->envelope($ticket, $definition, $syntheticContext, $operator, $actor);
            $shadow = clone $ticket;
            $facts = array_merge($envelope->facts, [
                'tag_ids' => $ticket->tags()->pluck('tags.id')->map(fn ($id): int => (int) $id)->all(),
            ]);
            $evaluation = $this->conditions->evaluate($definition, $facts);

            if (! ($evaluation['valid'] ?? false)) {
                throw new RuntimeException((string) ($evaluation['reason_code'] ?? 'The draft conditions are invalid.'));
            }

            $matched = (bool) $evaluation['passed'];
            $branch = $matched ? 'then' : 'else';
            $branchActions = (array) ($definition[$matched ? 'then_actions' : 'else_actions'] ?? []);
            $results = [];
            $failed = false;

            foreach ($branchActions as $position => $action) {
                $action = is_array($action) ? $action : [];
                if ($failed) {
                    $results[] = [
                        'position' => (int) $position,
                        'status' => 'not_run',
                        'action' => $this->actions->snapshot($action),
                        'changes' => [],
                        'reason_code' => null,
                    ];

                    continue;
                }

                try {
                    $result = $this->actions->handle(
                        $shadow,
                        $action,
                        $actor,
                        $envelope,
                        false,
                        TicketRuleStableJson::checksum([
                            'draft_checksum' => $validation['checksum'],
                            'ticket_id' => (int) $ticket->id,
                            'branch' => $branch,
                            'position' => (int) $position,
                        ]),
                    );
                    $results[] = [
                        'position' => (int) $position,
                        'status' => (string) ($result['status'] ?? 'failed'),
                        'action' => $this->actions->snapshot($action),
                        'changes' => (array) ($result['changes'] ?? []),
                        'authorization' => $result['authorization'] ?? null,
                        'after_commit_type' => data_get($result, 'after_commit.type'),
                        'reason_code' => $result['reason_code'] ?? null,
                    ];
                    $this->applyProjection($shadow, (array) ($result['changes'] ?? []));
                    $failed = ($result['status'] ?? null) === 'failed';
                } catch (TicketRuleActionFailure $failure) {
                    $failed = true;
                    $results[] = [
                        'position' => (int) $position,
                        'status' => 'failed',
                        'action' => $this->actions->snapshot($action),
                        'changes' => [],
                        'reason_code' => $failure->reasonCode,
                    ];
                } catch (Throwable $failure) {
                    $failed = true;
                    $results[] = [
                        'position' => (int) $position,
                        'status' => 'failed',
                        'action' => $this->actions->snapshot($action),
                        'changes' => [],
                        'reason_code' => 'unexpected_action_failure',
                        'failure_fingerprint' => $this->sanitizer->message($failure->getMessage()),
                    ];
                }
            }

            $changed = collect($results)->contains(
                fn (array $result): bool => in_array($result['status'], ['succeeded', 'planned', 'queued'], true)
            );

            return [
                'mode' => 'draft_preview',
                'ticket_id' => (int) $ticket->id,
                'work_context_id' => (int) $ticket->work_context_id,
                'definition_checksum' => $validation['checksum'],
                'authority_generation' => (int) $fence->catalog_generation,
                'authority_checksum' => (string) $fence->catalog_checksum,
                'published_set_checksum' => $frozen['checksum'],
                'published_version_ids' => $frozen['version_ids'],
                'trigger' => $definition['trigger'],
                'trigger_filters' => $definition['trigger_filters'],
                'conditions_matched' => $matched,
                'condition_evidence' => $evaluation,
                'selected_branch' => $branch,
                'actions' => $results,
                'terminal_status' => $failed ? 'failed' : ($changed ? 'would_change' : 'no_change'),
                'stop_requested' => (bool) data_get($definition, 'flow.stop_processing', false),
            ];
        }, 3);
    }

    private function operator(User $requested): User
    {
        $operator = User::query()->whereKey((int) $requested->getKey())->first();
        if (! $operator
            || ! $operator->isActive()
            || ! $operator->can('ticket.view')
            || ! $operator->can('ticket.rule_preview')) {
            throw new RuntimeException('Ticket view and Ticket Rule preview permissions are required.');
        }

        return $operator;
    }

    private function ticket(Ticket $requested): Ticket
    {
        $ticket = Ticket::query()
            ->whereKey((int) $requested->getKey())
            ->where('work_context_id', (int) $requested->work_context_id)
            ->whereHas('workContext', fn ($query) => $query->whereKey((int) $requested->work_context_id))
            ->sharedLock()
            ->first();

        if (! $ticket) {
            throw new RuntimeException('The selected Ticket is unavailable in the authorized Work Context.');
        }

        return $ticket;
    }

    /**
     * Build a registry-valid root event from explicit typed preview controls.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $context
     */
    private function envelope(
        Ticket $ticket,
        array $definition,
        array $context,
        User $operator,
        User $actor,
    ): TicketRuleEventEnvelope {
        $trigger = (string) $definition['trigger'];
        if ($trigger === TicketRuleTriggerRegistry::CREATED) {
            return TicketRuleEventEnvelope::created($ticket, [
                'channel' => (string) $ticket->channel,
                '_source_action' => 'TicketRuleDraftPreview',
            ], $operator, $actor);
        }

        $event = $this->syntheticMutation($ticket, $trigger, $context, $definition);
        $envelope = TicketRuleEventEnvelope::mutation($ticket, $event, $operator, $actor);
        $filters = $this->triggers->canonicalizeFilters(
            $trigger,
            $definition['trigger_filters'] ?? [],
        );
        if (! ($filters['valid'] ?? false)) {
            throw new RuntimeException('The trigger filters are invalid.');
        }
        if (! $this->triggers->isRelevant($trigger, $filters['filters'], [
            'event_key' => $envelope->eventKey,
            'source_channel' => $envelope->sourceChannel,
            'changed_fields' => $envelope->changedFields,
            'before' => $envelope->before,
            'after' => $envelope->after,
            'facts' => $envelope->facts,
        ])) {
            throw new RuntimeException(
                'The synthetic preview event does not match the selected trigger filters.'
            );
        }

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $definition
     */
    private function syntheticMutation(
        Ticket $ticket,
        string $trigger,
        array $context,
        array $definition,
    ): TicketRuleMutationEvent {
        $changed = [];
        $before = [];
        $after = [];
        $facts = [];
        $classification = [];
        $sourceChannel = 'system';

        if (in_array($trigger, [
            TicketRuleTriggerRegistry::UPDATED,
            TicketRuleTriggerRegistry::FIELD_CHANGED,
        ], true)) {
            $changed = collect((array) ($context['changed_fields'] ?? []))
                ->filter(fn (mixed $field): bool => is_string($field)
                    && collect(app(\App\Modules\Ticket\Support\TicketRuleFieldRegistry::class)
                        ->standardFields())->has($field))
                ->unique()
                ->values()
                ->all();
            if ($changed === []) {
                throw new RuntimeException('Choose at least one changed Ticket field for the preview.');
            }
            foreach ($changed as $field) {
                $before[$field] = $ticket->getAttribute($field);
                $after[$field] = $ticket->getAttribute($field);
            }
        } elseif ($trigger === TicketRuleTriggerRegistry::MESSAGE_ADDED) {
            $messageType = $this->requiredEnum(
                $context['message_type'] ?? null,
                ['customer_reply', 'public_update', 'internal_note'],
                'Choose a message type for the preview.',
            );
            $sourceChannel = $this->requiredEnum(
                $context['source_channel'] ?? null,
                ['tech', 'customer_portal', 'email', 'api', 'intake', 'relationship',
                    'telephony', 'signal', 'scheduled', 'integration', 'system', 'ticket_rule'],
                'Choose a message source for the preview.',
            );
            $changed = ['message_id'];
            $after = ['message_id' => 1];
            $facts = [
                'message_id' => 1,
                'message_type' => $messageType,
                'message_visibility' => $messageType === 'internal_note' ? 'internal' : 'public',
            ];
            $classification = $facts;
        } elseif ($trigger === TicketRuleTriggerRegistry::TAGS_CHANGED) {
            $added = $this->positiveIntegerList($context['added_tag_ids'] ?? []);
            $removed = $this->positiveIntegerList($context['removed_tag_ids'] ?? []);
            if ($added === [] && $removed === []) {
                throw new RuntimeException('Choose at least one added or removed tag for the preview.');
            }
            if (array_intersect($added, $removed) !== []) {
                throw new RuntimeException('A preview tag cannot be both added and removed.');
            }
            $current = $ticket->tags()->pluck('tags.id')->map(fn (mixed $id): int => (int) $id)->all();
            $next = array_values(array_unique(array_diff(array_merge($current, $added), $removed)));
            sort($current);
            sort($next);
            $changed = ['tag_ids'];
            $before = ['tag_ids' => $current];
            $after = ['tag_ids' => $next];
            $facts = ['tag_ids' => $next, 'added_tag_ids' => $added, 'removed_tag_ids' => $removed];
        } elseif ($trigger === TicketRuleTriggerRegistry::ASSIGNMENT_CHANGED) {
            $change = $this->requiredEnum(
                $context['assignment_change'] ?? null,
                ['queue_changed', 'owner_assigned', 'owner_changed', 'owner_unassigned'],
                'Choose an assignment change for the preview.',
            );
            $field = $change === 'queue_changed' ? 'queue_id' : 'owner_id';
            $next = $change === 'owner_unassigned'
                ? null
                : $this->positiveInteger(
                    $context[$field] ?? null,
                    'Choose the resulting Queue or Owner for the preview.',
                );
            if ($ticket->getAttribute($field) === $next) {
                throw new RuntimeException('Choose a resulting assignment that differs from the Ticket.');
            }
            $changed = [$field];
            $before = [$field => $ticket->getAttribute($field)];
            $after = [$field => $next];
            $facts = ['assignment_change' => $change, 'assignment_changes' => [$change], $field => $next];
        } elseif ($trigger === TicketRuleTriggerRegistry::STATUS_CHANGED) {
            $next = $this->positiveInteger(
                $context['status_id'] ?? null,
                'Choose a resulting status for the preview.',
            );
            if ((int) $ticket->status_id === $next) {
                throw new RuntimeException('Choose a resulting status that differs from the Ticket.');
            }
            $changed = ['status_id'];
            $before = ['status_id' => $ticket->status_id];
            $after = ['status_id' => $next];
            $facts = ['status_id' => $next];
        } elseif ($trigger === TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED) {
            $definitionId = $this->positiveInteger(
                $context['definition_id'] ?? null,
                'Choose a Custom Field for the preview event.',
            );
            $direction = $this->requiredEnum(
                $context['direction'] ?? null,
                ['set', 'changed', 'cleared'],
                'Choose a Custom Field change direction for the preview.',
            );
            $beforeValue = $context['before_value'] ?? null;
            $afterValue = $context['after_value'] ?? null;
            if ($direction === 'set' && $afterValue === null) {
                throw new RuntimeException('Enter the resulting Custom Field value for the preview.');
            }
            if ($direction === 'cleared') {
                $afterValue = null;
            }
            if ($direction === 'changed' && $beforeValue === $afterValue) {
                throw new RuntimeException('Use different before and after Custom Field values.');
            }
            $changed = ['custom_field_'.$definitionId];
            $facts = [
                'changed_custom_field_definition_ids' => [$definitionId],
                'custom_field_change_directions' => [(string) $definitionId => $direction],
                'custom_fields' => [(string) $definitionId => [
                    'before' => $beforeValue,
                    'after' => $afterValue,
                    'changed' => true,
                ]],
            ];
            $classification = [
                'changed_custom_field_definition_ids' => [$definitionId],
                'custom_field_change_directions' => [(string) $definitionId => $direction],
            ];
        } elseif ($trigger === TicketRuleTriggerRegistry::WORKFLOW_CHANGED) {
            $versionId = $this->positiveInteger(
                $context['workflow_version_id'] ?? null,
                'Choose a Workflow version for the preview.',
            );
            $operation = $this->requiredEnum(
                $context['workflow_operation'] ?? null,
                ['select', 'transition', 'switch', 'pause', 'resume'],
                'Choose a Workflow operation for the preview.',
            );
            $changed = ['workflow_version_id'];
            $before = ['workflow_version_id' => $ticket->workflow_version_id];
            $after = ['workflow_version_id' => $versionId];
            $facts = ['workflow_version_id' => $versionId, 'workflow_operation' => $operation];
        } elseif ($trigger === TicketRuleTriggerRegistry::WORKFLOW_STATE_CHANGED) {
            $versionId = $this->positiveInteger(
                $context['workflow_version_id'] ?? null,
                'Choose a Workflow version for the preview.',
            );
            $stateKey = $this->requiredIdentifier(
                $context['workflow_state_key'] ?? null,
                'Choose a resulting Workflow state for the preview.',
            );
            if ((string) $ticket->workflow_state_key === $stateKey) {
                throw new RuntimeException('Choose a Workflow state that differs from the Ticket.');
            }
            $changed = ['workflow_state_key'];
            $before = ['workflow_state_key' => $ticket->workflow_state_key];
            $after = ['workflow_state_key' => $stateKey];
            $facts = ['workflow_version_id' => $versionId, 'workflow_state_key' => $stateKey];
        } else {
            throw new RuntimeException('The selected trigger cannot be previewed.');
        }

        return TicketRuleMutationEvent::make(
            ticketId: (int) $ticket->id,
            eventKey: $trigger,
            changedFields: $changed,
            before: $before,
            after: $after,
            safeFacts: $facts,
            classification: $classification,
            sourceChannel: $sourceChannel,
            sourceAction: 'TicketRuleDraftPreview',
            deliveryIdentity: TicketRuleStableJson::checksum([
                'ticket_id' => (int) $ticket->id,
                'definition_checksum' => TicketRuleStableJson::checksum($definition),
                'trigger' => $trigger,
                'context' => $context,
            ]),
        );
    }

    /** @return list<int> */
    private function positiveIntegerList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            if ((is_int($id) && $id > 0)
                || (is_string($id) && preg_match('/\A[1-9][0-9]*\z/', $id) === 1)) {
                $ids[] = (int) $id;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function positiveInteger(mixed $value, string $message): int
    {
        if ((! is_int($value) && (! is_string($value)
                || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1))
            || (int) $value < 1) {
            throw new RuntimeException($message);
        }

        return (int) $value;
    }

    /** @param list<string> $allowed */
    private function requiredEnum(mixed $value, array $allowed, string $message): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    private function requiredIdentifier(mixed $value, string $message): string
    {
        if (! is_string($value)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,189}\z/', $value) !== 1) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    /** @param array<string, mixed> $changes */
    private function applyProjection(Ticket $ticket, array $changes): void
    {
        foreach ($changes as $field => $change) {
            if ($field !== 'tag_ids' && is_array($change) && array_key_exists('after', $change)) {
                $ticket->setAttribute((string) $field, $change['after']);
            }
        }
    }
}
