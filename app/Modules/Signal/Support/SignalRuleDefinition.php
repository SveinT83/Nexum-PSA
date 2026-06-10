<?php

namespace App\Modules\Signal\Support;

use Illuminate\Validation\ValidationException;

class SignalRuleDefinition
{
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

    public const ACTION_TYPES = [
        'marketing_suppress_contact_email' => [
            'label' => 'Suppress contact marketing email',
            'required' => [],
        ],
        'tag_contact' => [
            'label' => 'Tag contact',
            'required' => ['tag'],
        ],
        'tag_client' => [
            'label' => 'Tag client',
            'required' => ['tag'],
        ],
        'emit_signal' => [
            'label' => 'Emit derived signal',
            'required' => ['signal_type'],
        ],
        'sales_follow_up' => [
            'label' => 'Create Sales follow-up',
            'required' => [],
        ],
        'ticket_follow_up' => [
            'label' => 'Create Ticket follow-up',
            'required' => [],
        ],
        'webhook' => [
            'label' => 'Queue webhook',
            'required' => ['url'],
        ],
    ];

    public function decodeAndValidate(?string $conditionsJson, string $actionsJson): array
    {
        $conditions = $this->decodeJson($conditionsJson ?: '{}', 'conditions_json');
        $actions = $this->decodeJson($actionsJson, 'actions_json');

        $this->validateConditions($conditions);
        $this->validateActions($actions);

        return [$conditions, $actions];
    }

    private function decodeJson(string $json, string $field): array
    {
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw ValidationException::withMessages([
                $field => 'Must be a valid JSON object or array.',
            ]);
        }

        return $decoded;
    }

    private function validateConditions(array $conditions): void
    {
        foreach (array_keys($conditions) as $field) {
            if (! in_array($field, self::CONDITION_FIELDS, true)) {
                throw ValidationException::withMessages([
                    'conditions_json' => "Unknown condition field: {$field}.",
                ]);
            }
        }

        if (array_key_exists('min_confidence', $conditions)) {
            $confidence = (int) $conditions['min_confidence'];
            if ($confidence < 0 || $confidence > 100) {
                throw ValidationException::withMessages([
                    'conditions_json' => 'min_confidence must be between 0 and 100.',
                ]);
            }
        }

        foreach (['has_client', 'has_contact'] as $field) {
            if (array_key_exists($field, $conditions) && ! is_bool($conditions[$field])) {
                throw ValidationException::withMessages([
                    'conditions_json' => "{$field} must be true or false.",
                ]);
            }
        }

        foreach (['payload_equals', 'payload_contains'] as $field) {
            if (array_key_exists($field, $conditions) && ! is_array($conditions[$field])) {
                throw ValidationException::withMessages([
                    'conditions_json' => "{$field} must be a JSON object.",
                ]);
            }
        }
    }

    private function validateActions(array $actions): void
    {
        if (array_is_list($actions) === false || $actions === []) {
            throw ValidationException::withMessages([
                'actions_json' => 'Actions must be a non-empty JSON array.',
            ]);
        }

        foreach ($actions as $index => $action) {
            if (! is_array($action)) {
                throw ValidationException::withMessages([
                    'actions_json' => "Action #".($index + 1).' must be a JSON object.',
                ]);
            }

            $type = $action['type'] ?? null;
            if (! is_string($type) || ! array_key_exists($type, self::ACTION_TYPES)) {
                throw ValidationException::withMessages([
                    'actions_json' => "Action #".($index + 1).' has an unknown type.',
                ]);
            }

            foreach (self::ACTION_TYPES[$type]['required'] as $field) {
                if (blank($action[$field] ?? null)) {
                    throw ValidationException::withMessages([
                        'actions_json' => "Action #".($index + 1)." ({$type}) requires {$field}.",
                    ]);
                }
            }

            if ($type === 'webhook' && ! filter_var((string) $action['url'], FILTER_VALIDATE_URL)) {
                throw ValidationException::withMessages([
                    'actions_json' => "Action #".($index + 1).' webhook URL must be valid.',
                ]);
            }
        }
    }
}
