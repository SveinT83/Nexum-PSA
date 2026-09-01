<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Support\Str;

/**
 * Builds the bounded, value-free plan shown before a destructive full rerun.
 *
 * The signed receipt binds the complete preview; this presenter only exposes
 * an allowlisted operational projection for human review.
 */
final class TicketRuleFullRerunPreviewPresenter
{
    private const MAX_RULES = 50;

    private const MAX_ACTIONS = 100;

    private const MAX_COLLISIONS = 50;

    private const MAX_LOOP_BLOCKS = 50;

    /** @param array<string, mixed> $preview @return array<string, mixed> */
    public function present(array $preview): array
    {
        $sourceRules = array_values(array_filter((array) ($preview['rules'] ?? []), 'is_array'));
        $rules = [];
        $knownActions = 0;
        $displayedActions = 0;

        foreach ($sourceRules as $ruleIndex => $rule) {
            $sourceActions = array_values(array_filter((array) ($rule['actions'] ?? []), 'is_array'));
            $knownActions += count($sourceActions);
            if ($ruleIndex >= self::MAX_RULES) {
                continue;
            }

            $actions = [];
            foreach ($sourceActions as $action) {
                if ($displayedActions >= self::MAX_ACTIONS) {
                    continue;
                }
                $actions[] = $this->action($action);
                $displayedActions++;
            }

            $branch = $rule['selected_branch'] ?? null;
            $rules[] = [
                'event_sequence' => max(0, (int) ($rule['event_sequence'] ?? 0)),
                'event_key' => $this->identifier($rule['event_key'] ?? 'unknown'),
                'order_position' => max(0, (int) ($rule['order_position'] ?? 0)),
                'ticket_rule_id' => max(0, (int) ($rule['ticket_rule_id'] ?? 0)),
                'rule_version_id' => max(0, (int) ($rule['rule_version_id'] ?? 0)),
                'status' => $this->status($rule['status'] ?? null),
                'selected_branch' => in_array($branch, ['then', 'else'], true) ? $branch : null,
                'reason_code' => $this->optionalIdentifier($rule['reason_code'] ?? null),
                'loop_blocked' => (bool) ($rule['loop_blocked'] ?? false),
                'actions' => $actions,
                'actions_omitted_count' => max(0, count($sourceActions) - count($actions)),
            ];
        }

        $ruleTotal = count($sourceRules) + max(0, (int) ($preview['rules_omitted_count'] ?? 0));
        $actionTotal = max($knownActions, (int) data_get($preview, 'counters.actions', 0));
        $collisions = $this->collisions($preview);
        $loopBlocks = $this->loopBlocks($preview);

        return [
            'planned_rules' => $rules,
            'planned_rules_omitted_count' => max(0, $ruleTotal - count($rules)),
            'planned_action_row_count' => $actionTotal,
            'planned_action_displayed_count' => $displayedActions,
            'planned_action_omitted_count' => max(0, $actionTotal - $displayedActions),
            'planned_collisions' => $collisions['rows'],
            'planned_collisions_omitted_count' => $collisions['omitted'],
            'planned_loop_blocks' => $loopBlocks['rows'],
            'planned_loop_blocks_omitted_count' => $loopBlocks['omitted'],
        ];
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function action(array $source): array
    {
        $type = $this->identifier(data_get($source, 'action.type', 'unknown'));
        $status = $this->status($source['status'] ?? null);
        $labels = [];
        foreach (array_keys((array) ($source['changes'] ?? [])) as $field) {
            if (is_string($field)) {
                $labels[$this->fieldLabel($field)] = true;
            }
        }
        $labels = array_keys($labels);
        $visible = array_slice($labels, 0, 8);
        $summary = array_map(
            static fn (string $label): string => $label.' would change.',
            $visible,
        );
        $afterCommit = $this->optionalIdentifier($source['after_commit_type'] ?? null);
        if ($summary === []) {
            $summary[] = $this->fallbackSummary($type, $status, $afterCommit);
        }

        return [
            'position' => max(0, (int) ($source['position'] ?? 0)),
            'type' => $type,
            'status' => $status,
            'target' => $this->target($type, $labels, $afterCommit),
            'change_summary' => $summary,
            'change_summary_omitted_count' => max(0, count($labels) - count($visible)),
            'after_commit_type' => $afterCommit,
            'reason_code' => $this->optionalIdentifier($source['reason_code'] ?? null),
        ];
    }

    /** @return array{rows: list<array<string, mixed>>, omitted: int} */
    private function collisions(array $preview): array
    {
        $source = array_values(array_filter((array) ($preview['collisions'] ?? []), 'is_array'));
        $rows = [];
        foreach (array_slice($source, 0, self::MAX_COLLISIONS) as $collision) {
            $target = is_string($collision['target'] ?? null)
                ? preg_replace('/\\Afield:/', '', $collision['target'])
                : 'restricted';
            $rows[] = [
                'target' => $this->fieldLabel(is_string($target) ? $target : 'restricted'),
                'previous_writer' => $this->writer($collision['previous_writer'] ?? null),
                'new_writer' => $this->writer($collision['new_writer'] ?? null),
                'resolution' => $this->identifier($collision['resolution'] ?? 'unknown'),
            ];
        }
        $total = count($source) + max(0, (int) ($preview['collisions_omitted_count'] ?? 0));

        return ['rows' => $rows, 'omitted' => max(0, $total - count($rows))];
    }

    /** @return array{rows: list<array<string, mixed>>, omitted: int} */
    private function loopBlocks(array $preview): array
    {
        $source = array_values(array_filter(
            (array) data_get($preview, 'loop_risk.risks', []),
            'is_array',
        ));
        foreach ((array) ($preview['events'] ?? []) as $event) {
            if (is_array($event) && ($event['status'] ?? null) === 'loop_blocked') {
                $source[] = $event;
            }
        }
        foreach ((array) ($preview['rules'] ?? []) as $rule) {
            if (is_array($rule)
                && (($rule['status'] ?? null) === 'loop_blocked'
                    || (bool) ($rule['loop_blocked'] ?? false))) {
                $source[] = $rule;
            }
        }

        $unique = [];
        foreach ($source as $risk) {
            $row = [
                'reason_code' => $this->identifier($risk['reason_code'] ?? 'loop_blocked'),
                'event_key' => $this->identifier($risk['event_key'] ?? 'unknown'),
                'event_sequence' => max(0, (int) ($risk['event_sequence'] ?? $risk['sequence'] ?? 0)),
                'chain_depth' => max(0, (int) ($risk['chain_depth'] ?? 0)),
                'rule_order_position' => max(0, (int) ($risk['rule_order_position'] ?? $risk['order_position'] ?? 0)),
                'action_position' => array_key_exists('action_position', $risk)
                    ? max(0, (int) $risk['action_position'])
                    : null,
            ];
            $unique[TicketRuleStableJson::checksum($row)] = $row;
        }
        $known = array_values($unique);
        $rows = array_slice($known, 0, self::MAX_LOOP_BLOCKS);
        $total = max(
            count($known) + max(0, (int) data_get($preview, 'loop_risk.risks_omitted_count', 0)),
            (int) data_get($preview, 'counters.loop_blocks', 0),
        );

        return ['rows' => $rows, 'omitted' => max(0, $total - count($rows))];
    }

    /** @return array<string, int> */
    private function writer(mixed $source): array
    {
        $source = is_array($source) ? $source : [];

        return [
            'event_sequence' => max(0, (int) ($source['event_sequence'] ?? 0)),
            'ticket_rule_id' => max(0, (int) ($source['ticket_rule_id'] ?? 0)),
            'rule_version_id' => max(0, (int) ($source['rule_version_id'] ?? 0)),
            'action_position' => max(0, (int) ($source['action_position'] ?? 0)),
        ];
    }

    /** @param list<string> $labels */
    private function target(string $type, array $labels, ?string $afterCommit): string
    {
        if ($labels !== []) {
            $visible = array_slice($labels, 0, 3);

            return implode(', ', $visible)
                .(count($labels) > count($visible) ? ' +'.(count($labels) - count($visible)).' more' : '');
        }

        return match ($type) {
            'set_queue' => 'Ticket Queue',
            'assign_owner', 'unassign_owner', 'rerun_assignment' => 'Ticket assignment',
            'add_tags', 'remove_tags' => 'Ticket tags',
            'set_custom_field', 'clear_custom_field' => 'Custom Field (definition and value redacted)',
            'add_internal_note' => 'Ticket internal activity (content redacted)',
            'emit_signal' => 'Internal signal (payload redacted)',
            'select_workflow', 'transition_workflow', 'switch_workflow',
            'pause_workflow_automation', 'resume_workflow_automation' => 'Ticket Workflow',
            default => $afterCommit !== null
                ? 'Queued delivery (recipient and content redacted)'
                : 'Ticket',
        };
    }

    private function fallbackSummary(string $type, string $status, ?string $afterCommit): string
    {
        if ($status === 'no_change') {
            return 'No value change is planned.';
        }

        return match ($type) {
            'set_custom_field', 'clear_custom_field' => 'A Custom Field value would change; the definition and value are redacted.',
            'add_internal_note' => 'An internal note would be created; its content is redacted.',
            'emit_signal' => 'An internal signal would be emitted; its payload is redacted.',
            default => $afterCommit !== null
                ? 'An external delivery would be queued; recipient and content are redacted.'
                : 'No field-level value summary is available for this action.',
        };
    }

    private function fieldLabel(string $field): string
    {
        if (str_starts_with($field, 'custom_field.')
            || str_starts_with($field, 'custom_field_')) {
            return 'Custom Field (value redacted)';
        }

        return match ($field) {
            'subject' => 'Subject (value redacted)',
            'description', 'body', 'content' => 'Content (value redacted)',
            default => preg_match('/\\A[A-Za-z][A-Za-z0-9_.-]{0,79}\\z/', $field) === 1
                ? Str::headline(str_replace('.', ' ', $field))
                : 'Restricted target',
        };
    }

    private function status(mixed $status): string
    {
        return is_string($status) && in_array($status, [
            'planned', 'queued', 'succeeded', 'would_change', 'no_change',
            'failed', 'not_run', 'rolled_back', 'loop_blocked',
        ], true) ? $status : 'unknown';
    }

    private function optionalIdentifier(mixed $value): ?string
    {
        return is_string($value) ? $this->identifier($value) : null;
    }

    private function identifier(mixed $value): string
    {
        return is_string($value)
            && preg_match('/\\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,119}\\z/', $value) === 1
                ? $value
                : 'unknown';
    }
}
