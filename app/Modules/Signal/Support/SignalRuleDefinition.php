<?php

namespace App\Modules\Signal\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SignalRuleDefinition
{
    /** Legacy fields remain valid so existing rules can be evaluated and edited. */
    public const CONDITION_FIELDS = [
        'source_domain',
        'signal_type',
        'severity',
        'status',
        'min_confidence',
        'has_client',
        'has_contact',
        'payload_equals',
        'payload_contains',
    ];

    public const BUILDER_CONDITION_FIELDS = [
        'source_domain' => 'Source domain',
        'signal_type' => 'Signal type',
        'severity' => 'Severity',
        'status' => 'Status',
        'confidence' => 'Confidence',
        'has_client' => 'Has client',
        'has_contact' => 'Has contact',
        'payload' => 'Payload field',
    ];

    public const CONDITION_OPERATORS = [
        'equals' => 'Equals',
        'not_equals' => 'Does not equal',
        'in' => 'Is one of',
        'not_in' => 'Is not one of',
        'contains' => 'Contains',
        'not_contains' => 'Does not contain',
        'greater_or_equal' => 'Greater than or equal',
        'less_or_equal' => 'Less than or equal',
        'greater' => 'Greater than',
        'less' => 'Less than',
        'is_true' => 'Yes',
        'is_false' => 'No',
        'exists' => 'Exists',
        'missing' => 'Is missing',
    ];

    public const ACTION_TYPES = [
        'marketing_suppress_contact_email' => ['label' => 'Suppress contact marketing email', 'required' => []],
        'tag_contact' => ['label' => 'Tag contact', 'required' => ['tag']],
        'tag_client' => ['label' => 'Tag client', 'required' => ['tag']],
        'emit_signal' => ['label' => 'Emit derived signal', 'required' => ['signal_type']],
        'sales_follow_up' => ['label' => 'Create Sales follow-up', 'required' => []],
        'ticket_follow_up' => ['label' => 'Create Ticket follow-up', 'required' => []],
        'task_follow_up' => ['label' => 'Create Task follow-up', 'required' => []],
        'portal_invitation' => ['label' => 'Send portal invitation', 'required' => []],
        'webhook' => ['label' => 'Queue webhook', 'required' => ['url']],
    ];

    public const ACTION_OPTIONAL_FIELDS = [
        'tag', 'signal_type', 'severity', 'confidence', 'summary', 'url', 'title',
        'opportunity_title', 'opportunity_type', 'opportunity_status', 'actor_id', 'creator_id',
        'owner_id', 'assigned_to', 'probability_percent', 'estimated_value_ex_vat', 'activity_type',
        'activity_subject', 'activity_body', 'follow_up_minutes_from_now', 'next_follow_up_type',
        'next_follow_up_note', 'append_to_existing', 'create_if_missing', 'subject', 'description',
        'role', 'email', 'site_id', 'contact_id', 'queue_id', 'ticket_type_id', 'priority_id',
        'category_id', 'impact', 'urgency', 'due_minutes_from_now', 'estimated_minutes',
    ];

    public function decodeAndValidate(?string $conditionsJson, string $actionsJson): array
    {
        $conditions = $this->decodeJson($conditionsJson ?: '{}', 'conditions_json');
        $actions = $this->decodeJson($actionsJson, 'actions_json');

        $this->validateConditions($conditions);
        $this->validateActions($actions);

        return [$conditions, $actions];
    }

    public function buildAndValidate(array $conditionsInput, array $actionsInput): array
    {
        $conditions = array_key_exists('groups', $conditionsInput)
            ? $this->groupedConditionsFromInput($conditionsInput)
            : $this->legacyConditionsFromInput($conditionsInput);
        $actions = $this->actionsFromInput($actionsInput);

        $this->validateConditions($conditions);
        $this->validateActions($actions);

        return [$conditions, $actions];
    }

    /** Convert either supported storage format into builder rows without changing stored rules. */
    public function conditionFormGroups(?array $conditions): array
    {
        $conditions = $conditions ?: [];

        if (array_key_exists('groups', $conditions)) {
            return collect((array) $conditions['groups'])
                ->filter(fn (mixed $group): bool => is_array($group))
                ->map(function (array $group): array {
                    return [
                        'match' => in_array($group['match'] ?? null, ['all', 'any'], true) ? $group['match'] : 'all',
                        'conditions' => collect((array) ($group['conditions'] ?? []))
                            ->filter(fn (mixed $row): bool => is_array($row))
                            ->map(fn (array $row): array => array_merge([
                                'field' => '', 'path' => '', 'operator' => 'equals', 'value' => '',
                            ], $row, [
                                'value' => is_array($row['value'] ?? null)
                                    ? implode("\n", $row['value'])
                                    : ($row['value'] ?? ''),
                            ]))
                            ->values()
                            ->all(),
                    ];
                })
                ->values()
                ->all() ?: [$this->emptyConditionGroup()];
        }

        $rows = [];
        foreach (['source_domain', 'signal_type', 'severity', 'status'] as $field) {
            if (! empty($conditions[$field])) {
                $rows[] = ['field' => $field, 'path' => '', 'operator' => 'in', 'value' => implode("\n", (array) $conditions[$field])];
            }
        }
        if (array_key_exists('min_confidence', $conditions)) {
            $rows[] = ['field' => 'confidence', 'path' => '', 'operator' => 'greater_or_equal', 'value' => $conditions['min_confidence']];
        }
        foreach (['has_client', 'has_contact'] as $field) {
            if (array_key_exists($field, $conditions)) {
                $rows[] = ['field' => $field, 'path' => '', 'operator' => $conditions[$field] ? 'is_true' : 'is_false', 'value' => ''];
            }
        }
        foreach (['payload_equals' => 'equals', 'payload_contains' => 'contains'] as $field => $operator) {
            foreach ((array) ($conditions[$field] ?? []) as $path => $value) {
                $rows[] = ['field' => 'payload', 'path' => $path, 'operator' => $operator, 'value' => $value];
            }
        }

        return [['match' => 'all', 'conditions' => $rows ?: [$this->emptyConditionRow()]]];
    }

    public function rootMatch(?array $conditions): string
    {
        return in_array($conditions['match'] ?? null, ['all', 'any'], true) ? $conditions['match'] : 'all';
    }

    public function actionFormRows(?array $actions, int $minimumRows = 1): array
    {
        $rows = collect($actions ?: [])
            ->map(fn (array $action): array => array_merge(array_fill_keys(['type', ...self::ACTION_OPTIONAL_FIELDS], ''), $action))
            ->values()
            ->all();

        while (count($rows) < $minimumRows) {
            $rows[] = array_fill_keys(['type', ...self::ACTION_OPTIONAL_FIELDS], '');
        }

        return $rows;
    }

    public function emptyConditionGroup(): array
    {
        return ['match' => 'all', 'conditions' => [$this->emptyConditionRow()]];
    }

    public function emptyConditionRow(): array
    {
        return ['field' => '', 'path' => '', 'operator' => 'equals', 'value' => ''];
    }

    private function decodeJson(string $json, string $field): array
    {
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw ValidationException::withMessages([$field => 'Must be a valid JSON object or array.']);
        }

        return $decoded;
    }

    private function validateConditions(array $conditions): void
    {
        if (array_key_exists('groups', $conditions)) {
            $this->validateGroupedConditions($conditions);
            return;
        }

        foreach (array_keys($conditions) as $field) {
            if (! in_array($field, self::CONDITION_FIELDS, true)) {
                throw ValidationException::withMessages(['conditions_json' => "Unknown condition field: {$field}."]);
            }
        }

        if (array_key_exists('min_confidence', $conditions)) {
            $confidence = (int) $conditions['min_confidence'];
            if ($confidence < 0 || $confidence > 100) {
                throw ValidationException::withMessages(['conditions_json' => 'min_confidence must be between 0 and 100.']);
            }
        }

        foreach (['has_client', 'has_contact'] as $field) {
            if (array_key_exists($field, $conditions) && ! is_bool($conditions[$field])) {
                throw ValidationException::withMessages(['conditions_json' => "{$field} must be true or false."]);
            }
        }

        foreach (['payload_equals', 'payload_contains'] as $field) {
            if (array_key_exists($field, $conditions) && ! is_array($conditions[$field])) {
                throw ValidationException::withMessages(['conditions_json' => "{$field} must be a JSON object."]);
            }
        }
    }

    private function validateGroupedConditions(array $conditions): void
    {
        if (! in_array($conditions['match'] ?? null, ['all', 'any'], true)) {
            throw ValidationException::withMessages(['conditions_json' => 'Condition group matching must be all or any.']);
        }

        foreach ((array) $conditions['groups'] as $groupIndex => $group) {
            if (! is_array($group) || ! in_array($group['match'] ?? null, ['all', 'any'], true)) {
                throw ValidationException::withMessages(['conditions_json' => 'Condition group #'.($groupIndex + 1).' must use all or any matching.']);
            }

            foreach ((array) ($group['conditions'] ?? []) as $rowIndex => $row) {
                $prefix = 'Condition '.($groupIndex + 1).'.'.($rowIndex + 1);
                if (! is_array($row) || ! array_key_exists((string) ($row['field'] ?? ''), self::BUILDER_CONDITION_FIELDS)) {
                    throw ValidationException::withMessages(['conditions_json' => "{$prefix} has an unknown field."]);
                }
                if (! array_key_exists((string) ($row['operator'] ?? ''), self::CONDITION_OPERATORS)) {
                    throw ValidationException::withMessages(['conditions_json' => "{$prefix} has an unknown operator."]);
                }
                if (($row['field'] ?? null) === 'payload' && blank($row['path'] ?? null)) {
                    throw ValidationException::withMessages(['conditions_json' => "{$prefix} requires a payload path."]);
                }
                if (($row['field'] ?? null) === 'confidence' && filled($row['value'] ?? null)) {
                    $confidence = (int) $row['value'];
                    if ($confidence < 0 || $confidence > 100) {
                        throw ValidationException::withMessages(['conditions_json' => "{$prefix} confidence must be between 0 and 100."]);
                    }
                }
            }
        }
    }

    private function groupedConditionsFromInput(array $input): array
    {
        $groups = collect((array) ($input['groups'] ?? []))
            ->filter(fn (mixed $group): bool => is_array($group))
            ->map(function (array $group): array {
                $rows = collect((array) ($group['conditions'] ?? []))
                    ->filter(fn (mixed $row): bool => is_array($row) && filled($row['field'] ?? null))
                    ->map(function (array $row): array {
                        $field = trim((string) ($row['field'] ?? ''));
                        $operator = trim((string) ($row['operator'] ?? 'equals'));
                        $value = $row['value'] ?? null;

                        if (in_array($operator, ['in', 'not_in'], true)) {
                            $value = $this->stringList($value);
                        } elseif ($field === 'confidence' && filled($value)) {
                            $value = (int) $value;
                        } elseif (is_string($value)) {
                            $value = trim($value);
                        }

                        return array_filter([
                            'field' => $field,
                            'path' => $field === 'payload' ? trim((string) ($row['path'] ?? '')) : null,
                            'operator' => $operator,
                            'value' => in_array($operator, ['is_true', 'is_false', 'exists', 'missing'], true) ? null : $value,
                        ], fn (mixed $entry): bool => $entry !== null);
                    })
                    ->values()
                    ->all();

                return [
                    'match' => in_array($group['match'] ?? null, ['all', 'any'], true) ? $group['match'] : 'all',
                    'conditions' => $rows,
                ];
            })
            ->filter(fn (array $group): bool => $group['conditions'] !== [])
            ->values()
            ->all();

        return [
            'version' => 2,
            'match' => in_array($input['match'] ?? null, ['all', 'any'], true) ? $input['match'] : 'all',
            'groups' => $groups,
        ];
    }

    private function legacyConditionsFromInput(array $input): array
    {
        $conditions = [];
        foreach (['source_domain', 'signal_type', 'severity', 'status'] as $field) {
            $values = $this->stringList($input[$field] ?? []);
            if ($values !== []) {
                $conditions[$field] = $values;
            }
        }
        if (filled($input['min_confidence'] ?? null)) {
            $conditions['min_confidence'] = (int) $input['min_confidence'];
        }
        foreach (['has_client', 'has_contact'] as $field) {
            if (($input[$field] ?? '') !== '') {
                $conditions[$field] = (bool) (int) $input[$field];
            }
        }
        foreach (['payload_equals', 'payload_contains'] as $field) {
            $map = $this->keyValueMap($input[$field] ?? '');
            if ($map !== []) {
                $conditions[$field] = $map;
            }
        }

        return $conditions;
    }

    private function actionsFromInput(array $input): array
    {
        return collect($input)
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['type'] ?? null))
            ->map(function (array $row): array {
                $type = trim((string) ($row['type'] ?? ''));
                $action = ['type' => $type];
                foreach (self::ACTION_OPTIONAL_FIELDS as $field) {
                    if (! array_key_exists($field, $row) || blank($row[$field])) {
                        continue;
                    }
                    $action[$field] = $this->normalizeActionField($field, $row[$field]);
                }
                foreach (['append_to_existing', 'create_if_missing'] as $field) {
                    if (array_key_exists($field, $row)) {
                        $action[$field] = filter_var($row[$field], FILTER_VALIDATE_BOOLEAN);
                    }
                }
                if ($type === 'sales_follow_up') {
                    if (isset($action['subject']) && ! isset($action['activity_subject'])) {
                        $action['activity_subject'] = $action['subject'];
                    }
                    if (isset($action['description']) && ! isset($action['activity_body'])) {
                        $action['activity_body'] = $action['description'];
                    }
                    unset($action['subject'], $action['description']);
                }
                if ($type === 'emit_signal' && isset($action['description']) && ! isset($action['summary'])) {
                    $action['summary'] = $action['description'];
                    unset($action['description']);
                }

                return $action;
            })
            ->values()
            ->all();
    }

    private function stringList(mixed $value): array
    {
        $items = is_array($value) ? Arr::flatten($value) : preg_split('/[\r\n,]+/', (string) $value);

        return collect($items ?: [])->map(fn (mixed $item): string => trim((string) $item))->filter()->unique()->values()->all();
    }

    private function keyValueMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return [];
        }
        if (str_starts_with($text, '{')) {
            $decoded = json_decode($text, true);
            return is_array($decoded) ? $decoded : [];
        }
        $map = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$key, $entryValue] = array_map('trim', explode('=', $line, 2));
                if ($key !== '') {
                    $map[$key] = $entryValue;
                }
            }
        }

        return $map;
    }

    private function normalizeActionField(string $field, mixed $value): mixed
    {
        if (in_array($field, ['confidence', 'actor_id', 'creator_id', 'owner_id', 'assigned_to', 'probability_percent', 'follow_up_minutes_from_now', 'site_id', 'contact_id', 'queue_id', 'ticket_type_id', 'priority_id', 'category_id', 'due_minutes_from_now', 'estimated_minutes'], true)) {
            return (int) $value;
        }
        if ($field === 'estimated_value_ex_vat') {
            return (float) $value;
        }
        if ($field === 'severity') {
            $severity = Str::lower(trim((string) $value));
            return array_key_exists($severity, SignalSettings::SEVERITY_OPTIONS) ? $severity : 'info';
        }

        return trim((string) $value);
    }

    private function validateActions(array $actions): void
    {
        if (! array_is_list($actions) || $actions === []) {
            throw ValidationException::withMessages(['actions_json' => 'Actions must be a non-empty JSON array.']);
        }
        foreach ($actions as $index => $action) {
            if (! is_array($action)) {
                throw ValidationException::withMessages(['actions_json' => 'Action #'.($index + 1).' must be a JSON object.']);
            }
            $type = $action['type'] ?? null;
            if (! is_string($type) || ! array_key_exists($type, self::ACTION_TYPES)) {
                throw ValidationException::withMessages(['actions_json' => 'Action #'.($index + 1).' has an unknown type.']);
            }
            foreach (self::ACTION_TYPES[$type]['required'] as $field) {
                if (blank($action[$field] ?? null)) {
                    throw ValidationException::withMessages(['actions_json' => 'Action #'.($index + 1)." ({$type}) requires {$field}."]);
                }
            }
            if ($type === 'webhook' && ! filter_var((string) $action['url'], FILTER_VALIDATE_URL)) {
                throw ValidationException::withMessages(['actions_json' => 'Action #'.($index + 1).' webhook URL must be valid.']);
            }
        }
    }
}
