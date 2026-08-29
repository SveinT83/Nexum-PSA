<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Support\TicketRuleActionFailure;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleEventEnvelope;
use App\Modules\Ticket\Support\TicketRuleRestrictedEvidence;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class TicketRulePreviewPlanner
{
    private const MAX_RULES = 200;

    private const MAX_ACTIONS = 200;

    private const MAX_GROUPS = 20;

    private const MAX_ROWS = 50;

    private const MAX_COLLISIONS = 200;

    private const MAX_EVENTS = 200;

    private const MAX_RISKS = 100;

    private const MAX_WRITERS = 200;

    // Configured values remain tunable inside these reviewed safety ceilings.
    private const MAX_DEPTH_LIMIT = 32;

    private const MAX_EVALUATED_RULES_LIMIT = 500;

    private const MAX_EXECUTED_ACTIONS_LIMIT = 500;

    public function __construct(
        private readonly TicketRuleRuntimeGate $runtimeGate,
        private readonly TicketRuleFrozenPublishedSet $frozenPublishedSet,
        private readonly TicketRuleConditionEvaluator $conditionEvaluator,
        private readonly TicketRuleCompatibilityActionExecutor $actionExecutor,
        private readonly TicketRulePublishedDefinitionValidator $definitionValidator,
        private readonly TicketRuleSchema2ConditionEvaluator $schema2ConditionEvaluator,
        private readonly TicketRuleSchema2ActionExecutor $schema2ActionExecutor,
        private readonly TicketRulePreviewQueueSimulator $queueSimulator,
        private readonly TicketRulePublicationTargetValidator $publicationTargets,
        private readonly TicketRuleAuditSanitizer $sanitizer,
    ) {}

    /**
     * Use runtime checks against an in-memory Ticket shadow without writes.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function created(Ticket $ticket, array $context, User $operator): array
    {
        if (! (bool) config('ticket_rules.v2_enabled', false)) {
            throw new RuntimeException('Ticket Rule v2 capability is disabled.');
        }

        return DB::transaction(function () use ($ticket, $context, $operator): array {
            $operator = $this->authorizedOperator($operator);
            $ticket = $this->authorizedTicketSnapshot($ticket);
            $actor = $this->runtimeGate->requireExistingActor();
            $fence = TicketRuleAuthorityFence::query()
                ->whereKey(TicketRuleAuthorityFence::SCOPE)
                ->sharedLock()
                ->firstOrFail();
            $frozen = $this->frozenPublishedSet->capture();
            $this->authorizePublishedQueueEvidence($frozen['versions'], $operator);
            if ($frozen['versions']->contains(
                fn (TicketRuleVersion $version): bool => (int) $version->definition_schema_version
                    === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            )) {
                return $this->queueSimulator->simulate(
                    $ticket,
                    $context,
                    $operator,
                    $actor,
                    $fence,
                    $frozen,
                );
            }
            $envelope = TicketRuleEventEnvelope::created($ticket, $context, $operator, $actor);
            $limits = $this->limits();
            $counters = [
                'events' => 1,
                'evaluated_rules' => 0,
                'actions' => 0,
                'loop_blocks' => 0,
                'failed_executions' => 0,
            ];
            $shadowTicket = clone $ticket;
            $shadowTagIds = $this->ticketTagIds($ticket);
            $visitedFingerprints = [$envelope->fingerprint => true];
            $rules = [];
            $collisions = [];
            $collisionOmitted = 0;
            $lastWriters = [];
            $derivedEvents = [];
            $loopRisks = [];
            $riskOmitted = 0;
            $stopped = false;
            $halted = false;
            $hadEffect = false;
            $hadFailure = false;
            $rootRuleCount = 0;
            $rootRelevantRuleCount = $frozen['versions']
                ->filter(fn (TicketRuleVersion $version): bool => data_get(
                    $version->definition_json,
                    'trigger',
                ) === TicketRuleDefinitionRegistry::TRIGGER_CREATED)
                ->count();
            $rulesOmitted = 0;

            /** @var TicketRuleVersion $version */
            foreach ($frozen['versions'] as $order => $version) {
                if ($stopped || $halted) {
                    break;
                }

                $definition = is_array($version->definition_json) ? $version->definition_json : [];

                // Runtime ignores irrelevant triggers before consuming budgets
                // or validating definitions, so preview must do the same.
                if (($definition['trigger'] ?? null) !== TicketRuleDefinitionRegistry::TRIGGER_CREATED) {
                    continue;
                }

                $entry = $this->ruleEntry($version, (int) $order + 1);
                $entry['trigger_relevant'] = true;

                if ($counters['evaluated_rules'] >= $limits['max_evaluated_rules']) {
                    $this->recordRisk($loopRisks, $riskOmitted, [
                        'reason_code' => 'evaluated_rule_budget_exceeded',
                        'event_key' => $envelope->eventKey,
                        'chain_depth' => 0,
                        'rule_order_position' => (int) $order + 1,
                    ]);
                    $counters['loop_blocks']++;
                    $halted = true;
                    break;
                }

                $counters['evaluated_rules']++;
                $rootRuleCount++;

                $validation = $this->definitionValidator->validateStored($definition);
                if (($validation['status'] ?? null) !== TicketRulePublishedDefinitionValidator::STATUS_VALID
                    || (int) $version->definition_schema_version !== (int) ($validation['schema_version'] ?? 0)
                    || TicketRuleStableJson::checksum($definition) !== $version->definition_checksum
                    || ($validation['checksum'] ?? null) !== $version->definition_checksum) {
                    $entry['status'] = 'failed';
                    $entry['reason_code'] = 'invalid_published_definition';
                    $counters['failed_executions']++;
                    $hadFailure = true;
                    $this->appendRule($rules, $rulesOmitted, $entry);

                    continue;
                }

                $definition = $validation['definition'];
                $facts = $this->shadowFacts($envelope->facts, $shadowTicket, $shadowTagIds);
                $evaluation = (int) $version->definition_schema_version
                    === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION
                        ? $this->schema2ConditionEvaluator->evaluate($definition, $facts)
                        : $this->conditionEvaluator->evaluate($definition, $facts);
                $entry['condition_evidence'] = $this->boundedEvidence($evaluation);

                if (! ($evaluation['valid'] ?? false)) {
                    $entry['status'] = 'failed';
                    $entry['reason_code'] = $evaluation['reason_code'] ?? 'invalid_runtime_definition';
                    $counters['failed_executions']++;
                    $hadFailure = true;
                    $this->appendRule($rules, $rulesOmitted, $entry);

                    continue;
                }

                $matched = (bool) $evaluation['passed'];
                $branch = $matched ? 'then' : 'else';
                $actions = (array) ($definition[$matched ? 'then_actions' : 'else_actions'] ?? []);
                $entry['selected_branch'] = $branch;
                $entry['stop_requested'] = ($matched || $actions !== [])
                    && (bool) data_get($definition, 'flow.stop_processing', false);

                if (! $matched && $actions === []) {
                    $this->appendRule($rules, $rulesOmitted, $entry);

                    continue;
                }

                $branchTicket = clone $shadowTicket;
                $branchTags = $shadowTagIds;
                $branchActions = [];
                $branchEffect = false;
                $failurePosition = null;
                $failureCode = null;

                foreach ($actions as $position => $action) {
                    $position = (int) $position;
                    $action = is_array($action) ? $action : [];

                    if ($failurePosition !== null) {
                        $branchActions[] = $this->notRunAction($position, $action);

                        continue;
                    }

                    if ($counters['actions'] >= $limits['max_actions']) {
                        $failurePosition = $position;
                        $failureCode = 'action_budget_exceeded';
                        $branchActions[] = $this->failedAction($position, $action, $failureCode);

                        continue;
                    }

                    $counters['actions']++;

                    try {
                        $schema2 = (int) $version->definition_schema_version
                            === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION;
                        $result = $schema2
                            ? $this->schema2ActionExecutor->handle(
                                $branchTicket,
                                $action,
                                $actor,
                                $envelope,
                                false,
                                TicketRuleStableJson::checksum([
                                    'preview_version_id' => (int) $version->id,
                                    'branch' => $branch,
                                    'position' => $position,
                                ]),
                            )
                            : $this->actionExecutor->handle($branchTicket, $action, $actor, $envelope, false);
                        $result = $this->respectShadowTags($result, $action, $branchTags);
                        $branchActions[] = [
                            'position' => $position,
                            'action' => $this->snapshotAction($action),
                            'status' => (string) ($result['status'] ?? 'failed'),
                            'changes' => (array) ($result['changes'] ?? []),
                            'authorization' => $result['authorization'] ?? null,
                            'after_commit_type' => data_get($result, 'after_commit.type'),
                            'reason_code' => $result['reason_code'] ?? null,
                            '_action' => $action,
                        ];
                        $branchEffect = $branchEffect
                            || in_array($result['status'] ?? null, ['succeeded', 'planned', 'queued'], true);
                        $this->applyToShadow($branchTicket, $branchTags, $action, $result);
                    } catch (TicketRuleActionFailure $failure) {
                        $failurePosition = $position;
                        $failureCode = $failure->reasonCode;
                        $branchActions[] = $this->failedAction($position, $action, $failureCode);
                    } catch (Throwable $failure) {
                        $failurePosition = $position;
                        $failureCode = 'unexpected_action_failure';
                        $branchActions[] = $this->failedAction(
                            $position,
                            $action,
                            $failureCode,
                            $this->sanitizer->message($failure->getMessage()),
                        );
                    }
                }

                if ($failurePosition !== null) {
                    foreach ($branchActions as &$branchAction) {
                        if ($branchAction['position'] < $failurePosition) {
                            $branchAction['planned_status_before_rollback'] = $branchAction['status'];
                            $branchAction['status'] = 'rolled_back';
                        }
                    }
                    unset($branchAction);

                    $entry['status'] = 'failed';
                    $entry['reason_code'] = $failureCode;
                    $this->setActions($entry, $branchActions);
                    $counters['failed_executions']++;
                    $hadFailure = true;

                    if ($failureCode === 'action_budget_exceeded') {
                        $this->recordRisk($loopRisks, $riskOmitted, [
                            'reason_code' => $failureCode,
                            'event_key' => $envelope->eventKey,
                            'chain_depth' => 0,
                            'rule_order_position' => (int) $order + 1,
                            'action_position' => $failurePosition,
                        ]);
                        $counters['loop_blocks']++;
                        $halted = true;
                    }

                    $this->appendRule($rules, $rulesOmitted, $entry);

                    continue;
                }

                // A successful branch commits as one savepoint-sized shadow unit.
                $shadowTicket = $branchTicket;
                $shadowTagIds = $branchTags;
                $entry['status'] = $branchEffect ? 'would_change' : 'no_change';
                $hadEffect = $hadEffect || $branchEffect;

                foreach ($branchActions as &$branchAction) {
                    $pointer = $this->writerPointer($entry, $branchAction);
                    $this->registerCollisions(
                        $branchAction,
                        $pointer,
                        $lastWriters,
                        $collisions,
                        $collisionOmitted,
                    );

                    if (in_array($branchAction['status'], ['succeeded', 'planned'], true) && $branchAction['changes'] !== []) {
                        $derived = $this->derivedEvent(
                            $ticket,
                            $envelope,
                            $branchAction,
                            $pointer,
                            $limits,
                            $visitedFingerprints,
                            $frozen['versions'],
                        );
                        $derivedEvents[] = $derived;

                        // Slice 2 keeps non-current derived events as
                        // informational candidates without runtime counters.
                        if ($derived['current_trigger_count'] > 0) {
                            $counters['events']++;
                        }

                        if ($derived['current_trigger_count'] > 0
                            && $derived['status'] === 'loop_blocked') {
                            $this->recordRisk($loopRisks, $riskOmitted, [
                                'reason_code' => $derived['reason_code'],
                                'event_key' => $derived['event_key'],
                                'event_fingerprint' => $derived['event_fingerprint'],
                                'chain_depth' => $derived['chain_depth'],
                                'source_writer' => $derived['source_writer'],
                            ]);
                            $counters['loop_blocks']++;
                            $halted = true;
                        }
                    }
                }
                unset($branchAction);

                // Runtime applies a requested stop after any successful branch,
                // even when an independent root loop guard also halts the run.
                $entry['stop_applied'] = $entry['stop_requested'];
                $stopped = $entry['stop_applied'];
                $this->setActions($entry, $branchActions);
                $this->appendRule($rules, $rulesOmitted, $entry);
            }

            $this->analyzeDerivedEvents(
                $derivedEvents,
                $counters,
                $loopRisks,
                $riskOmitted,
                $halted,
            );

            $writers = collect($lastWriters)
                ->sortKeys()
                ->map(fn (array $writer, string $target): array => [
                    'target' => $target,
                    'writer' => $writer,
                ])
                ->values()
                ->take(self::MAX_WRITERS)
                ->all();
            $publicEvents = array_slice($derivedEvents, 0, self::MAX_EVENTS);
            $versionIds = array_slice($frozen['version_ids'], 0, self::MAX_RULES);
            $terminalStatus = $halted
                ? 'loop_blocked'
                : ($hadFailure ? 'failed' : ($hadEffect ? 'would_change' : 'no_change'));

            return [
                'mode' => 'preview',
                'execution_scope' => [TicketRuleDefinitionRegistry::TRIGGER_CREATED],
                'ticket_id' => (int) $ticket->id,
                'work_context_id' => (int) $ticket->work_context_id,
                'authority_generation' => (int) $fence->catalog_generation,
                'authority_checksum' => (string) $fence->catalog_checksum,
                'published_set_checksum' => $frozen['checksum'],
                'published_version_ids' => $versionIds,
                'published_version_ids_omitted_count' => max(0, count($frozen['version_ids']) - count($versionIds)),
                'limits' => $limits,
                'counters' => $counters,
                'terminal_status' => $terminalStatus,
                'rules' => $rules,
                'rules_omitted_count' => $rulesOmitted,
                'root_rules_not_evaluated_count' => max(0, $rootRelevantRuleCount - $rootRuleCount),
                'collisions' => $collisions,
                'collisions_omitted_count' => $collisionOmitted,
                'last_successful_writers' => $writers,
                'last_successful_writers_omitted_count' => max(0, count($lastWriters) - count($writers)),
                'derived_events' => $publicEvents,
                'derived_events_omitted_count' => max(0, count($derivedEvents) - count($publicEvents)),
                'loop_risk' => [
                    'status' => $halted ? 'blocked' : ($derivedEvents === [] ? 'none' : 'bounded'),
                    'current_non_created_trigger_count' => $this->nonCreatedTriggerCount($frozen['versions']),
                    'future_update_trigger_candidate_count' => count($derivedEvents),
                    'self_loop_candidate_count' => collect($derivedEvents)
                        ->where('potential_reason_code', 'self_loop_detected')
                        ->count(),
                    'repeated_fingerprint_candidate_count' => collect($derivedEvents)
                        ->where('potential_reason_code', 'repeated_event_fingerprint')
                        ->count(),
                    'self_loop_detected' => collect($loopRisks)
                        ->contains(fn (array $risk): bool => ($risk['reason_code'] ?? null) === 'self_loop_detected'),
                    'repeated_fingerprint_detected' => collect($derivedEvents)
                        ->contains(fn (array $event): bool => ($event['potential_reason_code'] ?? null)
                            === 'repeated_event_fingerprint'),
                    'maximum_planned_depth' => collect($derivedEvents)->max('chain_depth') ?? 0,
                    'risks' => $loopRisks,
                    'risks_omitted_count' => $riskOmitted,
                ],
                'planned_state' => $this->plannedState($shadowTicket, $shadowTagIds),
                'stopped' => $stopped,
                'halted' => $halted,
            ];
        }, 3);
    }

    /**
     * A queue preview can evaluate every frozen schema 2 rule through derived
     * events. Reauthorize the complete set before generating any condition or
     * action evidence so a restricted Custom Field cannot be inferred.
     *
     * @param  Collection<int, TicketRuleVersion>  $versions
     */
    private function authorizePublishedQueueEvidence(Collection $versions, User $operator): void
    {
        foreach ($versions as $version) {
            if (! $version instanceof TicketRuleVersion
                || (int) $version->definition_schema_version
                    !== TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION) {
                continue;
            }

            try {
                $this->publicationTargets->validateCustomFieldAccess(
                    is_array($version->definition_json) ? $version->definition_json : [],
                    $operator,
                );
            } catch (ValidationException) {
                throw new TicketRuleRestrictedEvidence(
                    'The published Ticket Rule queue preview is unavailable for this account.'
                );
            }
        }
    }

    private function authorizedOperator(User $operator): User
    {
        $id = is_numeric($operator->getKey()) ? (int) $operator->getKey() : 0;
        $operator = $id > 0 ? User::query()->whereKey($id)->first() : null;

        if (! $operator
            || ! $operator->isActive()
            || ! $operator->can('ticket.view')
            || ! $operator->can('ticket.rule_preview')) {
            throw new RuntimeException('Ticket view and Ticket Rule preview permissions are required.');
        }

        return $operator;
    }

    /**
     * Tech Ticket access is currently permission-global. Re-read the exact
     * Ticket through its durable Work Context so stale context fails closed.
     */
    private function authorizedTicketSnapshot(Ticket $requested): Ticket
    {
        $ticketId = is_numeric($requested->getKey()) ? (int) $requested->getKey() : 0;
        $contextId = is_numeric($requested->work_context_id) ? (int) $requested->work_context_id : 0;

        if ($ticketId < 1 || $contextId < 1) {
            throw new RuntimeException('The selected Ticket has no authorized Work Context.');
        }

        $ticket = Ticket::query()
            ->whereKey($ticketId)
            ->where('work_context_id', $contextId)
            ->whereHas('workContext', fn ($query) => $query->whereKey($contextId))
            ->sharedLock()
            ->first();

        if (! $ticket) {
            throw new RuntimeException('The selected Ticket is unavailable in the authorized Work Context.');
        }

        return $ticket;
    }

    /** @return array<string, int> */
    private function limits(): array
    {
        return [
            'max_depth' => min(
                self::MAX_DEPTH_LIMIT,
                max(1, (int) config('ticket_rules.limits.max_depth', 8)),
            ),
            'max_evaluated_rules' => min(
                self::MAX_EVALUATED_RULES_LIMIT,
                max(1, (int) config('ticket_rules.limits.max_evaluated_rules', 100)),
            ),
            'max_actions' => min(
                self::MAX_EXECUTED_ACTIONS_LIMIT,
                max(1, (int) config('ticket_rules.limits.max_actions', 100)),
            ),
        ];
    }

    /** @return list<int> */
    private function ticketTagIds(Ticket $ticket): array
    {
        return $ticket->tags()
            ->pluck('tags.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Conditions use immutable event facts plus committed shadow Ticket state.
     * Legacy email_tags remains the source-delivery fact from the envelope.
     *
     * @param  array<string, mixed>  $base
     * @param  list<int>  $tagIds
     * @return array<string, mixed>
     */
    private function shadowFacts(array $base, Ticket $ticket, array $tagIds): array
    {
        return array_merge($base, [
            'ticket_id' => (int) $ticket->id,
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
            'channel' => $ticket->channel,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'tag_ids' => $tagIds,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $action
     * @param  list<int>  $tagIds
     * @return array<string, mixed>
     */
    private function respectShadowTags(array $result, array $action, array $tagIds): array
    {
        if (($action['type'] ?? null) !== 'add_tag'
            || ! is_numeric($action['value'] ?? null)
            || ! in_array((int) $action['value'], $tagIds, true)
            || ($result['status'] ?? null) !== 'succeeded') {
            return $result;
        }

        return [
            'status' => 'no_change',
            'changes' => [],
            'after_commit' => null,
            'reason_code' => 'planned_tag_already_present',
            'authorization' => $result['authorization'] ?? null,
        ];
    }

    /**
     * @param  list<int>  $tagIds
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $result
     */
    private function applyToShadow(Ticket $ticket, array &$tagIds, array $action, array $result): void
    {
        if (! in_array($result['status'] ?? null, ['succeeded', 'planned'], true)) {
            return;
        }

        if (($action['type'] ?? null) === 'add_tag' && is_numeric($action['value'] ?? null)) {
            $tagIds[] = (int) $action['value'];
            $tagIds = array_values(array_unique($tagIds));
            sort($tagIds);
        }

        foreach ((array) ($result['changes'] ?? []) as $field => $change) {
            if ($field === 'tag_ids' || ! is_array($change) || ! array_key_exists('after', $change)) {
                continue;
            }

            $ticket->setAttribute((string) $field, $change['after']);
        }
    }

    /** @param array<string, mixed> $action */
    private function notRunAction(int $position, array $action): array
    {
        return [
            'position' => $position,
            'action' => $this->snapshotAction($action),
            'status' => 'not_run',
            'changes' => [],
            'authorization' => null,
            'after_commit_type' => null,
            'reason_code' => null,
            '_action' => $action,
        ];
    }

    /** @param array<string, mixed> $action */
    private function failedAction(
        int $position,
        array $action,
        string $reasonCode,
        ?string $fingerprint = null,
    ): array {
        return [
            'position' => $position,
            'action' => $this->snapshotAction($action),
            'status' => 'failed',
            'changes' => [],
            'authorization' => null,
            'after_commit_type' => null,
            'reason_code' => $reasonCode,
            'failure_fingerprint' => $fingerprint,
            '_action' => $action,
        ];
    }

    /** @param array<string, mixed> $action */
    private function snapshotAction(array $action): array
    {
        return array_key_exists('input', $action)
            ? $this->schema2ActionExecutor->snapshot($action)
            : $this->actionExecutor->snapshot($action);
    }

    /** @return array<string, int|string> */
    private function writerPointer(array $entry, array $action): array
    {
        return [
            'rule_order_position' => (int) $entry['order_position'],
            'ticket_rule_id' => (int) $entry['ticket_rule_id'],
            'rule_version_id' => (int) $entry['rule_version_id'],
            'branch' => (string) $entry['selected_branch'],
            'action_position' => (int) $action['position'],
        ];
    }

    /**
     * @param  array<string, int|string>  $pointer
     * @param  array<string, array<string, int|string>>  $lastWriters
     * @param  list<array<string, mixed>>  $collisions
     */
    private function registerCollisions(
        array &$action,
        array $pointer,
        array &$lastWriters,
        array &$collisions,
        int &$omitted,
    ): void {
        if (! in_array($action['status'], ['succeeded', 'planned'], true) || $action['changes'] === []) {
            return;
        }

        $overwrites = [];

        foreach ($this->collisionTargets($action) as $target) {
            $previous = $lastWriters[$target] ?? null;

            if ($previous !== null) {
                if (count($collisions) < self::MAX_COLLISIONS) {
                    $collisions[] = [
                        'target' => $target,
                        'previous_writer' => $previous,
                        'new_writer' => $pointer,
                        'resolution' => 'last_successful_writer',
                    ];
                } else {
                    $omitted++;
                }

                $overwrites[] = ['target' => $target, 'previous_writer' => $previous];
            }

            $lastWriters[$target] = $pointer;
        }

        $action['overwrites'] = $overwrites;
    }

    /** @return list<string> */
    private function collisionTargets(array $action): array
    {
        $targets = [];

        foreach (array_keys((array) $action['changes']) as $field) {
            if ($field === 'tag_ids' && is_numeric(data_get($action, '_action.value'))) {
                $targets[] = 'tag:'.(int) data_get($action, '_action.value');
            } else {
                $targets[] = 'field:'.(string) $field;
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * @param  array<string, int|string>  $pointer
     * @param  array<string, int>  $limits
     * @param  array<string, bool>  $visited
     * @param  Collection<int, TicketRuleVersion>  $versions
     * @return array<string, mixed>
     */
    private function derivedEvent(
        Ticket $ticket,
        TicketRuleEventEnvelope $parent,
        array $action,
        array $pointer,
        array $limits,
        array &$visited,
        Collection $versions,
    ): array {
        $changes = (array) $action['changes'];
        $fields = array_values(array_map('strval', array_keys($changes)));
        $before = collect($changes)->mapWithKeys(fn (mixed $change, string $field): array => [
            $field => is_array($change) ? ($change['before'] ?? null) : null,
        ])->all();
        $after = collect($changes)->mapWithKeys(fn (mixed $change, string $field): array => [
            $field => is_array($change) ? ($change['after'] ?? null) : null,
        ])->all();
        $eventKey = in_array('tag_ids', $fields, true)
            ? 'ticket.tags_changed'
            : 'ticket.fields_changed';
        $depth = 1;
        $fingerprint = TicketRuleStableJson::checksum([
            'ticket_id' => (int) $ticket->id,
            'event_key' => $eventKey,
            'changed_fields' => $fields,
            'before' => $before,
            'after' => $after,
        ]);
        $potentialReason = null;

        if ($depth > $limits['max_depth']) {
            $potentialReason = 'depth_budget_exceeded';
        } elseif ($eventKey === $parent->eventKey) {
            $potentialReason = 'self_loop_detected';
        } elseif (isset($visited[$fingerprint])) {
            $potentialReason = 'repeated_event_fingerprint';
        } else {
            $visited[$fingerprint] = true;
        }

        $currentTriggerCount = $versions
            ->filter(fn (TicketRuleVersion $version): bool => data_get(
                $version->definition_json,
                'trigger',
            ) === $eventKey)
            ->count();
        $reason = $currentTriggerCount > 0 ? $potentialReason : 'no_current_trigger';

        return [
            'sequence' => 0,
            'event_key' => $eventKey,
            'event_fingerprint' => $fingerprint,
            'changed_fields' => $fields,
            'chain_depth' => $depth,
            'source_writer' => $pointer,
            'current_trigger_count' => $currentTriggerCount,
            'evaluated_rule_count' => 0,
            'status' => $currentTriggerCount === 0
                ? 'informational'
                : ($reason === null ? 'queued' : 'loop_blocked'),
            'reason_code' => $reason,
            'potential_reason_code' => $potentialReason,
        ];
    }

    /**
     * Slice 2 never executes non-created triggers. Informational candidates
     * consume no runtime event or rule budget; unexpected current triggers
     * fail closed instead of silently expanding the slice.
     *
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, int>  $counters
     * @param  list<array<string, mixed>>  $risks
     */
    private function analyzeDerivedEvents(
        array &$events,
        array &$counters,
        array &$risks,
        int &$riskOmitted,
        bool &$halted,
    ): void {
        foreach ($events as $index => &$event) {
            $event['sequence'] = $index + 2;

            if ($event['current_trigger_count'] === 0) {
                continue;
            }

            if ($event['status'] === 'loop_blocked') {
                $halted = true;

                continue;
            }

            if ($halted) {
                $event['status'] = 'loop_blocked';
                $event['reason_code'] = 'root_execution_halted';

                continue;
            }

            $event['status'] = 'loop_blocked';
            $event['reason_code'] = 'non_created_trigger_out_of_scope';
            $this->recordRisk($risks, $riskOmitted, [
                'reason_code' => $event['reason_code'],
                'event_key' => $event['event_key'],
                'event_fingerprint' => $event['event_fingerprint'],
                'chain_depth' => $event['chain_depth'],
                'current_trigger_count' => $event['current_trigger_count'],
            ]);
            $counters['loop_blocks']++;
            $halted = true;
        }
        unset($event);

        if ($halted) {
            foreach ($events as &$event) {
                if ($event['current_trigger_count'] > 0 && $event['status'] === 'queued') {
                    $event['status'] = 'loop_blocked';
                    $event['reason_code'] = 'root_execution_halted';
                }
            }
            unset($event);
        }
    }

    /** @param Collection<int, TicketRuleVersion> $versions */
    private function nonCreatedTriggerCount(Collection $versions): int
    {
        return $versions
            ->filter(fn (TicketRuleVersion $version): bool => data_get(
                $version->definition_json,
                'trigger',
            ) !== TicketRuleDefinitionRegistry::TRIGGER_CREATED)
            ->count();
    }

    /** @param list<array<string, mixed>> $rules */
    private function appendRule(array &$rules, int &$omitted, array $entry): void
    {
        if (count($rules) < self::MAX_RULES) {
            $rules[] = $entry;
        } else {
            $omitted++;
        }
    }

    /** @param list<array<string, mixed>> $risks */
    private function recordRisk(array &$risks, int &$omitted, array $risk): void
    {
        if (count($risks) < self::MAX_RISKS) {
            $risks[] = $risk;
        } else {
            $omitted++;
        }
    }

    /** @param list<array<string, mixed>> $actions */
    private function setActions(array &$entry, array $actions): void
    {
        $entry['actions'] = collect($actions)
            ->take(self::MAX_ACTIONS)
            ->map(function (array $action): array {
                unset($action['_action']);

                return $action;
            })
            ->values()
            ->all();
        $entry['actions_omitted_count'] = max(0, count($actions) - count($entry['actions']));
    }

    /** @return array<string, mixed> */
    private function boundedEvidence(array $evaluation): array
    {
        $groups = (array) ($evaluation['groups'] ?? []);
        $bounded = [];

        foreach (array_slice($groups, 0, self::MAX_GROUPS) as $group) {
            $group = is_array($group) ? $group : [];
            $rows = (array) ($group['rows'] ?? []);
            $group['rows'] = array_slice($rows, 0, self::MAX_ROWS);
            $group['rows_omitted_count'] = max(0, count($rows) - count($group['rows']));
            $bounded[] = $group;
        }

        $evaluation['groups'] = $bounded;
        $evaluation['groups_omitted_count'] = max(0, count($groups) - count($bounded));

        return $evaluation;
    }

    /** @return array<string, mixed> */
    private function ruleEntry(TicketRuleVersion $version, int $order): array
    {
        return [
            'order_position' => $order,
            'ticket_rule_id' => (int) $version->ticket_rule_id,
            'rule_version_id' => (int) $version->id,
            'definition_checksum' => (string) $version->definition_checksum,
            'trigger_relevant' => false,
            'status' => 'unmatched',
            'selected_branch' => null,
            'stop_requested' => false,
            'stop_applied' => false,
            'condition_evidence' => null,
            'actions' => [],
            'actions_omitted_count' => 0,
        ];
    }

    /** @param list<int> $tagIds */
    private function plannedState(Ticket $ticket, array $tagIds): array
    {
        return [
            'ticket_type_id' => $ticket->ticket_type_id !== null ? (int) $ticket->ticket_type_id : null,
            'type' => $this->sanitizer->value('type', $ticket->type),
            'queue_id' => $ticket->queue_id !== null ? (int) $ticket->queue_id : null,
            'priority_id' => $ticket->priority_id !== null ? (int) $ticket->priority_id : null,
            'sla_id' => $ticket->sla_id !== null ? (int) $ticket->sla_id : null,
            'category_id' => $ticket->category_id !== null ? (int) $ticket->category_id : null,
            'tag_ids' => array_values(array_map('intval', $tagIds)),
        ];
    }
}
