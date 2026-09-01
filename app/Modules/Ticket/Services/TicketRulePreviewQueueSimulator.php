<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleEvent;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Support\TicketRuleActionFailure;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleEventEnvelope;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Support\Collection;
use Throwable;

/**
 * No-write queue simulation for mixed compatibility/schema-2 published sets.
 *
 * Every action uses its runtime executor with apply=false. Planned schema-2
 * mutations advance only an in-memory Ticket shadow and enqueue the same
 * normalized downstream event family as the authoritative coordinator.
 */
final class TicketRulePreviewQueueSimulator
{
    private const MAX_PUBLIC_ROWS = 200;

    public function __construct(
        private readonly TicketRulePublishedDefinitionValidator $validator,
        private readonly TicketRuleConditionEvaluator $compatibilityConditions,
        private readonly TicketRuleSchema2ConditionEvaluator $schema2Conditions,
        private readonly TicketRuleCompatibilityActionExecutor $compatibilityActions,
        private readonly TicketRuleSchema2ActionExecutor $schema2Actions,
        private readonly TicketRuleTriggerRegistry $triggers,
        private readonly TicketRuleAuditSanitizer $sanitizer,
        private readonly TicketCustomFieldTargetValidator $customFieldTargets,
        private readonly TicketCustomFieldValueResolver $customFieldValues,
    ) {}

    /**
     * @param  array<string, mixed>  $frozen
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function simulate(
        Ticket $ticket,
        array $context,
        User $operator,
        User $actor,
        TicketRuleAuthorityFence $fence,
        array $frozen,
    ): array {
        /** @var Collection<int, TicketRuleVersion> $versions */
        $versions = $frozen['versions'];
        $limits = [
            'max_depth' => min(32, max(1, (int) config('ticket_rules.limits.max_depth', 8))),
            'max_evaluated_rules' => min(500, max(1, (int) config('ticket_rules.limits.max_evaluated_rules', 100))),
            'max_actions' => min(500, max(1, (int) config('ticket_rules.limits.max_actions', 100))),
        ];
        $root = TicketRuleEventEnvelope::created($ticket, $context, $operator, $actor);
        $queue = [[
            'envelope' => $root,
            'sequence' => 1,
            'custom_fields' => [],
        ]];
        $visited = [$root->fingerprint => true];
        $shadow = clone $ticket;
        $tagIds = $ticket->tags()
            ->pluck('tags.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $customFieldOverlay = [];
        $counters = [
            'events' => 1,
            'evaluated_rules' => 0,
            'actions' => 0,
            'loop_blocks' => 0,
            'failed_executions' => 0,
        ];
        $rows = [];
        $events = [];
        $collisions = [];
        $nextSequence = 2;
        $haltReasonCode = null;
        $haltBlockedEventFingerprint = null;
        $writers = [];
        $hadEffect = false;
        $hadFailure = false;
        $halted = false;
        $stopped = false;

        while ($queue !== [] && ! $halted) {
            $queued = array_shift($queue);
            /** @var TicketRuleEventEnvelope $envelope */
            $envelope = $queued['envelope'];
            $sequence = (int) $queued['sequence'];
            $eventFacts = $this->facts(
                $envelope,
                (array) ($queued['custom_fields'] ?? []),
            );
            $eventStopped = false;
            $eventIndex = count($events);
            $events[] = [
                'sequence' => $sequence,
                'event_key' => $envelope->eventKey,
                'chain_depth' => $envelope->chainDepth,
                'event_fingerprint' => $envelope->fingerprint,
                'status' => 'processing',
            ];

            foreach ($versions as $order => $version) {
                if ($eventStopped || $halted) {
                    break;
                }

                $definition = is_array($version->definition_json) ? $version->definition_json : [];
                if (! $this->relevant($version, $definition, $envelope)) {
                    continue;
                }

                if ($counters['evaluated_rules'] >= $limits['max_evaluated_rules']) {
                    $counters['loop_blocks']++;
                    $halted = true;
                    $haltReasonCode = TicketRuleEvent::LOOP_REASON_EVALUATED_RULE_BUDGET_EXCEEDED;
                    $haltBlockedEventFingerprint = null;
                    $events[$eventIndex]['status'] = 'loop_blocked';
                    $events[$eventIndex]['loop_reason_code'] = $haltReasonCode;
                    $events[$eventIndex]['blocked_event_fingerprint'] = null;
                    $rows[] = $this->row(
                        $version,
                        $sequence,
                        $envelope->eventKey,
                        (int) $order + 1,
                        [
                            'status' => 'loop_blocked',
                            'reason_code' => $haltReasonCode,
                        ],
                    );
                    break;
                }
                $counters['evaluated_rules']++;

                $validation = $this->validator->validateStored($definition);
                if (($validation['status'] ?? null) !== TicketRulePublishedDefinitionValidator::STATUS_VALID
                    || (int) $version->definition_schema_version !== (int) ($validation['schema_version'] ?? 0)
                    || ($validation['checksum'] ?? null) !== $version->definition_checksum) {
                    $hadFailure = true;
                    $counters['failed_executions']++;
                    $rows[] = $this->row($version, $sequence, $envelope->eventKey, (int) $order + 1, [
                        'status' => 'failed',
                        'reason_code' => 'invalid_published_definition',
                    ]);

                    continue;
                }

                $definition = $validation['definition'];
                $schema2 = (int) $version->definition_schema_version
                    === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION;
                $facts = $eventFacts;
                $evaluation = $schema2
                    ? $this->schema2Conditions->evaluate($definition, $facts)
                    : $this->compatibilityConditions->evaluate($definition, $facts);

                if (! ($evaluation['valid'] ?? false)) {
                    $hadFailure = true;
                    $counters['failed_executions']++;
                    $rows[] = $this->row($version, $sequence, $envelope->eventKey, (int) $order + 1, [
                        'status' => 'failed',
                        'reason_code' => $evaluation['reason_code'] ?? 'invalid_runtime_definition',
                        'condition_evidence' => $evaluation,
                    ]);

                    continue;
                }

                $matched = (bool) $evaluation['passed'];
                $branch = $matched ? 'then' : 'else';
                $branchActions = (array) ($definition[$matched ? 'then_actions' : 'else_actions'] ?? []);
                $entry = $this->row($version, $sequence, $envelope->eventKey, (int) $order + 1, [
                    'status' => 'no_change',
                    'conditions_matched' => $matched,
                    'selected_branch' => $branch,
                    'condition_evidence' => $evaluation,
                    'actions' => [],
                ]);

                if (! $matched && $branchActions === []) {
                    $rows[] = $entry;

                    continue;
                }

                $branchShadow = clone $shadow;
                $branchTags = $tagIds;
                $pendingEvents = [];
                $branchCustomFields = $customFieldOverlay;
                $branchWriters = $writers;
                $branchCollisions = $collisions;
                $branchFailed = false;
                $branchFailureCode = null;
                $branchEffect = false;

                foreach ($branchActions as $position => $action) {
                    $action = is_array($action) ? $action : [];
                    if ($branchFailed) {
                        $entry['actions'][] = [
                            'position' => (int) $position,
                            'status' => 'not_run',
                            'action' => $this->snapshot($action, $schema2),
                            'changes' => [],
                        ];

                        continue;
                    }
                    if ($counters['actions'] >= $limits['max_actions']) {
                        $branchFailed = true;
                        $branchFailureCode = TicketRuleEvent::LOOP_REASON_ACTION_BUDGET_EXCEEDED;
                        $entry['actions'][] = [
                            'position' => (int) $position,
                            'status' => 'failed',
                            'action' => $this->snapshot($action, $schema2),
                            'changes' => [],
                            'reason_code' => TicketRuleEvent::LOOP_REASON_ACTION_BUDGET_EXCEEDED,
                        ];

                        continue;
                    }

                    $counters['actions']++;
                    try {
                        $result = $schema2
                            ? $this->schema2Actions->handle(
                                $branchShadow,
                                $action,
                                $actor,
                                $envelope,
                                false,
                                TicketRuleStableJson::checksum([
                                    'preview_event' => $envelope->fingerprint,
                                    'version_id' => (int) $version->id,
                                    'branch' => $branch,
                                    'position' => (int) $position,
                                ]),
                            )
                            : $this->compatibilityActions->handle(
                                $branchShadow,
                                $action,
                                $actor,
                                $envelope,
                                false,
                            );
                        $result = $this->normalizeTagProjection($result, $action, $schema2, $branchTags);
                        $result = $this->normalizeCustomFieldProjection(
                            $result,
                            $action,
                            $schema2,
                            $branchShadow,
                            $branchCustomFields,
                        );
                        $status = (string) ($result['status'] ?? 'failed');
                        $effect = in_array($status, ['succeeded', 'planned', 'queued'], true);
                        $entry['actions'][] = [
                            'position' => (int) $position,
                            'status' => $status,
                            'action' => $this->snapshot($action, $schema2),
                            'changes' => (array) ($result['changes'] ?? []),
                            'authorization' => $result['authorization'] ?? null,
                            'after_commit_type' => data_get($result, 'after_commit.type'),
                            'reason_code' => $result['reason_code'] ?? null,
                        ];
                        $actionIndex = count($entry['actions']) - 1;
                        $this->registerCollisions(
                            $entry['actions'][$actionIndex],
                            $version,
                            $sequence,
                            $branchWriters,
                            $branchCollisions,
                        );
                        if ($effect) {
                            $branchEffect = true;
                            $rawChanges = $this->rawProjection(
                                $branchShadow,
                                $action,
                                (array) ($result['changes'] ?? []),
                                $branchCustomFields,
                            );
                            $this->applyProjection(
                                $branchShadow,
                                $branchTags,
                                $branchCustomFields,
                                $rawChanges,
                            );
                            foreach ($this->derivedMutations(
                                $ticket,
                                $action,
                                $result,
                                $rawChanges,
                                $envelope,
                                $branchTags,
                                (int) $version->id,
                                (int) $position,
                            ) as $mutation) {
                                $pendingEvents[] = $mutation;
                            }
                        }
                    } catch (TicketRuleActionFailure $failure) {
                        $branchFailed = true;
                        $branchFailureCode = $failure->reasonCode;
                        $entry['actions'][] = [
                            'position' => (int) $position,
                            'status' => 'failed',
                            'action' => $this->snapshot($action, $schema2),
                            'changes' => [],
                            'reason_code' => $failure->reasonCode,
                        ];
                    } catch (Throwable $failure) {
                        $branchFailed = true;
                        $branchFailureCode = 'unexpected_action_failure';
                        $entry['actions'][] = [
                            'position' => (int) $position,
                            'status' => 'failed',
                            'action' => $this->snapshot($action, $schema2),
                            'changes' => [],
                            'reason_code' => 'unexpected_action_failure',
                            'failure_fingerprint' => $this->sanitizer->message($failure->getMessage()),
                        ];
                    }
                }

                if ($branchFailed) {
                    $loopBlocked = $branchFailureCode
                        === TicketRuleEvent::LOOP_REASON_ACTION_BUDGET_EXCEEDED;
                    $entry['status'] = 'failed';
                    $entry['reason_code'] = $loopBlocked ? $branchFailureCode : 'branch_failed';
                    $entry['actions'] = collect($entry['actions'])->map(function (array $action): array {
                        if (in_array($action['status'], ['succeeded', 'planned', 'queued'], true)) {
                            $action['status'] = 'rolled_back';
                        }

                        return $action;
                    })->all();
                    $hadFailure = true;
                    $counters['failed_executions']++;
                    if ($loopBlocked) {
                        $entry['loop_blocked'] = true;
                        $entry['loop_reason_code'] = $branchFailureCode;
                        $counters['loop_blocks']++;
                        $halted = true;
                        $haltReasonCode = $branchFailureCode;
                        $haltBlockedEventFingerprint = null;
                        $events[$eventIndex]['status'] = 'loop_blocked';
                        $events[$eventIndex]['loop_reason_code'] = $branchFailureCode;
                        $events[$eventIndex]['blocked_event_fingerprint'] = null;
                    }
                    $rows[] = $entry;

                    continue;
                }

                $shadow = $branchShadow;
                $tagIds = $branchTags;
                $entry['status'] = $branchEffect ? 'would_change' : 'no_change';
                $hadEffect = $hadEffect || $branchEffect;
                $customFieldOverlay = $branchCustomFields;
                $writers = $branchWriters;
                $collisions = $branchCollisions;

                foreach ($pendingEvents as $mutation) {
                    $derived = TicketRuleEventEnvelope::mutation($shadow, $mutation, $operator, $actor);
                    $derived = $this->withDepth($derived, $envelope->chainDepth + 1, $envelope);
                    if (! $this->hasConsumer($versions, $derived)) {
                        continue;
                    }

                    $childSequence = $nextSequence++;
                    $counters['events']++;
                    $reasonCode = null;
                    if ($derived->chainDepth > $limits['max_depth']) {
                        $reasonCode = TicketRuleEvent::LOOP_REASON_DEPTH_BUDGET_EXCEEDED;
                    } elseif (isset($visited[$derived->fingerprint])) {
                        $reasonCode = TicketRuleEvent::LOOP_REASON_REPEATED_EVENT_FINGERPRINT;
                    }

                    if ($reasonCode !== null) {
                        $wrapperFingerprint = TicketRuleStableJson::checksum([
                            'blocked_fingerprint' => $derived->fingerprint,
                            'preview_parent_event_fingerprint' => $envelope->fingerprint,
                            'event_sequence' => $childSequence,
                        ]);
                        $counters['loop_blocks']++;
                        $entry['loop_blocked'] = true;

                        $entry['loop_reason_code'] = $reasonCode;
                        $entry['blocked_event_fingerprint'] = $derived->fingerprint;
                        $halted = true;
                        $haltReasonCode = $reasonCode;
                        $haltBlockedEventFingerprint = $derived->fingerprint;
                        $events[$eventIndex]['status'] = 'loop_blocked';
                        $events[$eventIndex]['loop_reason_code'] = $reasonCode;
                        $events[$eventIndex]['blocked_event_fingerprint'] = $derived->fingerprint;
                        $events[] = [
                            'sequence' => $childSequence,
                            'event_key' => $derived->eventKey,
                            'chain_depth' => $derived->chainDepth,
                            'event_fingerprint' => $wrapperFingerprint,
                            'blocked_event_fingerprint' => $derived->fingerprint,
                            'status' => 'loop_blocked',
                            'loop_reason_code' => $reasonCode,
                            'reason_code' => $reasonCode,
                        ];

                        continue;
                    }

                    $visited[$derived->fingerprint] = true;
                    $queue[] = [
                        'envelope' => $derived,
                        'sequence' => $childSequence,
                        'custom_fields' => $customFieldOverlay,
                    ];
                }

                if ($halted) {
                    $rows[] = $entry;
                    break;
                }
                $stopRequested = ($matched || $branchActions !== [])
                    && (bool) data_get($definition, 'flow.stop_processing', false);
                $entry['stop_requested'] = $stopRequested;
                $entry['stop_applied'] = $stopRequested;
                $eventStopped = $entry['stop_applied'];
                $stopped = $stopped || $eventStopped;
                $rows[] = $entry;
            }

            if (($events[$eventIndex]['status'] ?? null) === 'processing') {
                $events[$eventIndex]['status'] = $halted
                    ? 'loop_blocked'
                    : ($eventStopped ? 'stopped' : 'completed');
            }
        }

        if ($halted && $queue !== []) {
            foreach ($queue as $queued) {
                /** @var TicketRuleEventEnvelope $pendingEnvelope */
                $pendingEnvelope = $queued['envelope'];
                $events[] = [
                    'sequence' => (int) $queued['sequence'],
                    'event_key' => $pendingEnvelope->eventKey,
                    'chain_depth' => $pendingEnvelope->chainDepth,
                    'event_fingerprint' => $pendingEnvelope->fingerprint,
                    'blocked_event_fingerprint' => $haltBlockedEventFingerprint,
                    'status' => 'loop_blocked',
                    'loop_reason_code' => $haltReasonCode,
                    'reason_code' => $haltReasonCode,
                ];
            }
            $queue = [];
        }

        usort(
            $events,
            fn (array $left, array $right): int => ((int) ($left['sequence'] ?? 0))
                <=> ((int) ($right['sequence'] ?? 0)),
        );

        $terminal = $halted
            ? 'loop_blocked'
            : ($hadFailure ? 'failed' : ($hadEffect ? 'would_change' : 'no_change'));

        return [
            'mode' => 'preview',
            'execution_scope' => ['ticket.created'],
            'ticket_id' => (int) $ticket->id,
            'work_context_id' => (int) $ticket->work_context_id,
            'authority_generation' => (int) $fence->catalog_generation,
            'authority_checksum' => (string) $fence->catalog_checksum,
            'published_set_checksum' => $frozen['checksum'],
            'published_version_ids' => array_slice(
                $frozen['version_ids'],
                0,
                self::MAX_PUBLIC_ROWS,
            ),
            'published_version_ids_omitted_count' => max(0, count($frozen['version_ids']) - self::MAX_PUBLIC_ROWS),
            'limits' => $limits,
            'counters' => $counters,
            'terminal_status' => $terminal,
            'rules' => array_slice($rows, 0, self::MAX_PUBLIC_ROWS),

            'rules_omitted_count' => max(0, count($rows) - self::MAX_PUBLIC_ROWS),
            'root_rules_not_evaluated_count' => 0,
            'collisions' => array_slice($collisions, 0, self::MAX_PUBLIC_ROWS),
            'collisions_omitted_count' => max(0, count($collisions) - self::MAX_PUBLIC_ROWS),
            'last_successful_writers' => array_values($writers),
            'last_successful_writers_omitted_count' => 0,
            'derived_events' => array_values(array_filter(
                $events,
                fn (array $event): bool => ($event['chain_depth'] ?? 0) > 0,
            )),
            'derived_events_omitted_count' => 0,
            'events' => $events,
            'loop_risk' => [
                'status' => $counters['loop_blocks'] > 0
                    ? 'blocked'
                    : ($events === [] ? 'none' : 'bounded'),
                'current_non_created_trigger_count' => $versions->filter(
                    fn (TicketRuleVersion $version): bool => data_get($version->definition_json, 'trigger')
                        !== TicketRuleDefinitionRegistry::TRIGGER_CREATED,
                )->count(),
                'risks' => array_values(array_filter(
                    $events,
                    fn (array $event): bool => ($event['status'] ?? null) === 'loop_blocked',
                )),
                'risks_omitted_count' => 0,
            ],
            'planned_state' => $this->plannedState($shadow, $tagIds),
            'stopped' => $stopped,
            'halted' => $halted,
        ];
    }

    /** @param array<string, mixed> $definition */
    private function relevant(
        TicketRuleVersion $version,
        array $definition,
        TicketRuleEventEnvelope $envelope,
    ): bool {
        $trigger = $definition['trigger'] ?? null;
        $eventKeys = collect((array) ($envelope->facts['event_keys'] ?? []))
            ->prepend($envelope->eventKey)
            ->filter(fn (mixed $key): bool => is_string($key) && str_starts_with($key, 'ticket.'))
            ->unique()
            ->values()
            ->all();

        if ((int) $version->definition_schema_version
            !== TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION) {
            return is_string($trigger) && in_array($trigger, $eventKeys, true);
        }

        if (! is_string($trigger)) {
            return false;
        }
        $triggerDefinition = $this->triggers->definition($trigger);
        $candidateKeys = array_values(array_intersect(
            $eventKeys,
            (array) ($triggerDefinition['event_keys'] ?? []),
        ));
        if ($triggerDefinition === null || $candidateKeys === [] || ! $this->triggers->enabled($trigger)) {
            return false;
        }
        $filters = $this->triggers->canonicalizeFilters($trigger, $definition['trigger_filters'] ?? null);
        if (! ($filters['valid'] ?? false)) {
            return true;
        }

        foreach ($candidateKeys as $eventKey) {
            if ($this->triggers->isRelevant($trigger, $filters['filters'], [
                'event_key' => $eventKey,
                'source_channel' => $envelope->sourceChannel,
                'changed_fields' => $envelope->changedFields,
                'before' => $envelope->before,
                'after' => $envelope->after,
                'facts' => $envelope->facts,
            ])) {
                return true;
            }
        }

        return false;
    }

    private function hasConsumer(Collection $versions, TicketRuleEventEnvelope $envelope): bool
    {
        return $versions->contains(function (TicketRuleVersion $version) use ($envelope): bool {
            $definition = is_array($version->definition_json) ? $version->definition_json : [];

            return $this->relevant($version, $definition, $envelope);
        });
    }

    /** @return array<string, mixed> */
    private function facts(TicketRuleEventEnvelope $event, array $customFields): array
    {
        return array_merge($event->facts, [
            '_preview_custom_field_current' => $customFields,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(
        TicketRuleVersion $version,
        int $sequence,
        string $eventKey,
        int $order,
        array $extra,
    ): array {
        return $extra + [
            'event_sequence' => $sequence,
            'event_key' => $eventKey,
            'order_position' => $order,
            'ticket_rule_id' => (int) $version->ticket_rule_id,
            'rule_version_id' => (int) $version->id,
            'definition_checksum' => (string) $version->definition_checksum,
            'trigger_relevant' => true,
        ];
    }

    /** @param array<string, mixed> $action */
    private function snapshot(array $action, bool $schema2): array
    {
        return $schema2
            ? $this->schema2Actions->snapshot($action)
            : $this->compatibilityActions->snapshot($action);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $action
     * @param  list<int>  $tags
     * @return array<string, mixed>
     */
    private function normalizeTagProjection(array $result, array $action, bool $schema2, array $tags): array
    {
        if (! $schema2 || ! in_array($action['type'] ?? null, [
            TicketRuleActionProviderRegistry::ADD_TAGS,
            TicketRuleActionProviderRegistry::REMOVE_TAGS,
        ], true)) {
            return $result;
        }

        $requested = array_map('intval', (array) data_get($action, 'input.tag_ids', []));
        $after = ($action['type'] ?? null) === TicketRuleActionProviderRegistry::ADD_TAGS
            ? array_values(array_unique(array_merge($tags, $requested)))
            : array_values(array_diff($tags, $requested));
        sort($after);

        $result['status'] = $tags === $after ? 'no_change' : 'planned';
        $result['changes'] = $tags === $after ? [] : [
            'tag_ids' => ['before' => $tags, 'after' => $after],
        ];

        return $result;
    }

    /**
     * Recompute Custom Field preview evidence against the branch-local overlay.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $action
     * @param  array<int|string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function normalizeCustomFieldProjection(
        array $result,
        array $action,
        bool $schema2,
        Ticket $ticket,
        array $overlay,
    ): array {
        if (! $schema2 || ! in_array($action['type'] ?? null, [
            TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
            TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD,
        ], true)) {
            return $result;
        }

        $resolved = $this->customFieldTargets->resolveForAutomation(
            data_get($action, 'input.target'),
            'action',
        );
        if (! ($resolved['valid'] ?? false) || ! $resolved['definition']) {
            return $result;
        }

        $definition = $resolved['definition'];
        $key = (string) $definition->id;
        $before = array_key_exists($key, $overlay)
            ? $overlay[$key]
            : $this->customFieldValues->current($ticket, $definition);
        $after = ($action['type'] ?? null) === TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD
            ? $this->customFieldValues->normalize($definition, null)
            : $this->customFieldValues->normalize(
                $definition,
                data_get($action, 'input.value'),
            );

        if ($this->customFieldValues->equivalent($before, $after)) {
            $result['status'] = 'no_change';
            $result['changes'] = [];

            return $result;
        }

        $result['status'] = 'planned';
        $result['changes'] = [
            'custom_field.'.(int) $definition->id => [
                'before' => $this->customFieldValues->auditProjection($definition, $before),
                'after' => $this->customFieldValues->auditProjection($definition, $after),
            ],
        ];

        return $result;
    }

    /**
     * @param  list<int>  $tags
     * @param  array<int|string, mixed>  $customFields
     * @param  array<string, mixed>  $changes
     */
    private function applyProjection(
        Ticket $ticket,
        array &$tags,
        array &$customFields,
        array $changes,
    ): void {
        foreach ($changes as $field => $change) {
            if (! is_array($change) || ! array_key_exists('after', $change)) {
                continue;
            }
            if ($field === 'tag_ids') {
                $tags = array_values(array_map('intval', (array) $change['after']));
                sort($tags);
            } elseif (is_string($field) && str_starts_with($field, 'custom_field.')) {
                $definitionId = (int) substr($field, strlen('custom_field.'));
                if ($definitionId > 0) {
                    $customFields[(string) $definitionId] = $change['after'];
                }
            } else {
                $ticket->setAttribute((string) $field, $change['after']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $changes
     * @param  list<int>  $tags
     * @return list<TicketRuleMutationEvent>
     */
    private function derivedMutations(
        Ticket $ticket,
        array $action,
        array $result,
        array $changes,
        TicketRuleEventEnvelope $parent,
        array $tags,
        int $versionId,
        int $position,
    ): array {
        $provided = collect((array) ($result['derived_events'] ?? []))
            ->filter(fn (mixed $event): bool => $event instanceof TicketRuleMutationEvent)
            ->values()
            ->all();
        if ($provided !== []) {
            return $provided;
        }

        if ($changes === [] || ($action['type'] ?? null) === TicketRuleActionProviderRegistry::EMIT_SIGNAL) {
            return [];
        }

        $type = $action['type'] ?? null;
        if (in_array($type, [
            TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
            TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD,
        ], true)) {
            $customFieldEvent = $this->customFieldMutation($ticket, $action, $changes, $parent, $versionId, $position);

            return $customFieldEvent ? [$customFieldEvent] : [];
        }
        $before = collect($changes)->mapWithKeys(fn (array $change, string $field): array => [
            $field => $change['before'] ?? null,
        ])->all();
        $after = collect($changes)->mapWithKeys(fn (array $change, string $field): array => [
            $field => $change['after'] ?? null,
        ])->all();
        $eventKey = $type === TicketRuleActionProviderRegistry::ADD_INTERNAL_NOTE
            ? TicketRuleTriggerRegistry::MESSAGE_ADDED
            : (array_key_exists('tag_ids', $changes)
                ? TicketRuleTriggerRegistry::TAGS_CHANGED
                : TicketRuleTriggerRegistry::UPDATED);
        $safeFacts = ['event_source_channel' => 'ticket_rule'];
        $classification = [];
        $assignmentChanges = $this->assignmentChanges($before, $after);
        if ($assignmentChanges !== []) {
            $safeFacts += [
                'queue_id' => $after['queue_id'] ?? null,
                'owner_id' => $after['owner_id'] ?? null,
                'assignment_changes' => $assignmentChanges,
            ];
            $classification += [
                'assignment_changes' => $assignmentChanges,
                'assignment_concept' => 'queue_and_individual_owner',
            ];
        }

        if ($eventKey === TicketRuleTriggerRegistry::TAGS_CHANGED) {
            $beforeTags = array_map('intval', (array) ($before['tag_ids'] ?? []));
            $safeFacts += [
                'tag_ids' => $tags,
                'added_tag_ids' => array_values(array_diff($tags, $beforeTags)),
                'removed_tag_ids' => array_values(array_diff($beforeTags, $tags)),
            ];
        }
        if ($eventKey === TicketRuleTriggerRegistry::MESSAGE_ADDED) {
            // Trigger relevance requires a positive record ID. Semantic loop
            // identity normalizes this preview-only generated identifier.
            $after['message_id'] = 1;
            $body = (string) data_get($action, 'input.body', '');
            $safeFacts += [
                'message_type' => 'internal_note',
                'message_visibility' => 'internal',
                'attachments_count' => 0,
                'message_body_length' => mb_strlen($body),
                'message_body_sha256' => hash('sha256', $body),
                'message_subject_length' => 0,
                'message_subject_sha256' => hash('sha256', ''),
            ];
            $classification += [
                'message_type' => 'internal_note',
                'message_visibility' => 'internal',
            ];
        }

        return [TicketRuleMutationEvent::make(
            ticketId: (int) $ticket->id,
            eventKey: $eventKey,
            changedFields: array_keys($changes),
            before: $before,
            after: $after,
            safeFacts: $safeFacts,
            classification: $classification,
            sourceChannel: 'ticket_rule',
            sourceAction: 'TicketRulePreview',
            deliveryIdentity: 'preview:'.$parent->fingerprint.':'.$versionId.':'.$position,
            correlationUuid: $parent->correlationUuid,
            causationUuid: $parent->correlationUuid,
        )];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function assignmentChanges(array $before, array $after): array
    {
        $changes = [];
        if (array_key_exists('queue_id', $before)
            && array_key_exists('queue_id', $after)
            && $before['queue_id'] !== $after['queue_id']) {
            $changes[] = 'queue_changed';
        }

        if (! array_key_exists('owner_id', $before)
            || ! array_key_exists('owner_id', $after)
            || $before['owner_id'] === $after['owner_id']) {
            return $changes;
        }

        if ($before['owner_id'] === null && $after['owner_id'] !== null) {
            $changes[] = 'owner_assigned';
        } elseif ($before['owner_id'] !== null && $after['owner_id'] === null) {
            $changes[] = 'owner_unassigned';
        } else {
            $changes[] = 'owner_changed';
        }

        return $changes;
    }

    /**
     * Build the runtime-equivalent Custom Field event without exposing raw values.
     *
     * Exact values live only in the event facts used by the in-memory evaluator.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $changes
     */
    private function customFieldMutation(
        Ticket $ticket,
        array $action,
        array $changes,
        TicketRuleEventEnvelope $parent,
        int $versionId,
        int $position,
    ): ?TicketRuleMutationEvent {
        $type = $action['type'] ?? null;
        $resolved = $this->customFieldTargets->resolveForAutomation(
            data_get($action, 'input.target'),
            'action',
        );
        if (! is_string($type)
            || ! ($resolved['valid'] ?? false)
            || ! $resolved['definition']) {
            return null;
        }

        $definition = $resolved['definition'];
        $fieldKey = 'custom_field.'.(int) $definition->id;
        $change = $changes[$fieldKey] ?? null;
        if (! is_array($change)
            || ! array_key_exists('before', $change)
            || ! array_key_exists('after', $change)) {
            return null;
        }

        $before = $change['before'];
        $after = $change['after'];
        $direction = ! $this->customFieldValues->present($before)
            && $this->customFieldValues->present($after)
                ? 'set'
                : ($this->customFieldValues->present($before)
                    && ! $this->customFieldValues->present($after)
                        ? 'cleared'
                        : 'changed');
        $definitionId = (int) $definition->id;

        return TicketRuleMutationEvent::make(
            ticketId: (int) $ticket->id,
            eventKey: TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED,
            changedFields: [$fieldKey],
            before: [
                $fieldKey => $this->customFieldValues->auditProjection($definition, $before),
            ],
            after: [
                $fieldKey => $this->customFieldValues->auditProjection($definition, $after),
            ],
            safeFacts: [
                'custom_fields' => [
                    (string) $definitionId => $this->customFieldValues->fact(
                        $definition,
                        $before,
                        $after,
                    ),
                ],
                'changed_custom_field_definition_ids' => [$definitionId],
                'custom_field_change_directions' => [(string) $definitionId => $direction],
                'event_source_channel' => 'ticket_rule',
                'event_source_action' => 'TicketRuleCustomFieldActionExecutor.'.$type,
            ],
            classification: [
                'custom_field_definition_ids' => [$definitionId],
                'custom_field_change_directions' => [(string) $definitionId => $direction],
                'raw_values_persisted' => false,
            ],
            sourceChannel: 'ticket_rule',
            sourceAction: 'TicketRuleCustomFieldActionExecutor.'.$type,
            deliveryIdentity: 'preview:'.$parent->fingerprint.':'.$versionId.':'.$position,
            correlationUuid: $parent->correlationUuid,
            causationUuid: $parent->correlationUuid,
        );
    }

    private function withDepth(
        TicketRuleEventEnvelope $event,
        int $depth,
        TicketRuleEventEnvelope $parent,
    ): TicketRuleEventEnvelope {
        return new TicketRuleEventEnvelope(
            ticketId: $event->ticketId,
            eventKey: $event->eventKey,
            sourceChannel: $event->sourceChannel,
            sourceAction: $event->sourceAction,
            changedFields: $event->changedFields,
            before: $event->before,
            after: $event->after,
            facts: $event->facts,
            initiatorType: $event->initiatorType,
            initiatorId: $event->initiatorId,
            automationActorId: $event->automationActorId,
            correlationUuid: $event->correlationUuid,
            causationUuid: $parent->correlationUuid,
            parentEventId: null,
            parentActionResultId: null,
            chainDepth: $depth,
            occurredAt: $event->occurredAt,
            fingerprint: $event->fingerprint,
            idempotencyKey: $event->idempotencyKey,
        );
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, array<string, mixed>>  $writers
     * @param  list<array<string, mixed>>  $collisions
     */
    private function registerCollisions(
        array &$action,
        TicketRuleVersion $version,
        int $sequence,
        array &$writers,
        array &$collisions,
    ): void {
        if (! in_array($action['status'], ['succeeded', 'planned', 'queued'], true)) {
            return;
        }

        foreach (array_keys((array) ($action['changes'] ?? [])) as $field) {
            $target = 'field:'.$field;
            $writer = [
                'target' => $target,
                'event_sequence' => $sequence,
                'ticket_rule_id' => (int) $version->ticket_rule_id,
                'rule_version_id' => (int) $version->id,
                'action_position' => (int) $action['position'],
            ];
            if (isset($writers[$target])) {
                $collisions[] = [
                    'target' => $target,
                    'previous_writer' => $writers[$target],
                    'new_writer' => $writer,
                    'resolution' => 'last_planned_writer',
                ];
            }
            $writers[$target] = $writer;
        }
    }

    /** @param list<int> $tagIds @return array<string, mixed> */
    private function plannedState(Ticket $ticket, array $tagIds): array
    {
        return [
            'ticket_type_id' => $ticket->ticket_type_id,
            'queue_id' => $ticket->queue_id,
            'status_id' => $ticket->status_id,
            'priority_id' => $ticket->priority_id,
            'sla_id' => $ticket->sla_id,
            'category_id' => $ticket->category_id,
            'client_id' => $ticket->client_id,
            'site_id' => $ticket->site_id,
            'contact_id' => $ticket->contact_id,
            'asset_id' => $ticket->asset_id,
            'owner_id' => $ticket->owner_id,
            'impact' => $ticket->impact,
            'urgency' => $ticket->urgency,
            'workflow_id' => $ticket->workflow_id,
            'workflow_version_id' => $ticket->workflow_version_id,
            'workflow_state_key' => $ticket->workflow_state_key,
            'tag_ids' => $tagIds,
        ];
    }

    /**
     * Recover exact in-memory values from the already canonicalized action.
     *
     * Public action evidence remains the sanitized $changes array.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function rawProjection(Ticket $ticket, array $action, array $changes, array $customFields): array
    {
        $type = $action['type'] ?? null;
        if (in_array($type, [
            TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
            TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD,
        ], true)) {
            $resolved = $this->customFieldTargets->resolveForAutomation(
                data_get($action, 'input.target'),
                'action',
            );
            if (! ($resolved['valid'] ?? false) || ! $resolved['definition']) {
                return $changes;
            }

            $definition = $resolved['definition'];
            $key = (string) $definition->id;
            $before = array_key_exists($key, $customFields)
                ? $customFields[$key]
                : $this->customFieldValues->current($ticket, $definition);
            $after = $type === TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD
                ? $this->customFieldValues->normalize($definition, null)
                : $this->customFieldValues->normalize(
                    $definition,
                    data_get($action, 'input.value'),
                );

            return [
                'custom_field.'.(int) $definition->id => [
                    'before' => $before,
                    'after' => $after,
                ],
            ];
        }

        $fields = match ($type) {
            TicketRuleActionProviderRegistry::SET_TICKET_FIELDS => (array) data_get(
                $action,
                'input.fields',
                [],
            ),
            TicketRuleActionProviderRegistry::SET_QUEUE => [
                'queue_id' => data_get($action, 'input.queue_id'),
            ],
            TicketRuleActionProviderRegistry::ASSIGN_OWNER => [
                'owner_id' => data_get($action, 'input.owner_id'),
            ],
            TicketRuleActionProviderRegistry::UNASSIGN_OWNER => ['owner_id' => null],
            default => [],
        };

        if ($fields === []) {
            return $changes;
        }

        $raw = [];
        foreach ($fields as $field => $after) {
            if (! array_key_exists((string) $field, $changes)) {
                continue;
            }
            $raw[(string) $field] = [
                'before' => $ticket->getAttribute((string) $field),
                'after' => $after,
            ];
        }

        return $raw;
    }
}
