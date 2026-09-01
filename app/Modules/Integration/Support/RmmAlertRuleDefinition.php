<?php

namespace App\Modules\Integration\Support;

use Illuminate\Validation\ValidationException;

class RmmAlertRuleDefinition
{
    public const CONDITION_LABELS = [
        'subject_contains' => 'Alert subject contains',
        'severities' => 'Severity is one of',
        'asset_id' => 'Asset is',
        'client_id' => 'Client is',
        'fingerprint' => 'Fingerprint equals',
        'integration_types' => 'RMM provider is one of',
    ];

    public const ACTION_LABELS = [
        'create_ticket' => 'Create or update Ticket',
        'create_task' => 'Create or reuse Task',
        'reopen_ticket' => 'Reopen linked Ticket',
        'emit_signal' => 'Emit Signal',
        'ignore' => 'Ignore and stop RMM routing',
    ];

    private const ACTION_FIELDS = [
        'create_ticket' => [
            'subject', 'description', 'queue_id', 'ticket_type_id', 'priority_id', 'category_id', 'owner_id',
        ],
        'create_task' => [
            'title', 'description', 'queue_id', 'priority_id', 'category_id', 'assigned_to',
            'due_minutes_from_now', 'estimated_minutes',
        ],
        'reopen_ticket' => ['reopen_status_id'],
        'emit_signal' => ['signal_type', 'severity', 'summary'],
        'ignore' => [],
    ];

    private const INTEGER_FIELDS = [
        'queue_id', 'ticket_type_id', 'priority_id', 'category_id', 'owner_id', 'assigned_to',
        'due_minutes_from_now', 'estimated_minutes', 'reopen_status_id',
    ];

    /** @return array<string, mixed> */
    public function normalizeConditions(array $input): array
    {
        $unknown = array_diff(array_keys($input), array_keys(self::CONDITION_LABELS));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'conditions' => 'Unsupported RMM condition: '.implode(', ', $unknown).'.',
            ]);
        }

        $conditions = [];
        $subject = trim((string) ($input['subject_contains'] ?? ''));
        if ($subject !== '') {
            $conditions['subject_contains'] = $subject;
        }

        $severities = collect((array) ($input['severities'] ?? []))
            ->map(fn (mixed $value): string => mb_strtolower(trim((string) $value)))
            ->filter()
            ->unique()->values()->all();
        if (array_diff($severities, RmmAlertSeverity::LEVELS) !== []) {
            throw ValidationException::withMessages([
                'conditions.severities' => 'Select only supported RMM severities.',
            ]);
        }
        if ($severities !== []) {
            $conditions['severities'] = $severities;
        }

        foreach (['asset_id', 'client_id'] as $field) {
            if (filled($input[$field] ?? null)) {
                $value = filter_var($input[$field], FILTER_VALIDATE_INT);
                if ($value === false || $value < 1) {
                    throw ValidationException::withMessages([$field => 'Select a valid positive ID.']);
                }
                $conditions[$field] = $value;
            }
        }

        $fingerprint = trim((string) ($input['fingerprint'] ?? ''));
        if ($fingerprint !== '') {
            $conditions['fingerprint'] = $fingerprint;
        }

        $providers = collect((array) ($input['integration_types'] ?? []))
            ->map(fn (mixed $value): string => mb_strtolower(trim((string) $value)))
            ->filter()
            ->unique()->values()->all();
        if (array_diff($providers, ['tactical', 'nable']) !== []) {
            throw ValidationException::withMessages([
                'conditions.integration_types' => 'Select only supported RMM providers.',
            ]);
        }
        if ($providers !== []) {
            $conditions['integration_types'] = $providers;
        }

        if ($conditions === []) {
            throw ValidationException::withMessages([
                'conditions' => 'Add at least one RMM alert condition.',
            ]);
        }

        return $conditions;
    }

    /** @return list<array<string, mixed>> */
    public function normalizeActions(array $input): array
    {
        $actions = collect($input)
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['type'] ?? null))
            ->map(function (array $row): array {
                $type = trim((string) ($row['type'] ?? ''));
                if (! array_key_exists($type, self::ACTION_LABELS)) {
                    throw ValidationException::withMessages([
                        'actions' => "Unsupported RMM action: {$type}.",
                    ]);
                }
                $unknown = array_diff(array_keys($row), ['type', ...self::ACTION_FIELDS[$type]]);
                if ($unknown !== []) {
                    throw ValidationException::withMessages([
                        'actions' => "Unsupported {$type} field: ".implode(', ', $unknown).'.',
                    ]);
                }

                $action = ['type' => $type];
                foreach (self::ACTION_FIELDS[$type] as $field) {
                    if (! filled($row[$field] ?? null)) {
                        continue;
                    }
                    if (in_array($field, self::INTEGER_FIELDS, true)) {
                        $value = filter_var($row[$field], FILTER_VALIDATE_INT);
                        $allowsZero = $field === 'due_minutes_from_now';
                        if ($value === false || ($allowsZero ? $value < 0 : $value < 1)) {
                            throw ValidationException::withMessages([
                                'actions' => "{$field} must be a valid integer.",
                            ]);
                        }
                        $action[$field] = $value;
                    } else {
                        $action[$field] = trim((string) $row[$field]);
                    }
                }

                if ($type === 'reopen_ticket' && empty($action['reopen_status_id'])) {
                    throw ValidationException::withMessages([
                        'actions' => 'Reopen Ticket requires an active target Ticket status.',
                    ]);
                }
                if ($type === 'emit_signal' && blank($action['signal_type'] ?? null)) {
                    throw ValidationException::withMessages([
                        'actions' => 'Emit Signal requires a signal type.',
                    ]);
                }
                if (isset($action['severity'])) {
                    $action['severity'] = RmmAlertSeverity::normalize($action['severity']);
                }

                return $action;
            })
            ->values()
            ->all();

        if ($actions === []) {
            throw ValidationException::withMessages(['actions' => 'Add at least one RMM action.']);
        }
        if (count($actions) > 10) {
            throw ValidationException::withMessages(['actions' => 'A rule may contain at most 10 actions.']);
        }
        if (collect($actions)->contains(fn (array $action): bool => $action['type'] === 'ignore') && count($actions) !== 1) {
            throw ValidationException::withMessages([
                'actions' => 'Ignore must be the only action because it stops all RMM routing.',
            ]);
        }

        return $actions;
    }

    public function conditionSummary(array $conditions): string
    {
        return collect($conditions)->map(function (mixed $value, string $field): string {
            $display = is_array($value) ? implode(', ', $value) : (string) $value;

            return (self::CONDITION_LABELS[$field] ?? $field).': '.$display;
        })->implode(' AND ');
    }

    public function actionSummary(array $actions): string
    {
        return collect($actions)
            ->map(fn (array $action): string => self::ACTION_LABELS[$action['type']] ?? $action['type'])
            ->implode(' -> ');
    }
}
