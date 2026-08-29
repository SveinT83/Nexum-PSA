<?php

namespace App\Modules\Ticket\Services;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketWorkflowVersion;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class TicketRulePublicationTargetValidator
{
    public function __construct(
        private readonly TicketCustomFieldTargetValidator $customFields,
    ) {}

    /** @param array<string, mixed> $definition */
    public function validate(array $definition): void
    {
        $this->validateTriggerTargets($definition);
        $this->validateConditionTargets($definition);

        foreach (array_merge(
            (array) ($definition['then_actions'] ?? []),
            (array) ($definition['else_actions'] ?? []),
        ) as $action) {
            if (is_array($action)) {
                $this->validateActionTargets($action);
            }
        }
    }

    /**
     * Reauthorize every Custom Field target for the human operating the rule.
     *
     * @param  array<string, mixed>  $definition
     */
    public function validateCustomFieldAccess(array $definition, User $operator): void
    {
        if (($definition['trigger'] ?? null) === TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED) {
            foreach ((array) data_get($definition, 'trigger_filters.targets', []) as $target) {
                $this->operatorCustomFieldTarget($target, 'view', $operator);
            }
        }

        foreach ((array) data_get($definition, 'conditions.groups', []) as $group) {
            foreach ((array) data_get($group, 'conditions', []) as $condition) {
                if (is_array($condition) && array_key_exists('target', $condition)) {
                    $this->operatorCustomFieldTarget($condition['target'], 'view', $operator);
                }
            }
        }

        foreach (array_merge(
            (array) ($definition['then_actions'] ?? []),
            (array) ($definition['else_actions'] ?? []),
        ) as $action) {
            if (is_array($action)
                && in_array($action['type'] ?? null, [
                    TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
                    TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD,
                ], true)) {
                $this->operatorCustomFieldTarget(
                    data_get($action, 'input.target'),
                    'edit',
                    $operator,
                );
            }
        }
    }

    /** @param array<string, mixed> $definition */
    private function validateTriggerTargets(array $definition): void
    {
        $trigger = $definition['trigger'] ?? null;
        $filters = (array) ($definition['trigger_filters'] ?? []);

        if ($trigger === TicketRuleTriggerRegistry::TAGS_CHANGED) {
            $this->activeIds(Tag::class, array_merge(
                (array) ($filters['added_tag_ids'] ?? []),
                (array) ($filters['removed_tag_ids'] ?? []),
            ), 'active');
        }

        if (in_array($trigger, [
            TicketRuleTriggerRegistry::WORKFLOW_CHANGED,
            TicketRuleTriggerRegistry::WORKFLOW_STATE_CHANGED,
        ], true)) {
            $this->publishedWorkflowIds((array) ($filters['workflow_version_ids'] ?? []));
        }

        if ($trigger === TicketRuleTriggerRegistry::STATUS_CHANGED) {
            $this->activeIds(TicketStatus::class, (array) ($filters['status_ids'] ?? []), 'is_active');
        }

        if ($trigger === TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED) {
            foreach ((array) ($filters['targets'] ?? []) as $target) {
                $this->customFieldTarget($target, 'condition');
            }
        }
    }

    /** @param array<string, mixed> $definition */
    private function validateConditionTargets(array $definition): void
    {
        foreach ((array) data_get($definition, 'conditions.groups', []) as $group) {
            foreach ((array) data_get($group, 'conditions', []) as $condition) {
                if (is_array($condition) && array_key_exists('target', $condition)) {
                    $this->customFieldTarget($condition['target'], 'condition');
                }
            }
        }
    }

    /** @param array<string, mixed> $action */
    private function validateActionTargets(array $action): void
    {
        $type = $action['type'] ?? null;
        $input = (array) ($action['input'] ?? []);

        if ($type === TicketRuleActionProviderRegistry::SET_TICKET_FIELDS) {
            $fields = (array) ($input['fields'] ?? []);
            $this->activeValue(TicketType::class, $fields['ticket_type_id'] ?? null, 'is_active');
            $this->activeValue(TicketPriority::class, $fields['priority_id'] ?? null, 'is_active');
            $this->activeValue(Sla::class, $fields['sla_id'] ?? null);
            $this->activeCategory($fields['category_id'] ?? null);
            $this->activeValue(Client::class, $fields['client_id'] ?? null, 'active');
            $this->activeValue(ClientSite::class, $fields['site_id'] ?? null);
            $this->activeValue(ClientUser::class, $fields['contact_id'] ?? null, 'active');
            $this->activeValue(Asset::class, $fields['asset_id'] ?? null);
        }

        if ($type === TicketRuleActionProviderRegistry::SET_QUEUE) {
            $this->activeValue(TicketQueue::class, $input['queue_id'] ?? null, 'is_active');
        }

        if ($type === TicketRuleActionProviderRegistry::ASSIGN_OWNER) {
            $ownerId = (int) ($input['owner_id'] ?? 0);
            if ($ownerId < 1 || ! User::query()
                ->whereKey($ownerId)
                ->where('status', User::STATUS_ACTIVE)
                ->where('is_system_actor', false)
                ->exists()) {
                $this->unavailable();
            }
        }

        if (in_array($type, [
            TicketRuleActionProviderRegistry::ADD_TAGS,
            TicketRuleActionProviderRegistry::REMOVE_TAGS,
        ], true)) {
            $this->activeIds(Tag::class, (array) ($input['tag_ids'] ?? []), 'active');
        }

        if (in_array($type, [
            TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
            TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD,
        ], true)) {
            $this->customFieldTarget($input['target'] ?? null, 'action');
        }

        if ($type === TicketRuleActionProviderRegistry::SELECT_WORKFLOW) {
            $this->publishedWorkflowIds([$input['workflow_version_id'] ?? null]);
        }

        if ($type === TicketRuleActionProviderRegistry::SWITCH_WORKFLOW) {
            $this->publishedWorkflowIds([
                $input['source_workflow_version_id'] ?? null,
                $input['target_workflow_version_id'] ?? null,
            ]);
            $stateKey = $input['target_state_key'] ?? null;
            if (filled($stateKey)) {
                $target = TicketWorkflowVersion::query()
                    ->whereKey((int) ($input['target_workflow_version_id'] ?? 0))
                    ->first();
                if (! collect($target?->definition['states'] ?? [])->contains(
                    fn (mixed $state): bool => is_array($state)
                        && ($state['state_key'] ?? null) === $stateKey
                )) {
                    $this->unavailable();
                }
            }
        }
    }

    private function customFieldTarget(mixed $target, string $usage): void
    {
        $result = $this->customFields->validateForPublication($target, $usage);
        if (! ($result['valid'] ?? false)) {
            throw ValidationException::withMessages([
                'definition' => (string) ($result['reason_code'] ?? 'custom_field_target_unavailable'),
            ]);
        }
    }

    private function operatorCustomFieldTarget(
        mixed $target,
        string $access,
        User $operator,
    ): void {
        $result = $this->customFields->resolve($target, $access, $operator);
        if (! ($result['valid'] ?? false)) {
            throw ValidationException::withMessages([
                'definition' => 'A referenced Custom Field is unavailable for your account.',
            ]);
        }
    }

    /** @param class-string<Model> $model */
    private function activeValue(string $model, mixed $id, ?string $activeColumn = null): void
    {
        if ($id === null) {
            return;
        }

        $query = $model::query()->whereKey((int) $id);
        if ($activeColumn !== null) {
            $query->where($activeColumn, true);
        }
        if ((int) $id < 1 || ! $query->exists()) {
            $this->unavailable();
        }
    }

    /** @param class-string<Model> $model @param array<mixed> $ids */
    private function activeIds(string $model, array $ids, ?string $activeColumn = null): void
    {
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            $this->activeValue($model, $id, $activeColumn);
        }
    }

    private function activeCategory(mixed $id): void
    {
        if ($id !== null && ! Category::query()->forTickets()->active()->whereKey((int) $id)->exists()) {
            $this->unavailable();
        }
    }

    /** @param array<mixed> $ids */
    private function publishedWorkflowIds(array $ids): void
    {
        foreach ($ids as $id) {
            if ((int) $id < 1 || ! TicketWorkflowVersion::query()
                ->with('workflow')
                ->whereKey((int) $id)
                ->where('status', 'published')
                ->whereHas('workflow', fn ($query) => $query->where('is_active', true))
                ->exists()) {
                $this->unavailable();
            }
        }
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages([
            'definition' => 'A referenced Ticket Rule target is no longer available.',
        ]);
    }
}
