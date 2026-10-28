<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Ticket\Models\TicketAssignmentRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Explicit assignment-rule writes retain the existing ordered condition semantics. */
class TicketAssignmentRuleEditor
{
    public function data(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'weight' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'], 'stop_processing' => ['nullable', 'boolean'], 'conditions' => ['required', 'array', 'min:1'],
            'conditions.*.field' => ['required', 'string', 'in:client_id,contact_id,queue_id,category_id,tag_ids,priority_id,ticket_type_id,channel'],
            'conditions.*.operator' => ['required', 'string', 'in:equals,not_equals,contains,present'], 'conditions.*.value' => ['nullable', 'string', 'max:255'],
            'action_value' => ['required', Rule::exists((new User)->getTable(), 'id')],
        ]);
        $conditions = collect($data['conditions'])->filter(fn ($condition) => ($condition['operator'] ?? 'equals') === 'present' || trim((string) ($condition['value'] ?? '')) !== '')
            ->map(fn ($condition) => ['field' => $condition['field'], 'operator' => $condition['operator'], 'value' => $condition['value'] ?? ''])->values()->all();
        if ($conditions === []) {
            throw ValidationException::withMessages(['conditions' => 'Add at least one assignment condition.']);
        }

        return ['name' => $data['name'], 'description' => $data['description'] ?? null, 'weight' => (int) $data['weight'], 'is_active' => (bool) ($data['is_active'] ?? false),
            'stop_processing' => (bool) ($data['stop_processing'] ?? true), 'conditions_json' => $conditions, 'action_type' => 'assign_user', 'action_value' => (string) $data['action_value']];
    }

    public function create(array $data, User $actor, array $auditContext = []): TicketAssignmentRule
    {
        return DB::transaction(function () use ($data, $actor, $auditContext) {
            $rule = TicketAssignmentRule::create($data + ['created_by' => $actor->id, 'updated_by' => $actor->id]);
            app(DomainApiAudit::class)->record($actor, 'ticket', 'ticket.assignment.rule.created', $rule, [], $rule->attributesToArray(), $auditContext);

            return $rule;
        });
    }

    public function delete(TicketAssignmentRule $rule, User $actor): void
    {
        DB::transaction(function () use ($rule, $actor) {
            $before = $rule->attributesToArray();
            $rule->delete();
            app(DomainApiAudit::class)->record($actor, 'ticket', 'ticket.assignment.rule.deleted', $rule, $before, $rule->attributesToArray());
        });
    }
}
