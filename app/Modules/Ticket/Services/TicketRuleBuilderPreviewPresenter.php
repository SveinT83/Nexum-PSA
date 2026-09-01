<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Support\Str;

/**
 * Converts exact no-write preview evidence into a bounded, value-free Livewire
 * view model. Public component state must never contain raw Ticket or Custom
 * Field values even when a future executor accidentally returns extra fields.
 */
final class TicketRuleBuilderPreviewPresenter
{
    private const MAX_GROUPS = 20;

    private const MAX_ROWS = 50;

    private const MAX_EVENTS = 50;

    private const MAX_POLICY_OUTCOMES = 50;

    public function __construct(
        private readonly TicketRuleFullRerunPreviewPresenter $plans,
    ) {}

    /** @param array<string, mixed> $preview @return array<string, mixed> */
    public function present(array $preview): array
    {
        $draftRule = [
            'event_sequence' => 0,
            'event_key' => $preview['trigger'] ?? 'unknown',
            'order_position' => 0,
            'ticket_rule_id' => 0,
            'rule_version_id' => 0,
            'status' => $preview['terminal_status'] ?? 'unknown',
            'selected_branch' => $preview['selected_branch'] ?? null,
            'reason_code' => $preview['reason_code'] ?? null,
            'actions' => (array) ($preview['actions'] ?? []),
        ];
        $draftPlan = $this->plans->present(['rules' => [$draftRule]]);
        $draftActions = (array) data_get($draftPlan, 'planned_rules.0.actions', []);
        $queueSource = is_array($preview['queue_preview'] ?? null)
            ? $preview['queue_preview']
            : (array_key_exists('rules', $preview) ? $preview : null);
        $queue = is_array($queueSource) ? $this->queue($queueSource) : null;
        $queueScope = $queue !== null
            && ($preview['queue_preview_scope'] ?? null) === 'published_rules_only'
                ? 'published_rules_only'
                : null;
        $policyOutcomes = $this->policyOutcomes($preview, $queueSource);

        return [
            'mode' => $this->identifier($preview['mode'] ?? 'draft_preview'),
            'terminal_status' => $this->status($preview['terminal_status'] ?? null),
            'selected_branch' => in_array($preview['selected_branch'] ?? null, ['then', 'else'], true)
                ? $preview['selected_branch']
                : null,
            'conditions_matched' => (bool) ($preview['conditions_matched'] ?? false),
            'definition_checksum' => $this->checksum($preview['definition_checksum'] ?? null),
            'published_set_checksum' => $this->checksum(
                $preview['published_set_checksum']
                    ?? data_get($queueSource, 'published_set_checksum'),
            ),
            'condition_evidence' => $this->conditionEvidence(
                $preview['condition_evidence'] ?? null,
            ),
            'actions' => $draftActions,
            'actions_omitted_count' => max(
                0,
                count((array) ($preview['actions'] ?? [])) - count($draftActions),
            ),
            'policy_outcomes' => $policyOutcomes['rows'],
            'policy_outcomes_omitted_count' => $policyOutcomes['omitted'],
            'queue_scope' => $queueScope,
            'queue' => $queue,
            'warnings' => $this->warnings($preview, $queue),
        ];
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function queue(array $source): array
    {
        $plan = $this->plans->present($source);
        $sourceRules = array_values(array_filter(
            (array) ($source['rules'] ?? []),
            'is_array',
        ));
        $rules = [];

        foreach ((array) ($plan['planned_rules'] ?? []) as $index => $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $rule['condition_evidence'] = $this->conditionEvidence(
                data_get($sourceRules, $index.'.condition_evidence'),
            );
            $rule['source_label'] = ((int) ($rule['rule_version_id'] ?? 0)) === 0
                ? 'Draft being previewed'
                : 'Published rule';
            $rules[] = $rule;
        }

        $events = [];
        $sourceEvents = array_values(array_filter(
            (array) ($source['events'] ?? []),
            'is_array',
        ));
        foreach (array_slice($sourceEvents, 0, self::MAX_EVENTS) as $event) {
            $events[] = [
                'sequence' => max(0, (int) ($event['sequence'] ?? 0)),
                'event_key' => $this->identifier($event['event_key'] ?? 'unknown'),
                'chain_depth' => max(0, (int) ($event['chain_depth'] ?? 0)),
                'status' => $this->status($event['status'] ?? null),
                'reason_code' => $this->optionalIdentifier(
                    $event['reason_code'] ?? $event['loop_reason_code'] ?? null,
                ),
            ];
        }
        $knownEventCount = count($sourceEvents)
            + max(0, (int) ($source['derived_events_omitted_count'] ?? 0));

        return [
            'terminal_status' => $this->status($source['terminal_status'] ?? null),
            'rules' => $rules,
            'rules_omitted_count' => max(0, (int) ($plan['planned_rules_omitted_count'] ?? 0)),
            'action_count' => max(0, (int) ($plan['planned_action_row_count'] ?? 0)),
            'actions_displayed_count' => max(
                0,
                (int) ($plan['planned_action_displayed_count'] ?? 0),
            ),
            'actions_omitted_count' => max(
                0,
                (int) ($plan['planned_action_omitted_count'] ?? 0),
            ),
            'collisions' => (array) ($plan['planned_collisions'] ?? []),
            'collisions_omitted_count' => max(
                0,
                (int) ($plan['planned_collisions_omitted_count'] ?? 0),
            ),
            'loop_blocks' => (array) ($plan['planned_loop_blocks'] ?? []),
            'loop_blocks_omitted_count' => max(
                0,
                (int) ($plan['planned_loop_blocks_omitted_count'] ?? 0),
            ),
            'events' => $events,
            'events_omitted_count' => max(0, $knownEventCount - count($events)),
            'counters' => [
                'events' => max(0, (int) data_get($source, 'counters.events', 0)),
                'evaluated_rules' => max(
                    0,
                    (int) data_get($source, 'counters.evaluated_rules', 0),
                ),
                'actions' => max(0, (int) data_get($source, 'counters.actions', 0)),
                'loop_blocks' => max(
                    0,
                    (int) data_get($source, 'counters.loop_blocks', 0),
                ),
                'failed_executions' => max(
                    0,
                    (int) data_get($source, 'counters.failed_executions', 0),
                ),
            ],
            'halted' => (bool) ($source['halted'] ?? false),
            'stopped' => (bool) ($source['stopped'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function conditionEvidence(mixed $source): array
    {
        $source = is_array($source) ? $source : [];
        $groups = [];

        foreach (array_slice((array) ($source['groups'] ?? []), 0, self::MAX_GROUPS) as $group) {
            if (! is_array($group)) {
                continue;
            }
            $rows = [];
            $sourceRows = array_values(array_filter(
                (array) ($group['rows'] ?? []),
                'is_array',
            ));
            foreach (array_slice($sourceRows, 0, self::MAX_ROWS) as $row) {
                $field = $row['field'] ?? null;
                $redactCustomFieldValue = is_string($field) && str_starts_with($field, 'custom_field.');
                $rows[] = [
                    'position' => max(0, (int) ($row['position'] ?? 0)),
                    'field' => $this->fieldLabel($field),
                    'operator' => $this->identifier($row['operator'] ?? 'unknown'),
                    'value_type' => $this->identifier($row['value_type'] ?? 'unknown'),
                    'passed' => (bool) ($row['passed'] ?? false),
                    'reason_code' => $this->optionalIdentifier($row['reason_code'] ?? null),
                    'expected' => $redactCustomFieldValue
                        ? 'Custom Field value (redacted)'
                        : $this->valueSummary($row['expected'] ?? null),
                    'actual' => $redactCustomFieldValue
                        ? 'Custom Field value (redacted)'
                        : $this->valueSummary($row['actual'] ?? null),
                ];
            }
            $groups[] = [
                'position' => max(0, (int) ($group['position'] ?? 0)),
                'match' => in_array($group['match'] ?? null, ['ALL', 'ANY'], true)
                    ? $group['match']
                    : 'ALL',
                'passed' => (bool) ($group['passed'] ?? false),
                'rows' => $rows,
                'rows_omitted_count' => max(0, count($sourceRows) - count($rows)),
            ];
        }

        return [
            'valid' => (bool) ($source['valid'] ?? false),
            'passed' => (bool) ($source['passed'] ?? false),
            'mode' => in_array($source['mode'] ?? null, ['always', 'grouped'], true)
                ? $source['mode']
                : 'unknown',
            'root_match' => in_array($source['root_match'] ?? null, ['ALL', 'ANY'], true)
                ? $source['root_match']
                : 'ALL',
            'reason_code' => $this->optionalIdentifier($source['reason_code'] ?? null),
            'groups' => $groups,
            'groups_omitted_count' => max(
                0,
                count((array) ($source['groups'] ?? [])) - count($groups),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $queue
     * @return array{rows: list<array<string, mixed>>, omitted: int}
     */
    private function policyOutcomes(array $preview, ?array $queue): array
    {
        $rows = [];
        $omitted = 0;
        $sources = [[
            'scope' => 'Draft',
            'actions' => (array) ($preview['actions'] ?? []),
        ]];

        foreach ((array) ($queue['rules'] ?? []) as $rule) {
            if (is_array($rule)) {
                $sources[] = [
                    'scope' => ((int) ($rule['rule_version_id'] ?? 0)) === 0
                        ? 'Draft queue step'
                        : 'Published queue step',
                    'actions' => (array) ($rule['actions'] ?? []),
                ];
            }
        }

        foreach ($sources as $source) {
            foreach ($source['actions'] as $action) {
                if (! is_array($action)) {
                    continue;
                }
                $reason = $this->optionalIdentifier($action['reason_code'] ?? null);
                $authorization = $action['authorization'] ?? null;
                $denied = is_array($authorization)
                    && array_key_exists('allowed', $authorization)
                    && ! (bool) $authorization['allowed'];
                if ($reason === null && ! $denied) {
                    continue;
                }
                if (count($rows) >= self::MAX_POLICY_OUTCOMES) {
                    $omitted++;

                    continue;
                }
                $rows[] = [
                    'scope' => $source['scope'],
                    'position' => max(0, (int) ($action['position'] ?? 0)),
                    'action_type' => $this->identifier(data_get($action, 'action.type', 'unknown')),
                    'status' => $this->status($action['status'] ?? null),
                    'reason_code' => $reason ?? 'authorization_denied',
                ];
            }
        }

        return ['rows' => $rows, 'omitted' => $omitted];
    }

    /** @param array<string, mixed>|null $queue @return list<array<string, string>> */
    private function warnings(array $preview, ?array $queue): array
    {
        $warnings = [];
        if ($this->status($preview['terminal_status'] ?? null) === 'failed') {
            $warnings[] = [
                'code' => 'draft_preview_failed',
                'message' => 'The draft branch could not be fully planned.',
            ];
        }
        if ($queue !== null && ($queue['halted'] ?? false)) {
            $warnings[] = [
                'code' => 'queue_halted',
                'message' => 'The exact queue plan halted at a loop or budget boundary.',
            ];
        }
        if ($queue !== null && (int) data_get($queue, 'counters.loop_blocks', 0) > 0) {
            $warnings[] = [
                'code' => 'loop_blocked',
                'message' => 'One or more downstream events were blocked by loop protection.',
            ];
        }
        if ($queue !== null && (int) data_get($queue, 'counters.failed_executions', 0) > 0) {
            $warnings[] = [
                'code' => 'planned_execution_failed',
                'message' => 'One or more planned rule executions failed validation or policy checks.',
            ];
        }

        $omissions = max(0, (int) data_get($queue, 'rules_omitted_count', 0))
            + max(0, (int) data_get($queue, 'actions_omitted_count', 0))
            + max(0, (int) data_get($queue, 'collisions_omitted_count', 0))
            + max(0, (int) data_get($queue, 'loop_blocks_omitted_count', 0))
            + max(0, (int) data_get($queue, 'events_omitted_count', 0));
        if ($omissions > 0) {
            $warnings[] = [
                'code' => 'display_rows_omitted',
                'message' => $omissions.' bounded preview rows are omitted below.',
            ];
        }

        return $warnings;
    }

    private function fieldLabel(mixed $field): string
    {
        if (! is_string($field)
            || preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,79}\z/', $field) !== 1) {
            return 'Restricted field';
        }
        if (str_starts_with($field, 'custom_field.')) {
            return 'Custom Field value (redacted)';
        }

        return match ($field) {
            'subject' => 'Subject (value redacted)',
            'description', 'body', 'content' => 'Content (value redacted)',
            default => Str::headline(str_replace('.', ' ', $field)),
        };
    }

    private function valueSummary(mixed $value): string
    {
        if ($value === null) {
            return 'Not set';
        }
        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }
        if (is_int($value) || is_float($value)) {
            return 'Numeric value';
        }
        if (is_array($value)) {
            $type = $value['type'] ?? null;
            if ($type === 'text') {
                return 'Redacted text ('.max(0, (int) ($value['length'] ?? 0))
                    .' characters; fingerprint '.$this->shortChecksum($value['sha256'] ?? null).')';
            }
            if ($type === 'structured') {
                return 'Redacted structured value ('.max(0, (int) ($value['count'] ?? 0))
                    .' items; fingerprint '.$this->shortChecksum($value['sha256'] ?? null).')';
            }

            return 'Redacted structured value ('.count($value).' items; fingerprint '
                .$this->shortChecksum(TicketRuleStableJson::checksum($value)).')';
        }

        $text = (string) $value;

        return 'Redacted text ('.mb_strlen($text).' characters; fingerprint '
            .$this->shortChecksum(hash('sha256', $text)).')';
    }

    private function status(mixed $value): string
    {
        return is_string($value) && in_array($value, [
            'planned', 'queued', 'succeeded', 'would_change', 'no_change',
            'failed', 'not_run', 'rolled_back', 'loop_blocked', 'completed',
            'stopped', 'processing',
        ], true) ? $value : 'unknown';
    }

    private function checksum(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1
            ? $value
            : null;
    }

    private function shortChecksum(mixed $value): string
    {
        return $this->checksum($value) !== null ? substr((string) $value, 0, 12) : 'unavailable';
    }

    private function optionalIdentifier(mixed $value): ?string
    {
        return is_string($value) ? $this->identifier($value) : null;
    }

    private function identifier(mixed $value): string
    {
        return is_string($value)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,119}\z/', $value) === 1
                ? $value
                : 'unknown';
    }
}
