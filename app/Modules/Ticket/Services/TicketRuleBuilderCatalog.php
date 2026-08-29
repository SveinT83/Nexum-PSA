<?php

namespace App\Modules\Ticket\Services;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketWorkflowVersion;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleFieldRegistry;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class TicketRuleBuilderCatalog
{
    public const MAX_WORKFLOW_VERSIONS = 50;

    public const MAX_WORKFLOW_STATES = 100;

    public const MAX_WORKFLOW_TRANSITIONS = 100;

    public const MAX_CUSTOM_FIELDS = 100;

    public const MAX_CUSTOM_FIELD_OPTIONS = 100;

    public const MAX_SPECIALIZED_CATALOG_BYTES = 8388608;

    private const MAX_KEY_BYTES = 191;

    private const MAX_LABEL_CHARACTERS = 160;

    public function __construct(
        private readonly TicketRuleTriggerRegistry $triggers,
        private readonly TicketRuleFieldRegistry $fields,
        private readonly TicketRuleActionProviderRegistry $actions,
        private readonly TicketCustomFieldTargetValidator $customFields,
        private readonly CustomFieldModelRegistry $customFieldModels,
    ) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $operator = auth()->user();
        $workflowCandidates = TicketWorkflowVersion::query()
            ->with('workflow')
            ->where('status', 'published')
            ->whereHas('workflow', fn (Builder $query) => $query->where('is_active', true))
            ->orderByDesc('id')
            ->limit(self::MAX_WORKFLOW_VERSIONS + 1)
            ->get();
        $workflowsTruncated = $workflowCandidates->count() > self::MAX_WORKFLOW_VERSIONS;
        $workflows = $workflowCandidates->take(self::MAX_WORKFLOW_VERSIONS)->values();

        $customFieldCandidates = $this->authorizedCustomFieldQuery($operator)
            ->orderBy('label')
            ->limit(self::MAX_CUSTOM_FIELDS + 1)
            ->get();
        $visibleCustomFields = $customFieldCandidates
            ->filter(fn (CustomFieldDefinition $definition): bool => $operator instanceof User
                && $this->customFields->canViewDefinitionId($definition->id, $operator));
        $customFieldsTruncated = $visibleCustomFields->count() > self::MAX_CUSTOM_FIELDS;

        $catalog = [
            'triggers' => collect($this->triggers->definitions())->map(
                fn (array $definition, string $key): array => [
                    'key' => $key,
                    'label' => $definition['label'],
                    'filter_schema' => $definition['filter_schema'],
                    'enabled' => $this->triggers->enabled($key),
                ],
            )->values()->all(),
            'facts' => collect($this->fields->conditionFacts())->map(
                fn (array $definition, string $key): array => $definition + ['key' => $key],
            )->values()->all(),
            'action_fields' => collect($this->fields->standardActionFields())->map(
                fn (array $definition, string $key): array => $definition + ['key' => $key],
            )->values()->all(),
            'actions' => collect($this->actions->definitions())->map(
                fn (array $definition, string $key): array => [
                    'key' => $key,
                    'label' => $definition['label'],
                    'help' => $definition['help'],
                    'input_schema' => $definition['input_schema'],
                    'permitted_triggers' => array_values((array) $definition['permitted_triggers']),
                    'enabled' => $this->actions->enabled($key),
                ],
            )->values()->all(),
            'workflow_transitions' => $this->workflowTransitions($workflows),
            'references' => [
                'ticket_type.active' => $this->options(TicketType::query()->where('is_active', true)->orderBy('name')),
                'ticket_queue.active' => $this->options(TicketQueue::query()->where('is_active', true)->orderBy('name')),
                'ticket_priority.active' => $this->options(TicketPriority::query()->where('is_active', true)->orderBy('name')),
                'sla.available' => $this->options(Sla::query()->orderBy('name')),
                'taxonomy.ticket_category.active' => $this->options(Category::query()->forTickets()->active()->orderBy('name')),
                'taxonomy.tag.active_for_ticket' => $this->options(Tag::query()->where('active', true)->orderBy('name')),
                'client.available' => $this->options(Client::query()->where('active', true)->orderBy('name')),
                'client_site.same_work_context' => $this->options(ClientSite::query()->orderBy('name')),
                'client_contact.same_work_context' => $this->options(ClientUser::query()->where('active', true)->orderBy('name')),
                'asset.same_work_context' => $this->options(Asset::query()->orderBy('name')),
                'user.active_workflow_eligible_same_context' => $this->options(
                    User::query()->where('status', User::STATUS_ACTIVE)->where('is_system_actor', false)->orderBy('name')
                ),
                'ticket_status.active' => $this->options(TicketStatus::query()->where('is_active', true)->orderBy('name')),
                'ticket_workflow_version.published' => $workflows
                    ->map(fn (TicketWorkflowVersion $version): array => $this->workflowVersion($version))->all(),
            ],
            'custom_fields' => $visibleCustomFields
                ->take(self::MAX_CUSTOM_FIELDS)
                ->map(fn (CustomFieldDefinition $definition): array => $this->customField($definition))
                ->values()
                ->all(),
            'tickets' => $this->tickets($operator),
            'limits' => [
                'workflow_versions_truncated' => $workflowsTruncated,
                'custom_fields_truncated' => $customFieldsTruncated,
            ],
        ];

        $specialized = json_encode([
            'workflow_transitions' => $catalog['workflow_transitions'],
            'workflow_versions' => $catalog['references']['ticket_workflow_version.published'],
            'custom_fields' => $catalog['custom_fields'],
        ], JSON_THROW_ON_ERROR);
        if (strlen($specialized) > self::MAX_SPECIALIZED_CATALOG_BYTES) {
            throw new \RuntimeException('The Rule Builder selector catalog is too large to load safely.');
        }

        return $catalog;
    }

    /**
     * Transition keys are interpreted against the Ticket's current Workflow.
     * Collapse duplicate keys so the selector never implies that a version is pinned.
     *
     * @param  Collection<int, TicketWorkflowVersion>  $workflows
     * @return list<array{value: string, label: string}>
     */
    private function workflowTransitions(Collection $workflows): array
    {
        return $workflows
            ->flatMap(function (TicketWorkflowVersion $version): array {
                $workflow = $this->label($version->workflow?->name ?? 'Workflow');

                return collect((array) ($version->definition['transitions'] ?? []))
                    ->take(self::MAX_WORKFLOW_TRANSITIONS)
                    ->map(function (mixed $transition) use ($workflow): ?array {
                        if (! is_array($transition)) {
                            return null;
                        }

                        $key = $this->boundedKey($transition['transition_key'] ?? null);

                        return $key === null ? null : [
                            'value' => $key,
                            'workflow' => $workflow,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();
            })
            ->groupBy('value')
            ->map(function (Collection $items, string $key): array {
                $workflows = $items->pluck('workflow')->filter()->unique()->sort()->values();
                $shown = $workflows->take(3);
                $remaining = $workflows->count() - $shown->count();
                $support = $shown->isEmpty() ? '' : ' · available in '.$shown->join(', ');
                if ($remaining > 0) {
                    $support .= ' and '.$remaining.' more';
                }

                return [
                    'value' => $key,
                    'label' => $this->label($key." · exact key on the Ticket's current Workflow".$support),
                ];
            })
            ->sortBy('label')
            ->values()
            ->all();
    }

    /** @return array{value: int, label: string, states: list<array{value: string, label: string}>, transitions: list<array{value: string, label: string}>} */
    private function workflowVersion(TicketWorkflowVersion $version): array
    {
        $workflow = $this->label($version->workflow?->name ?? 'Workflow');
        $states = collect((array) ($version->definition['states'] ?? []))
            ->take(self::MAX_WORKFLOW_STATES)
            ->map(function (mixed $state): ?array {
                if (! is_array($state)) {
                    return null;
                }

                $key = $this->boundedKey($state['state_key'] ?? null);

                return $key === null ? null : [
                    'value' => $key,
                    'label' => $this->label($state['name'] ?? $key),
                ];
            })
            ->filter()
            ->values()
            ->all();
        $transitions = collect((array) ($version->definition['transitions'] ?? []))
            ->take(self::MAX_WORKFLOW_TRANSITIONS)
            ->map(function (mixed $transition) use ($workflow): ?array {
                if (! is_array($transition)) {
                    return null;
                }

                $key = $this->boundedKey($transition['transition_key'] ?? null);

                return $key === null ? null : [
                    'value' => $key,
                    'label' => $this->label(($transition['name'] ?? $key).' · '.$workflow),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'value' => (int) $version->id,
            'label' => $this->label($workflow.' · v'.$version->version),
            'states' => $states,
            'transitions' => $transitions,
        ];
    }

    /**
     * Apply the coarse view boundary before LIMIT so restricted definition counts
     * never become a truncation oracle. The exact target validator still runs on
     * the bounded result.
     */
    private function authorizedCustomFieldQuery(mixed $operator): Builder
    {
        $storageTypes = $this->customFieldModels->storageTypesFor(Ticket::class);
        $query = CustomFieldDefinition::query()
            ->where('active', true)
            ->whereIn('model_type', $storageTypes)
            ->whereNotExists(function ($duplicate) use ($storageTypes): void {
                $duplicate
                    ->selectRaw('1')
                    ->from('custom_field_definitions as duplicate')
                    ->whereColumn('duplicate.id', '!=', 'custom_field_definitions.id')
                    ->whereColumn('duplicate.key', 'custom_field_definitions.key')
                    ->whereIn('duplicate.model_type', $storageTypes)
                    ->where('duplicate.active', true)
                    ->whereNull('duplicate.deleted_at');
            });

        if (! $operator instanceof User || ! $operator->isActive()) {
            return $query->whereRaw('1 = 0');
        }
        if (! $operator->hasAnyRole(['Admin', 'Superuser'])) {
            $query->where('admin_only', false);
        }

        $permissions = $operator->getAllPermissions()
            ->pluck('name')
            ->filter(fn (mixed $permission): bool => is_string($permission) && $permission !== '')
            ->values()
            ->all();

        return $query->where(function (Builder $query) use ($permissions): void {
            $query->whereNull('view_permission')->orWhere('view_permission', '');
            if ($permissions !== []) {
                $query->orWhereIn('view_permission', $permissions);
            }
        });
    }

    /**
     * Ticket subjects are private operational data. Never hydrate them for a
     * Rule manager who lacks ordinary Ticket visibility.
     *
     * @return list<array{value: int, label: string}>
     */
    private function tickets(mixed $operator): array
    {
        if (! $operator instanceof User || ! $operator->isActive() || ! $operator->can('ticket.view')) {
            return [];
        }

        return Ticket::query()
            ->whereNotNull('work_context_id')
            ->whereHas('workContext')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Ticket $ticket): array => [
                'value' => (int) $ticket->id,
                'label' => $this->label(trim(($ticket->ticket_key ?? '#'.$ticket->id).' · '.$ticket->subject)),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function customField(CustomFieldDefinition $definition): array
    {
        $target = $this->customFields->targetFor($definition);
        $facts = collect([
            TicketCustomFieldTargetValidator::CURRENT,
            TicketCustomFieldTargetValidator::BEFORE,
            TicketCustomFieldTargetValidator::AFTER,
            TicketCustomFieldTargetValidator::CHANGED,
            TicketCustomFieldTargetValidator::PRESENT,
        ])->map(function (string $key) use ($target): array {
            $fact = $this->customFields->conditionFact($key, $target) ?? [];

            return [
                'key' => $key,
                'label' => $this->label($fact['label'] ?? $key),
                'value_type' => (string) ($fact['value_type'] ?? 'string'),
                'condition_operators' => array_values((array) ($fact['condition_operators'] ?? [])),
            ];
        })->values()->all();

        return [
            'value' => (int) $definition->id,
            'label' => $this->label($definition->label),
            'field_type' => (string) $definition->field_type,
            'target' => $target,
            'options' => collect((array) $definition->options)
                ->take(self::MAX_CUSTOM_FIELD_OPTIONS)
                ->map(function (mixed $option): ?array {
                    if (is_array($option)) {
                        $value = $option['value'] ?? $option['key'] ?? $option['label'] ?? null;
                        $label = $option['label'] ?? $value;
                    } else {
                        $value = is_scalar($option) ? $option : null;
                        $label = $value;
                    }

                    $key = $this->boundedKey($value);

                    return $key === null ? null : [
                        'value' => $key,
                        'label' => $this->label($label),
                    ];
                })
                ->filter()
                ->values()
                ->all(),
            'facts' => $facts,
        ];
    }

    private function boundedKey(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $key = trim((string) $value);

        return $key !== '' && strlen($key) <= self::MAX_KEY_BYTES ? $key : null;
    }

    private function label(mixed $value): string
    {
        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return 'Unavailable';
        }

        $label = trim((string) $value);
        $label = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $label) ?? 'Unavailable';

        return Str::limit($label !== '' ? $label : 'Unnamed', self::MAX_LABEL_CHARACTERS - 3, '...');
    }

    /** @return list<array{value: int, label: string}> */
    private function options(Builder $query): array
    {
        return $query->limit(500)->get()->map(function ($model): array {
            $label = $model->name
                ?? $model->label
                ?? $model->title
                ?? $model->email
                ?? class_basename($model).' #'.$model->getKey();

            return ['value' => (int) $model->getKey(), 'label' => $this->label($label)];
        })->values()->all();
    }
}
