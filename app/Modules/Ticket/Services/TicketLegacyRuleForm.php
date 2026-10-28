<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketType;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** The legacy form payload remains governed by the existing fenced mutation boundary. */
final class TicketLegacyRuleForm
{
    public function validatedRule(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'weight' => 'required|integer|min:0|max:100000',
            'is_active' => 'nullable|boolean',
            'stop_processing' => 'nullable|boolean',
            'conditions' => 'required|array|min:1',
            'conditions.*.field' => 'required|string|in:channel,subject,description,from_email,from_domain,email_tags,client_known,client_has_active_contract',
            'conditions.*.operator' => 'required|string|in:contains,equals,not_equals,starts_with,ends_with,regex,present',
            'conditions.*.value' => 'nullable|string|max:1000',
            'actions' => 'required|array|min:1',
            'actions.*.type' => 'required|string|in:set_ticket_type,set_queue,set_priority,set_sla,set_category,add_tag,emit_signal',
            'actions.*.value' => 'required|string|max:255',
            'actions.*.severity' => 'nullable|string|in:info,warning,error,critical',
            'actions.*.confidence' => 'nullable|integer|min:0|max:100',
            'actions.*.summary' => 'nullable|string|max:255',
            'actions.*.payload_note' => 'nullable|string|max:1000',
        ]);

        $actions = collect($data['actions'])
            ->map(function (array $action): array {
                $mapped = [
                    'type' => $action['type'],
                    'value' => $action['type'] === 'emit_signal'
                        ? $this->normalizeSignalType($action['value'])
                        : $action['value'],
                ];

                if ($action['type'] === 'emit_signal') {
                    $mapped['signal_type'] = $mapped['value'];
                    foreach (['severity', 'confidence', 'summary', 'payload_note'] as $field) {
                        if (array_key_exists($field, $action) && filled($action[$field])) {
                            $mapped[$field] = $action[$field];
                        }
                    }
                }

                return $mapped;
            })
            ->values()
            ->all();

        $this->validateRuleActionTargets($actions);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'weight' => $data['weight'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'stop_processing' => (bool) ($data['stop_processing'] ?? false),
            'conditions_json' => collect($data['conditions'])
                ->map(fn (array $condition) => [
                    'field' => $condition['field'],
                    'operator' => $condition['operator'],
                    'value' => $condition['value'] ?? '',
                ])
                ->values()
                ->all(),
            'actions_json' => $actions,
        ];
    }

    private function validateRuleActionTargets(array $actions): void
    {
        foreach ($actions as $action) {
            $exists = match ($action['type']) {
                'set_ticket_type' => TicketType::whereKey($action['value'])->exists(),
                'set_queue' => TicketQueue::whereKey($action['value'])->exists(),
                'set_priority' => TicketPriority::whereKey($action['value'])->exists(),
                'set_sla' => Sla::whereKey($action['value'])->exists(),
                'set_category' => Category::forTickets()->active()->whereKey($action['value'])->exists(),
                'add_tag' => Tag::where('active', true)->whereKey($action['value'])->exists(),
                'emit_signal' => $this->normalizeSignalType($action['value']) !== '',
                default => false,
            };

            if (! $exists) {
                throw ValidationException::withMessages([
                    'actions' => $action['type'] === 'emit_signal'
                        ? 'Emit Signal action requires a signal type.'
                        : 'Rule action target does not exist.',
                ]);
            }
        }
    }

    private function normalizeSignalType(mixed $value): string
    {
        return str((string) $value)
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->toString();
    }
}
