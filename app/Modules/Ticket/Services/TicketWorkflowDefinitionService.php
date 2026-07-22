<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowVersion;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketWorkflowCustomerNotificationPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketWorkflowDefinitionService
{
    public const CURRENT_SCHEMA_VERSION = 4;

    public function __construct(
        private readonly TicketWorkflowRequirementEvaluator $requirements,
        private readonly EnsureDefaultEmailTemplates $defaultEmailTemplates,
    ) {}

    /**
     * Build the mutable editor definition without changing a published version.
     *
     * @return array<string, mixed>
     */
    public function fromWorkflow(TicketWorkflow $workflow): array
    {
        $workflow->loadMissing(['states', 'transitions']);

        return [
            'schema_version' => self::CURRENT_SCHEMA_VERSION,
            'states' => $workflow->states
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->map(fn ($state) => [
                    'state_key' => $state->state_key ?: 'state-'.$state->id,
                    'ticket_status_id' => (int) $state->ticket_status_id,
                    'name' => $state->name,
                    'is_initial' => (bool) $state->is_initial,
                    'is_terminal' => (bool) $state->is_terminal,
                    'requirements' => $this->requirementsWithLegacyFlags($state->requirements, $state),
                    'action_policy' => $state->action_policy ?: [],
                    'assignment_policy' => $state->assignment_policy ?: [],
                    'commercial_policy' => $state->commercial_policy ?: [],
                    'sort_order' => (int) $state->sort_order,
                ])->values()->all(),
            'transitions' => $workflow->transitions
                ->where('is_active', true)
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->map(fn ($transition) => [
                    'transition_key' => $transition->transition_key ?: 'transition-'.$transition->id,
                    'from_state_key' => $transition->from_state_key,
                    'to_state_key' => $transition->to_state_key,
                    'label' => $transition->label,
                    'manual_enabled' => (bool) $transition->manual_enabled,
                    'trigger_actions' => $transition->trigger_actions ?: [],
                    'requirements' => $this->requirementsWithLegacyFlags($transition->requirements, $transition),
                    'customer_notification' => TicketWorkflowCustomerNotificationPolicy::normalize($transition->customer_notification),
                    'sort_order' => (int) $transition->sort_order,
                ])->values()->all(),
            'escalation_paths' => array_values($workflow->escalation_paths ?: []),
        ];
    }

    public function publish(TicketWorkflow $workflow, ?User $actor = null): TicketWorkflowVersion
    {
        $definition = $this->fromWorkflow($workflow);
        $this->validate($definition, $workflow);

        return DB::transaction(function () use ($workflow, $actor, $definition): TicketWorkflowVersion {
            $locked = TicketWorkflow::query()->lockForUpdate()->findOrFail($workflow->id);
            $nextVersion = ((int) $locked->versions()->max('version')) + 1;

            $version = $locked->versions()->create([
                'version' => $nextVersion,
                'status' => 'published',
                'definition' => $definition,
                'published_by' => $actor?->id,
                'published_at' => now(),
            ]);

            $locked->forceFill([
                'definition_status' => 'published',
                'published_version_id' => $version->id,
            ])->save();

            return $version;
        });
    }

    /**
     * Persist a mutable definition while published versions remain immutable.
     *
     * @param  array<string, mixed>  $definition
     */
    public function saveDraft(TicketWorkflow $workflow, array $definition): TicketWorkflow
    {
        $definition['schema_version'] = self::CURRENT_SCHEMA_VERSION;
        $this->validate($definition, $workflow);

        return DB::transaction(function () use ($workflow, $definition): TicketWorkflow {
            $stateKeys = collect($definition['states'])->pluck('state_key')->all();
            $workflow->states()->whereNotIn('state_key', $stateKeys)->delete();

            foreach ($definition['states'] as $state) {
                $requirements = $this->tree($state['requirements'] ?? []);
                $legacyFlags = $this->legacyFlagsForTree($requirements);
                $workflow->states()->updateOrCreate(
                    ['state_key' => $state['state_key']],
                    [
                        'ticket_status_id' => $state['ticket_status_id'],
                        'name' => $state['name'],
                        'is_initial' => (bool) ($state['is_initial'] ?? false),
                        'is_terminal' => (bool) ($state['is_terminal'] ?? false),
                        'requirements' => $requirements,
                        'action_policy' => $state['action_policy'] ?? [],
                        'assignment_policy' => $state['assignment_policy'] ?? [],
                        'commercial_policy' => $state['commercial_policy'] ?? [],
                        'requires_note' => $legacyFlags['requires_note'],
                        'requires_response' => $legacyFlags['requires_response'],
                        'requires_resolution' => $legacyFlags['requires_resolution'],
                        'requires_knowledge_update' => $legacyFlags['requires_knowledge_update'],
                        'sort_order' => (int) ($state['sort_order'] ?? 10),
                    ],
                );
            }

            $workflow->transitions()->delete();
            foreach ($definition['transitions'] ?? [] as $transition) {
                $requirements = $this->tree($transition['requirements'] ?? []);
                $legacyFlags = $this->legacyFlagsForTree($requirements);
                $workflow->transitions()->create([
                    'transition_key' => $transition['transition_key'],
                    'from_state_key' => $transition['from_state_key'],
                    'to_state_key' => $transition['to_state_key'],
                    'from_status_id' => collect($definition['states'])->firstWhere('state_key', $transition['from_state_key'])['ticket_status_id'],
                    'to_status_id' => collect($definition['states'])->firstWhere('state_key', $transition['to_state_key'])['ticket_status_id'],
                    'label' => $transition['label'],
                    'is_active' => true,
                    'manual_enabled' => (bool) ($transition['manual_enabled'] ?? true),
                    'trigger_actions' => array_values($transition['trigger_actions'] ?? []),
                    'requirements' => $requirements,
                    'customer_notification' => TicketWorkflowCustomerNotificationPolicy::normalize($transition['customer_notification'] ?? null),
                    'requires_note' => $legacyFlags['requires_note'],
                    'requires_response' => $legacyFlags['requires_response'],
                    'requires_resolution' => $legacyFlags['requires_resolution'],
                    'requires_knowledge_update' => $legacyFlags['requires_knowledge_update'],
                    'sort_order' => (int) ($transition['sort_order'] ?? 10),
                ]);
            }

            $workflow->forceFill([
                'definition_status' => 'draft',
                'escalation_paths' => array_values($definition['escalation_paths'] ?? []),
            ])->save();

            return $workflow->refresh()->load(['states', 'transitions', 'publishedVersion']);
        });
    }

    /**
     * @return array{workflow_id: int|null, workflow_version_id: int|null, workflow_state_key: string|null, status_id: int|null}
     */
    public function initialTicketState(?int $workflowId, ?int $fallbackStatusId = null): array
    {
        $workflow = $workflowId
            ? TicketWorkflow::query()->with('publishedVersion')->find($workflowId)
            : TicketWorkflow::query()->with('publishedVersion')->where('is_active', true)->where('is_default', true)->first();

        if (! $workflow) {
            return [
                'workflow_id' => null,
                'workflow_version_id' => null,
                'workflow_state_key' => null,
                'status_id' => $fallbackStatusId,
            ];
        }

        $definition = $workflow->publishedVersion?->definition ?: $this->fromWorkflow($workflow);
        $initial = collect($definition['states'] ?? [])->firstWhere('ticket_status_id', (int) $fallbackStatusId)
            ?? collect($definition['states'] ?? [])->firstWhere('is_initial', true)
            ?? collect($definition['states'] ?? [])->sortBy('sort_order')->first();

        return [
            'workflow_id' => $workflow->id,
            'workflow_version_id' => $workflow->publishedVersion?->id,
            'workflow_state_key' => $initial['state_key'] ?? null,
            'status_id' => isset($initial['ticket_status_id']) ? (int) $initial['ticket_status_id'] : $fallbackStatusId,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function validate(array $definition, ?TicketWorkflow $workflow = null): void
    {
        $states = collect($definition['states'] ?? []);

        if ($states->isEmpty()) {
            throw ValidationException::withMessages(['states' => 'A workflow must contain at least one state.']);
        }

        if ($states->where('is_initial', true)->count() !== 1) {
            throw ValidationException::withMessages(['states' => 'A workflow must contain exactly one initial state.']);
        }

        $keys = $states->pluck('state_key')->filter();
        if ($keys->count() !== $states->count() || $keys->unique()->count() !== $keys->count()) {
            throw ValidationException::withMessages(['states' => 'Every workflow state must have a unique stable key.']);
        }

        $catalog = $this->requirements->catalog();
        foreach ($states as $state) {
            $this->validateTree($state['requirements'] ?? [], $catalog);

            foreach (($state['action_policy'] ?? []) as $action => $policy) {
                if (! isset(TicketAction::definitions()[$action])) {
                    throw ValidationException::withMessages(['states' => 'Unknown Ticket action: '.$action.'.']);
                }
                if (! in_array(($policy['mode'] ?? 'inherit'), ['inherit', 'hidden', 'blocked', 'available', 'conditional'], true)) {
                    throw ValidationException::withMessages(['states' => 'Unknown action policy mode for '.$action.'.']);
                }

                $this->validateTree($policy['requirements'] ?? [], $catalog);
            }
        }

        foreach (($definition['transitions'] ?? []) as $transition) {
            if (blank($transition['transition_key'] ?? null)) {
                throw ValidationException::withMessages(['transitions' => 'Every transition needs a stable key.']);
            }
            if (! $keys->contains($transition['from_state_key'] ?? null) || ! $keys->contains($transition['to_state_key'] ?? null)) {
                throw ValidationException::withMessages(['transitions' => 'Every transition must reference two existing states.']);
            }

            if (($transition['from_state_key'] ?? null) === ($transition['to_state_key'] ?? null)) {
                throw ValidationException::withMessages(['transitions' => 'A transition cannot return to the same state.']);
            }

            $this->validateTree($transition['requirements'] ?? [], $catalog);
            $this->validateCustomerNotification($transition['customer_notification'] ?? null);
        }

        foreach (($definition['escalation_paths'] ?? []) as $path) {
            if (blank($path['path_key'] ?? null)) {
                throw ValidationException::withMessages(['escalation_paths' => 'Every escalation path needs a stable key.']);
            }
            if (! $keys->contains($path['from_state_key'] ?? null)) {
                throw ValidationException::withMessages(['escalation_paths' => 'Every escalation path must start in an existing state.']);
            }

            if ($workflow && (int) ($path['target_workflow_id'] ?? 0) === (int) $workflow->id
                && ($path['target_state_key'] ?? null) === ($path['from_state_key'] ?? null)) {
                throw ValidationException::withMessages(['escalation_paths' => 'An escalation cannot target the same workflow state.']);
            }

            $this->validateTree($path['requirements'] ?? [], $catalog);

            $target = TicketWorkflow::query()->with('publishedVersion')->where('is_active', true)->find($path['target_workflow_id'] ?? null);
            if (! $target || ! $target->publishedVersion) {
                throw ValidationException::withMessages(['escalation_paths' => 'Every target workflow must be active and published.']);
            }
            if (! collect($target->publishedVersion->definition['states'] ?? [])->contains('state_key', $path['target_state_key'] ?? null)) {
                throw ValidationException::withMessages(['escalation_paths' => 'The target workflow state is not present in its published version.']);
            }
            foreach (($path['protected_actions'] ?? []) as $action) {
                if (! isset(TicketAction::definitions()[$action])) {
                    throw ValidationException::withMessages(['escalation_paths' => 'Unknown protected Ticket action: '.$action.'.']);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $tree
     * @return array{match: string, groups: array<int, array<string, mixed>>}
     */
    public function tree(?array $tree): array
    {
        return [
            'match' => in_array(($tree['match'] ?? 'all'), ['all', 'any'], true) ? $tree['match'] : 'all',
            'groups' => array_values(array_filter($tree['groups'] ?? [], 'is_array')),
        ];
    }

    /**
     * Keep pre-versioned workflows functional while their boolean requirement
     * flags are gradually replaced by the grouped requirement editor.
     *
     * @param  object  $source  A legacy workflow state or transition model.
     * @return array{match: string, groups: array<int, array<string, mixed>>}
     */
    private function requirementsWithLegacyFlags(?array $tree, object $source): array
    {
        $normalized = $this->tree($tree);
        if ($normalized['groups'] !== []) {
            return $normalized;
        }

        $conditions = collect([
            'ticket.internal_note' => (bool) ($source->requires_note ?? false),
            'ticket.technician_response' => (bool) ($source->requires_response ?? false),
            'ticket.solution' => (bool) ($source->requires_resolution ?? false),
            'ticket.knowledge_follow_up' => (bool) ($source->requires_knowledge_update ?? false),
        ])->filter()->keys()->map(fn (string $fact) => [
            'fact' => $fact,
            'operator' => 'is_true',
            'value' => null,
        ])->values()->all();

        return [
            'match' => 'all',
            'groups' => $conditions === [] ? [] : [[
                'match' => 'all',
                'conditions' => $conditions,
            ]],
        ];
    }

    /** @return array{requires_note: bool, requires_response: bool, requires_resolution: bool, requires_knowledge_update: bool} */
    private function legacyFlagsForTree(array $tree): array
    {
        $facts = collect($tree['groups'] ?? [])->flatMap(function (array $group): array {
            $own = collect($group['conditions'] ?? [])->pluck('fact')->filter()->all();
            $nested = collect($group['groups'] ?? [])->flatMap(fn (array $child) => collect($child['conditions'] ?? [])->pluck('fact'))->filter()->all();

            return array_merge($own, $nested);
        })->unique();

        return [
            'requires_note' => $facts->contains('ticket.internal_note'),
            'requires_response' => $facts->contains('ticket.technician_response'),
            'requires_resolution' => $facts->contains('ticket.solution'),
            'requires_knowledge_update' => $facts->contains('ticket.knowledge_follow_up'),
        ];
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  array<string, array<string, mixed>>  $catalog
     */
    private function validateTree(array $tree, array $catalog): void
    {
        foreach (($tree['groups'] ?? []) as $group) {
            foreach (($group['conditions'] ?? []) as $condition) {
                $fact = (string) ($condition['fact'] ?? '');
                if ($fact === '' || ! isset($catalog[$fact])) {
                    throw ValidationException::withMessages(['requirements' => 'Unknown workflow requirement fact: '.($fact ?: '(empty)').'.']);
                }

                $operator = (string) ($condition['operator'] ?? 'is_true');
                if (! in_array($operator, $catalog[$fact]['operators'] ?? ['is_true'], true)) {
                    throw ValidationException::withMessages(['requirements' => 'Operator '.$operator.' is not valid for '.$fact.'.']);
                }
            }

            $this->validateTree(['groups' => $group['groups'] ?? []], $catalog);
        }
    }

    /** @param array<string, mixed>|null $policy */
    private function validateCustomerNotification(?array $policy): void
    {
        $rawChannels = array_values($policy['channels'] ?? []);
        $supported = array_keys(TicketWorkflowCustomerNotificationPolicy::channelDefinitions());
        $invalid = array_diff($rawChannels, $supported);

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'transitions' => 'Unknown customer notification channel: '.implode(', ', $invalid).'.',
            ]);
        }

        $normalized = TicketWorkflowCustomerNotificationPolicy::normalize($policy);
        if (! $normalized['enabled']) {
            return;
        }

        if ($normalized['channels'] === []) {
            throw ValidationException::withMessages([
                'transitions' => 'Choose at least one customer notification channel.',
            ]);
        }

        if (mb_strlen((string) ($normalized['message'] ?? '')) > 2000) {
            throw ValidationException::withMessages([
                'transitions' => 'The customer status message may not be longer than 2000 characters.',
            ]);
        }

        if (in_array(TicketWorkflowCustomerNotificationPolicy::CHANNEL_EMAIL, $normalized['channels'], true)) {
            $this->defaultEmailTemplates->handle();
            $templateExists = EmailTemplate::query()
                ->where('scope', 'tickets')
                ->where('key', $normalized['email_template_key'])
                ->where('is_active', true)
                ->exists();

            if (! $templateExists) {
                throw ValidationException::withMessages([
                    'transitions' => 'Choose an active Ticket email template for the customer status update.',
                ]);
            }
        }
    }

    public function stableKey(string $name, string $prefix = 'state'): string
    {
        return $prefix.'-'.(Str::slug($name) ?: 'item').'-'.Str::lower(Str::random(8));
    }
}
