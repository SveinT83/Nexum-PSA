<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleActionResult;
use App\Modules\Ticket\Models\TicketRuleAfterCommitResult;
use App\Modules\Ticket\Models\TicketRuleEvent;
use App\Modules\Ticket\Models\TicketRuleExecution;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleFieldRegistry;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Support\Str;

/**
 * Project immutable rule evidence through an explicit operator-safe allowlist.
 *
 * No caller receives raw event/action JSON. Custom Field values remain reduced
 * to definition/type/presence/fingerprint evidence even for authorized admins.
 */
final class TicketRuleExecutionPresenter
{
    /** @var array<string, string> */
    private array $fieldLabels;

    public function __construct(
        private readonly TicketRuleActionProviderRegistry $actions,
        private readonly TicketRuleAuditSanitizer $sanitizer,
        private readonly TicketCustomFieldTargetValidator $customFields,
        private readonly TicketRuleEvidenceAccess $evidenceAccess,
        TicketRuleFieldRegistry $fields,
        private readonly TicketRuleTriggerRegistry $triggers,
    ) {
        $this->fieldLabels = collect($fields->conditionFacts())
            ->mapWithKeys(fn (array $definition, string $key): array => [
                $key => (string) ($definition['label'] ?? Str::headline($key)),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public function runSummary(TicketRuleRun $run, ?User $viewer = null): array
    {
        $restricted = $this->evidenceAccess->runIsRestricted($run, $viewer);
        $ruleNames = $restricted
            ? []
            : $run->executions
                ->map(fn (TicketRuleExecution $execution): string => (string) (
                    $execution->version?->name
                    ?? $execution->rule?->name
                    ?? 'Deleted rule #'.$execution->ticket_rule_id
                ))
                ->filter()
                ->unique()
                ->take(4)
                ->values()
                ->all();
        $status = $restricted ? 'restricted_evidence' : (string) $run->status;

        return [
            'id' => (int) $run->id,
            'ticket_id' => (int) $run->ticket_id,
            'ticket_available' => $run->ticket !== null,
            'ticket_key' => $run->ticket?->ticket_key ?? 'Deleted Ticket #'.$run->ticket_id,
            'root_event' => (string) $run->root_event_key,
            'root_event_label' => $this->eventLabel((string) $run->root_event_key),
            'status' => $status,
            'status_label' => Str::headline($status),
            'status_class' => $this->statusClass($status),
            'mode' => (string) $run->mode,
            'attempt_number' => (int) $run->attempt_number,
            'retry_of_run_id' => $run->retry_of_run_id ? (int) $run->retry_of_run_id : null,
            'restricted_evidence' => $restricted,
            'restricted_message' => $restricted
                ? 'Restricted evidence. One or more Custom Field targets are not visible to your account.'
                : null,
            'rule_names' => $ruleNames,
            'rule_names_omitted' => $restricted
                ? 0
                : max(
                    0,
                    $run->executions->pluck('rule_version_id')->unique()->count() - count($ruleNames),
                ),
            'event_count' => $restricted
                ? null
                : (int) ($run->events_count ?? $run->events->count()),
            'execution_count' => $restricted
                ? null
                : (int) ($run->executions_count ?? $run->executions->count()),
            'action_count' => $restricted
                ? null
                : (int) ($run->action_results_count ?? $run->actionResults->count()),
            'duration_ms' => $restricted || $run->duration_ms === null
                ? null
                : (int) $run->duration_ms,
            'started_at' => $run->started_at,
            'completed_at' => $run->completed_at,
        ];
    }

    /** @return array<string, mixed> */
    public function ruleDetail(TicketRule $rule): array
    {
        return [
            'id' => (int) $rule->id,
            'name' => (string) $rule->name,
            'description' => $rule->description ? Str::limit((string) $rule->description, 1000) : null,
            'lifecycle_status' => (string) $rule->lifecycle_status,
            'compatibility_status' => (string) $rule->compatibility_status,
            'compatibility_reason_code' => $rule->compatibility_reason_code,
            'is_active' => (bool) $rule->is_active,
            'weight' => (int) $rule->weight,
            'stop_processing' => (bool) $rule->stop_processing,
            'published_version_id' => $rule->published_version_id ? (int) $rule->published_version_id : null,
            'published_at' => $rule->published_at,
            'deleted_at' => $rule->deleted_at,
            'version_count' => $rule->versions->count(),
            'execution_count' => (int) ($rule->executions_count ?? 0),
            'legacy_log_count' => (int) ($rule->logs_count ?? 0),
            'versions' => $rule->versions
                ->sortByDesc('version_number')
                ->map(fn (TicketRuleVersion $version): array => $this->versionSummary($version))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function runDetail(TicketRuleRun $run, ?User $viewer = null): array
    {
        $summary = $this->runSummary($run, $viewer);
        $restricted = (bool) $summary['restricted_evidence'];
        $labels = $this->userLabels([
            $run->initiator_id,
            $run->automation_actor_id,
        ]);

        return [
            'summary' => $summary,
            'restricted_evidence' => $restricted,
            'restricted_message' => $summary['restricted_message'],
            'source_channel' => $run->source_channel,
            'source_action' => (string) $run->source_action,
            'initiator' => $this->userLabel($run->initiator_id, $run->initiator_type, $labels),
            'automation_actor' => $this->userLabel($run->automation_actor_id, 'system actor', $labels),
            'correlation_uuid' => (string) $run->correlation_uuid,
            'causation_uuid' => $run->causation_uuid,
            'authority_generation' => (int) $run->authority_generation,
            'published_version_ids' => $restricted
                ? []
                : collect((array) $run->published_version_ids)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter()
                    ->values()
                    ->all(),
            'termination_reason' => $restricted ? null : $run->termination_reason,
            'counters' => $restricted
                ? []
                : $this->namedIntegerMap((array) $run->counters_json),
            'safe_summary' => $restricted
                ? []
                : $this->namedScalarMap((array) $run->safe_summary_json),
            'events' => $restricted
                ? []
                : $run->events
                    ->sortBy('sequence')
                    ->map(fn (TicketRuleEvent $event): array => $this->event($event, $viewer))
                    ->values()
                    ->all(),
            'executions' => $restricted
                ? []
                : $run->executions
                    ->sortBy([['event_id', 'asc'], ['order_position', 'asc'], ['id', 'asc']])
                    ->map(fn (TicketRuleExecution $execution): array => $this->execution($execution, $viewer))
                    ->values()
                    ->all(),
            'after_commit_results' => $restricted
                ? []
                : $run->afterCommitResults
                    ->sortBy('id')
                    ->map(fn (TicketRuleAfterCommitResult $result): array => $this->delivery($result))
                    ->values()
                    ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function versionSummary(TicketRuleVersion $version): array
    {
        $definition = is_array($version->definition_json) ? $version->definition_json : [];
        $then = array_values(array_filter((array) ($definition['then_actions'] ?? []), 'is_array'));
        $else = array_values(array_filter((array) ($definition['else_actions'] ?? []), 'is_array'));
        $groups = (array) data_get($definition, 'conditions.groups', []);
        $conditionCount = collect($groups)->sum(fn (mixed $group): int => is_array($group)
            ? count((array) ($group['conditions'] ?? []))
            : 0);

        return [
            'id' => (int) $version->id,
            'version_number' => (int) $version->version_number,
            'status' => (string) $version->status,
            'schema_version' => (int) $version->definition_schema_version,
            'trigger' => (string) $version->trigger_key,
            'trigger_label' => $this->eventLabel((string) $version->trigger_key),
            'condition_count' => $conditionCount,
            'then_actions' => $this->actionLabels($then),
            'else_actions' => $this->actionLabels($else),
            'stop_processing' => (bool) $version->stop_processing,
            'definition_checksum' => (string) $version->definition_checksum,
            'published_by' => $version->published_by ? (int) $version->published_by : null,
            'published_at' => $version->published_at,
            'provenance' => (string) $version->provenance,
        ];
    }

    /** @return array<string, mixed> */
    private function event(TicketRuleEvent $event, ?User $viewer): array
    {
        return [
            'id' => (int) $event->id,
            'sequence' => (int) $event->sequence,
            'event_key' => (string) $event->event_key,
            'event_label' => $this->eventLabel((string) $event->event_key),
            'status' => (string) $event->status,
            'status_label' => Str::headline((string) $event->status),
            'loop_reason_code' => $event->loop_reason_code,
            'loop_reason_label' => $event->loop_reason_code
                ? Str::headline((string) $event->loop_reason_code)
                : null,
            'blocked_event_fingerprint' => $event->blocked_event_fingerprint,
            'status_class' => $this->statusClass((string) $event->status),
            'source_channel' => $event->source_channel,
            'source_action' => (string) $event->source_action,
            'changed_fields' => collect((array) $event->changed_fields_json)
                ->filter(fn (mixed $field): bool => is_string($field))
                ->filter(fn (string $field): bool => $this->fieldVisible($field, $viewer))
                ->map(fn (string $field): string => $this->fieldLabel($field))
                ->take(100)
                ->values()
                ->all(),
            'before' => $this->safeEvidenceMap((array) $event->before_json, $viewer),
            'after' => $this->safeEvidenceMap((array) $event->after_json, $viewer),
            'correlation_uuid' => (string) $event->correlation_uuid,
            'causation_uuid' => $event->causation_uuid,
            'chain_depth' => (int) $event->chain_depth,
            'parent_event_id' => $event->parent_event_id ? (int) $event->parent_event_id : null,
            'parent_action_result_id' => $event->parent_action_result_id ? (int) $event->parent_action_result_id : null,
            'occurred_at' => $event->occurred_at,
            'processed_at' => $event->processed_at,
        ];
    }

    /** @return array<string, mixed> */
    private function execution(TicketRuleExecution $execution, ?User $viewer): array
    {
        return [
            'id' => (int) $execution->id,
            'event_id' => (int) $execution->event_id,
            'rule_id' => (int) $execution->ticket_rule_id,
            'rule_name' => (string) (
                $execution->version?->name
                ?? $execution->rule?->name
                ?? 'Deleted rule #'.$execution->ticket_rule_id
            ),
            'rule_available' => $execution->rule !== null,
            'rule_version_id' => (int) $execution->rule_version_id,
            'version_number' => $execution->version?->version_number,
            'order_position' => (int) $execution->order_position,
            'attempt_number' => (int) $execution->attempt_number,
            'retry_of_id' => $execution->retry_of_id ? (int) $execution->retry_of_id : null,
            'status' => (string) $execution->status,
            'status_label' => Str::headline((string) $execution->status),
            'status_class' => $this->statusClass((string) $execution->status),
            'trigger_relevant' => (bool) $execution->trigger_relevant,
            'conditions_matched' => (bool) $execution->conditions_matched,
            'selected_branch' => $execution->selected_branch,
            'condition_evidence' => $this->conditionEvidence(
                $execution->condition_evidence_json,
                $viewer,
            ),
            'change_summary' => $this->namedScalarMap($this->visibleNamedValues(
                (array) $execution->change_summary_json,
                $viewer,
            )),
            'stop_requested' => (bool) $execution->stop_requested,
            'stop_applied' => (bool) $execution->stop_applied,
            'failure_code' => $execution->failure_code,
            'failure_message' => $this->safeFailure($execution->failure_message),
            'duration_ms' => $execution->duration_ms === null ? null : (int) $execution->duration_ms,
            'action_attempts_omitted_count' => (int) ($execution->action_attempts_omitted_count ?? 0),
            'actions' => $execution->actionResults
                ->sortBy([['position', 'asc'], ['attempt_number', 'asc'], ['id', 'asc']])
                ->map(fn (TicketRuleActionResult $result): array => $this->action($result, $viewer))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function action(TicketRuleActionResult $result, ?User $viewer): array
    {
        $snapshot = is_array($result->action_snapshot_json) ? $result->action_snapshot_json : [];
        $customFieldVisible = $this->customFieldActionVisible($result, $snapshot, $viewer);

        return [
            'id' => (int) $result->id,
            'position' => (int) $result->position,
            'attempt_number' => (int) $result->attempt_number,
            'retry_of_id' => $result->retry_of_id ? (int) $result->retry_of_id : null,
            'action_type' => (string) $result->action_type,
            'action_label' => $this->actionLabel((string) $result->action_type),
            'status' => (string) $result->status,
            'status_label' => Str::headline((string) $result->status),
            'status_class' => $this->statusClass((string) $result->status),
            'input' => $customFieldVisible
                ? $this->safeActionInput($snapshot['input'] ?? $snapshot, (string) $result->action_type)
                : [],
            'changes' => $this->safeEvidenceMap((array) $result->change_json, $viewer),
            'authorization' => $this->safeAuthorization((array) $result->authorization_json),
            'failure_code' => $result->failure_code,
            'failure_message' => $this->safeFailure($result->failure_message),
            'duration_ms' => $result->duration_ms === null ? null : (int) $result->duration_ms,
            'started_at' => $result->started_at,
            'completed_at' => $result->completed_at,
        ];
    }

    /** @return array<string, mixed> */
    private function delivery(TicketRuleAfterCommitResult $result): array
    {
        return [
            'id' => (int) $result->id,
            'action_result_id' => (int) $result->action_result_id,
            'delivery_type' => (string) $result->delivery_type,
            'status' => (string) $result->status,
            'status_label' => Str::headline((string) $result->status),
            'status_class' => $this->statusClass((string) $result->status),
            'attempt_number' => (int) $result->attempt_number,
            'retry_of_id' => $result->retry_of_id ? (int) $result->retry_of_id : null,
            'attempt_count' => (int) $result->attempt_count,
            'external_reference_fingerprint' => $result->external_reference_fingerprint,
            'failure_code' => $result->failure_code,
            'failure_message' => $this->safeFailure($result->failure_message),
            'queued_at' => $result->queued_at,
            'completed_at' => $result->completed_at,
        ];
    }

    /** @return array<string, mixed>|null */
    private function conditionEvidence(mixed $evidence, ?User $viewer): ?array
    {
        if (! is_array($evidence)) {
            return null;
        }

        $groups = [];
        foreach (array_slice((array) ($evidence['groups'] ?? []), 0, 20) as $group) {
            if (! is_array($group)) {
                continue;
            }

            $rows = [];
            foreach (array_slice((array) ($group['rows'] ?? $group['conditions'] ?? []), 0, 50) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $field = is_string($row['field'] ?? null) ? $row['field'] : 'unknown';
                $definitionId = data_get($row, 'target.definition_id');
                if (($definitionId !== null && ! $this->customFieldVisible($definitionId, $viewer))
                    || ! $this->fieldVisible($field, $viewer)) {
                    continue;
                }
                $rows[] = [
                    'position' => (int) ($row['position'] ?? count($rows)),
                    'field' => $field,
                    'field_label' => $this->fieldLabel($field),
                    'operator' => is_string($row['operator'] ?? null) ? $row['operator'] : 'unknown',
                    'passed' => (bool) ($row['passed'] ?? false),
                    'expected' => $this->describeValue($this->safePresentedValue($field, $row['expected'] ?? null)),
                    'actual' => $this->describeValue($this->safePresentedValue($field, $row['actual'] ?? null)),
                    'reason_code' => is_string($row['reason_code'] ?? null) ? $row['reason_code'] : null,
                ];
            }

            $groups[] = [
                'position' => (int) ($group['position'] ?? count($groups)),
                'match' => in_array($group['match'] ?? null, ['ALL', 'ANY'], true) ? $group['match'] : 'ALL',
                'passed' => (bool) ($group['passed'] ?? false),
                'rows' => $rows,
            ];
        }

        return [
            'valid' => (bool) ($evidence['valid'] ?? false),
            'passed' => (bool) ($evidence['passed'] ?? false),
            'mode' => in_array($evidence['mode'] ?? null, ['always', 'grouped'], true) ? $evidence['mode'] : null,
            'root_match' => in_array($evidence['root_match'] ?? null, ['ALL', 'ANY'], true) ? $evidence['root_match'] : null,
            'reason_code' => is_string($evidence['reason_code'] ?? null) ? $evidence['reason_code'] : null,
            'groups' => $groups,
        ];
    }

    /** @return list<array{key: string, label: string, value: string}> */
    private function safeEvidenceMap(array $values, ?User $viewer): array
    {
        $safe = [];

        foreach (array_slice($values, 0, 100, true) as $key => $value) {
            $key = (string) $key;
            if (! $this->safeEvidenceKey($key)) {
                continue;
            }
            if (! $this->evidenceEntryVisible($key, $value, $values, $viewer)) {
                continue;
            }

            $safe[] = [
                'key' => $key,
                'label' => $this->fieldLabel($key),
                'value' => $this->describeValue($this->safePresentedValue($key, $value)),
            ];
        }

        return $safe;
    }

    private function safeEvidenceKey(string $key): bool
    {
        return array_key_exists($key, $this->fieldLabels)
            || in_array($key, [
                'ticket_id', 'ticket_key', 'message_id', 'message_type', 'message_visibility',
                'workflow_operation', 'workflow_id', 'workflow_version_id', 'workflow_state_key',
                'rule_workflow_paused_at', 'rule_workflow_paused_by', 'rule_workflow_pause_reason',
                'tag_ids', 'added_tag_ids', 'removed_tag_ids', 'assignment_change',
                'custom_field_definition_id', 'custom_field_type', 'before_present', 'after_present',
                'value_fingerprint', 'before_fingerprint', 'after_fingerprint',
            ], true)
            || str_starts_with($key, 'custom_field.');
    }

    /**
     * Custom Field audit fragments are visible only when the current operator
     * may view every definition the fragment identifies.
     *
     * @param  array<string, mixed>  $values
     */
    private function evidenceEntryVisible(
        string $key,
        mixed $value,
        array $values,
        ?User $viewer,
    ): bool {
        $definitionId = $this->definitionIdFromField($key);
        if ($definitionId !== null) {
            return $this->customFieldVisible($definitionId, $viewer);
        }

        if ($key === 'custom_field_definition_id') {
            return $this->customFieldVisible($value, $viewer);
        }

        if (! in_array($key, [
            'custom_field_type', 'before_present', 'after_present',
            'value_fingerprint', 'before_fingerprint', 'after_fingerprint',
        ], true)) {
            return true;
        }

        $definitionIds = $this->definitionIdsInEvidence($values);

        return count($definitionIds) === 1
            && $this->customFieldVisible($definitionIds[0], $viewer);
    }

    /** @param array<string, mixed> $values @return list<int> */
    private function definitionIdsInEvidence(array $values): array
    {
        $ids = [];
        foreach ($values as $key => $value) {
            $definitionId = $this->definitionIdFromField((string) $key);
            if ($definitionId !== null) {
                $ids[] = $definitionId;
            }
            if ((string) $key === 'custom_field_definition_id' && is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function visibleNamedValues(array $values, ?User $viewer): array
    {
        return collect($values)
            ->filter(fn (mixed $value, mixed $key): bool => $this->evidenceEntryVisible(
                (string) $key,
                $value,
                $values,
                $viewer,
            ))
            ->all();
    }

    /** @param array<string, mixed> $snapshot */
    private function customFieldActionVisible(
        TicketRuleActionResult $result,
        array $snapshot,
        ?User $viewer,
    ): bool {
        if (! in_array($result->action_type, [
            TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
            TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD,
        ], true)) {
            return true;
        }

        return $this->customFieldVisible(
            data_get($snapshot, 'input.target.definition_id'),
            $viewer,
        );
    }

    private function fieldVisible(string $field, ?User $viewer): bool
    {
        $definitionId = $this->definitionIdFromField($field);

        return $definitionId === null || $this->customFieldVisible($definitionId, $viewer);
    }

    private function customFieldVisible(mixed $definitionId, ?User $viewer): bool
    {
        return $viewer !== null
            && $this->customFields->canViewDefinitionId($definitionId, $viewer);
    }

    private function definitionIdFromField(string $field): ?int
    {
        return preg_match('/\Acustom_field\.(\d+)\z/', $field, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    /** @return list<array{key: string, value: string}> */
    private function safeActionInput(mixed $input, string $actionType): array
    {
        if (! is_array($input)) {
            return [['key' => 'Input', 'value' => $this->describeValue($this->safePresentedValue('input', $input))]];
        }

        $items = [];
        foreach (array_slice($input, 0, 50, true) as $key => $value) {
            $key = (string) $key;

            if ($key === 'fields' && is_array($value)) {
                foreach (array_slice($value, 0, 50, true) as $field => $fieldValue) {
                    $items[] = [
                        'key' => $this->fieldLabel((string) $field),
                        'value' => $this->describeValue($this->safePresentedValue((string) $field, $fieldValue)),
                    ];
                }

                continue;
            }

            $items[] = [
                'key' => Str::headline($key),
                'value' => $this->describeValue($this->safePresentedValue(
                    $key,
                    $value,
                    $actionType === TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD && $key === 'value',
                )),
            ];
        }

        return $items;
    }

    /** @return list<array{key: string, value: string}> */
    private function safeAuthorization(array $authorization): array
    {
        $allowed = [
            'allowed', 'permission', 'ticket_action', 'ticket_actions', 'targets_revalidated',
            'capability', 'assignment_decision', 'sla_decision', 'current_state_revalidated',
        ];
        $items = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $authorization)) {
                continue;
            }

            $items[] = [
                'key' => Str::headline($key),
                'value' => $this->describeValue($this->safePresentedValue($key, $authorization[$key])),
            ];
        }

        return $items;
    }

    /** @return list<array{key: string, value: int}> */
    private function namedIntegerMap(array $values): array
    {
        $result = [];
        foreach (array_slice($values, 0, 50, true) as $key => $value) {
            if (is_numeric($value)) {
                $result[] = ['key' => Str::headline((string) $key), 'value' => (int) $value];
            }
        }

        return $result;
    }

    /** @return list<array{key: string, value: string}> */
    private function namedScalarMap(array $values): array
    {
        $result = [];
        foreach (array_slice($values, 0, 50, true) as $key => $value) {
            if (is_scalar($value) || $value === null || $this->isSafeDescriptor($value)) {
                $result[] = [
                    'key' => Str::headline((string) $key),
                    'value' => $this->describeValue($this->safePresentedValue((string) $key, $value)),
                ];
            }
        }

        return $result;
    }

    /**
     * Re-sanitize every scalar at the final presentation boundary. This keeps
     * legacy or malformed stored evidence from becoming an accidental raw-data
     * disclosure even when its outer key is allowlisted.
     */
    private function safePresentedValue(
        string $key,
        mixed $value,
        bool $forceStructured = false,
    ): mixed {
        if ($this->isSafeDescriptor($value)) {
            return $value;
        }

        if ($forceStructured || str_starts_with($key, 'custom_field.')) {
            return $this->sanitizer->value($key, ['value' => $value]);
        }

        if ($key === 'terminal_status') {
            return $this->sanitizer->value('status', $value);
        }

        return $this->sanitizer->value($key, $value);
    }

    private function describeValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return Str::limit($value, 160, '…');
        }

        if ($this->isSafeDescriptor($value)) {
            $measure = isset($value['length']) ? 'length '.(int) $value['length'] : 'items '.(int) ($value['count'] ?? 0);

            return $measure.', SHA-256 '.(string) $value['sha256'];
        }

        if (is_array($value)
            && array_is_list($value)
            && count($value) <= 50
            && collect($value)->every(fn (mixed $item): bool => is_numeric($item))) {
            return collect($value)->map(fn (mixed $item): int => (int) $item)->implode(', ');
        }

        return 'Redacted structured value';
    }

    private function isSafeDescriptor(mixed $value): bool
    {
        if (! is_array($value)
            || ! in_array($value['type'] ?? null, ['text', 'structured'], true)
            || ! is_string($value['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/i', $value['sha256']) !== 1) {
            return false;
        }

        return (isset($value['length']) && is_numeric($value['length']))
            || (isset($value['count']) && is_numeric($value['count']));
    }

    private function safeFailure(mixed $message): ?string
    {
        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        return $this->sanitizer->message(trim($message));
    }

    /** @param list<array<string, mixed>> $actions */
    private function actionLabels(array $actions): array
    {
        return collect($actions)
            ->map(fn (array $action): string => $this->actionLabel((string) ($action['type'] ?? 'unknown')))
            ->take(100)
            ->values()
            ->all();
    }

    private function actionLabel(string $type): string
    {
        return (string) data_get($this->actions->definition($type), 'label', Str::headline($type));
    }

    private function eventLabel(string $key): string
    {
        return (string) data_get($this->triggers->definition($key), 'label', Str::headline($key));
    }

    private function fieldLabel(string $field): string
    {
        if (preg_match('/\Acustom_field\.(\d+)\z/', $field, $matches) === 1) {
            return 'Custom Field #'.$matches[1];
        }

        return $this->fieldLabels[$field] ?? Str::headline($field);
    }

    /** @param list<mixed> $ids @return array<int, string> */
    private function userLabels(array $ids): array
    {
        return User::query()
            ->whereIn('id', collect($ids)->filter(fn (mixed $id): bool => is_numeric($id))->map('intval')->unique())
            ->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();
    }

    /** @param array<int, string> $labels */
    private function userLabel(mixed $id, mixed $type, array $labels): string
    {
        if (! is_numeric($id) || (int) $id < 1) {
            return $type ? Str::headline((string) $type) : 'System / unavailable';
        }

        return $labels[(int) $id] ?? 'Deleted user #'.(int) $id;
    }

    private function statusClass(string $status): string
    {
        return match ($status) {
            'succeeded', 'processed' => 'success',
            'failed', 'unresolved' => 'danger',
            'loop_blocked', 'not_run', 'rolled_back' => 'warning',
            'running', 'queued', 'planned' => 'primary',
            default => 'secondary',
        };
    }
}
