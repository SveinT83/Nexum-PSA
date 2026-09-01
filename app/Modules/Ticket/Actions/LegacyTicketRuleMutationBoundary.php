<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\TicketRule;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;

/**
 * Operator-facing authorization boundary for legacy Ticket Rule writes.
 *
 * Compatibility and migration tests may seed the low-level fenced catalogue
 * action directly. Every HTTP mutation must cross this boundary so stale User
 * permission relations cannot preserve revoked publication authority.
 */
final class LegacyTicketRuleMutationBoundary
{
    private const BASE_PERMISSIONS = [
        'ticket.manage_rules',
        'ticket.rule_publish',
    ];

    public function __construct(
        private readonly MutateLegacyTicketRuleCatalog $catalog,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(?User $operator, array $attributes): TicketRule
    {
        $operator = $this->currentOperator($operator);
        $this->assertActionPermissions($operator, (array) ($attributes['actions_json'] ?? []));

        return $this->catalog->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(?User $operator, TicketRule $rule, array $attributes): TicketRule
    {
        $operator = $this->currentOperator($operator);
        $actions = array_key_exists('actions_json', $attributes)
            ? (array) $attributes['actions_json']
            : (array) $rule->actions_json;
        $this->assertActionPermissions($operator, $actions);

        return $this->catalog->update($rule, $attributes);
    }

    public function toggle(?User $operator, TicketRule $rule): TicketRule
    {
        return $this->catalog->toggle(
            $rule,
            function (TicketRule $locked, bool $willEnable) use ($operator): void {
                $current = $this->currentOperator($operator);

                if ($willEnable) {
                    $this->assertActionPermissions($current, (array) $locked->actions_json);
                }
            },
        );
    }

    public function delete(?User $operator, TicketRule $rule): void
    {
        $this->currentOperator($operator);
        $this->catalog->delete($rule);
    }

    private function currentOperator(?User $operator): User
    {
        $current = $operator?->getKey()
            ? User::query()->find($operator->getKey())
            : null;

        if (! $current || ! $current->isActive()) {
            throw new AuthorizationException('An active Ticket Rule operator is required.');
        }

        foreach (self::BASE_PERMISSIONS as $permission) {
            $this->assertPermission($current, $permission);
        }

        return $current;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function assertActionPermissions(User $operator, array $actions): void
    {
        foreach ($actions as $action) {
            $type = is_array($action) ? ($action['type'] ?? null) : null;
            $permission = match ($type) {
                'emit_signal' => 'signal.action.execute',
                'set_ticket_type',
                'set_queue',
                'set_priority',
                'set_sla',
                'set_category',
                'add_tag' => 'ticket.update',
                default => null,
            };

            if ($permission === null) {
                throw new AuthorizationException(
                    'The legacy Ticket Rule contains an unsupported action permission boundary.',
                );
            }

            $this->assertPermission($operator, $permission);
        }
    }

    private function assertPermission(User $operator, string $permission): void
    {
        if (! Permission::query()
            ->where('name', $permission)
            ->where('guard_name', 'web')
            ->exists()
            || ! $operator->can($permission)) {
            throw new AuthorizationException('Missing permission: '.$permission);
        }
    }
}
