<?php

namespace App\Modules\Integration\Actions;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Integration\Models\RmmAlertRule;
use App\Modules\Integration\Support\RmmAlertRuleDefinition;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveRmmAlertRule
{
    public function __construct(private readonly RmmAlertRuleDefinition $definitions) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, ?RmmAlertRule $rule, ?User $actor): RmmAlertRule
    {
        $conditions = $this->definitions->normalizeConditions((array) $data['conditions']);
        $actions = $this->definitions->normalizeActions((array) $data['actions']);
        $this->validateReferences($conditions, $actions);

        return DB::transaction(function () use ($data, $conditions, $actions, $rule, $actor): RmmAlertRule {
            $current = $rule
                ? RmmAlertRule::query()->lockForUpdate()->findOrFail($rule->id)
                : new RmmAlertRule;

            if ($rule && (int) ($data['revision'] ?? 0) !== (int) $current->revision) {
                throw ValidationException::withMessages([
                    'revision' => 'This rule changed after the form was opened. Reload before saving.',
                ]);
            }

            $current->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? false),
                'priority' => $data['priority'],
                'stop_processing' => (bool) ($data['stop_processing'] ?? false),
                'conditions' => $conditions,
                'actions' => $actions,
                'updated_by' => $actor?->id,
            ]);
            if (! $rule) {
                $current->created_by = $actor?->id;
            }
            $current->save();

            return $current->fresh();
        });
    }

    /** @param array<string, mixed> $conditions @param list<array<string, mixed>> $actions */
    private function validateReferences(array $conditions, array $actions): void
    {
        $errors = [];
        if (isset($conditions['asset_id']) && ! Asset::query()->whereKey($conditions['asset_id'])->exists()) {
            $errors['conditions.asset_id'] = 'The selected Asset no longer exists.';
        }
        if (isset($conditions['client_id']) && ! Client::query()->whereKey($conditions['client_id'])->exists()) {
            $errors['conditions.client_id'] = 'The selected Client no longer exists.';
        }

        foreach ($actions as $index => $action) {
            $references = [
                'queue_id' => [TicketQueue::class, ['is_active' => true], 'active Ticket Queue'],
                'ticket_type_id' => [TicketType::class, ['is_active' => true], 'active Ticket Type'],
                'priority_id' => [TicketPriority::class, ['is_active' => true], 'active Ticket Priority'],
                'owner_id' => [User::class, ['status' => User::STATUS_ACTIVE, 'is_system_actor' => false], 'active technician'],
                'assigned_to' => [User::class, ['status' => User::STATUS_ACTIVE, 'is_system_actor' => false], 'active technician'],
            ];

            foreach ($references as $field => [$model, $where, $label]) {
                if (! isset($action[$field])) {
                    continue;
                }
                $query = $model::query()->whereKey($action[$field]);
                foreach ($where as $column => $value) {
                    $query->where($column, $value);
                }
                if (! $query->exists()) {
                    $errors["actions.{$index}.{$field}"] = "Select an {$label}.";
                }
            }

            if (isset($action['category_id']) && ! Category::query()
                ->forTickets()->active()->whereKey($action['category_id'])->exists()) {
                $errors["actions.{$index}.category_id"] = 'Select an active Ticket Category.';
            }
            if (isset($action['reopen_status_id']) && ! TicketStatus::query()
                ->whereKey($action['reopen_status_id'])->where('is_active', true)->where('is_closed', false)->exists()) {
                $errors["actions.{$index}.reopen_status_id"] = 'Select an active non-closed Ticket status.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
