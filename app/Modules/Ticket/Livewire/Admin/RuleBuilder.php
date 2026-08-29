<?php

namespace App\Modules\Ticket\Livewire\Admin;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\PublishTicketRuleDraft;
use App\Modules\Ticket\Actions\SaveTicketRuleDraft;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Services\TicketRuleBuilderCatalog;
use App\Modules\Ticket\Services\TicketRuleBuilderPreviewPresenter;
use App\Modules\Ticket\Services\TicketRuleDefinitionCanonicalizer;
use App\Modules\Ticket\Services\TicketRulePreviewService;
use App\Modules\Ticket\Services\TicketRulePublicationTargetValidator;
use App\Modules\Ticket\Services\TicketRulePublishedDefinitionValidator;
use App\Modules\Ticket\Services\TicketRuleRuntimeGate;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

final class RuleBuilder extends Component
{
    #[Locked]
    public ?int $ruleId = null;

    #[Locked]
    public ?string $creationToken = null;

    public string $name = '';

    public ?string $description = null;

    public string $trigger = TicketRuleTriggerRegistry::CREATED;

    public array $triggerFilters = [];

    public string $conditionMode = 'always';

    public string $rootMatch = 'ALL';

    public array $groups = [];

    public array $thenActions = [];

    public array $elseActions = [];

    public bool $stopProcessing = false;

    public int $weight = 100;

    public ?int $previewTicketId = null;

    public array $previewContext = [];

    #[Locked]
    public ?array $previewResult = null;

    #[Locked]
    public ?string $draftChecksum = null;

    #[Locked]
    public bool $expectedNoDraft = false;

    #[Locked]
    public array $catalog = [];

    #[Locked]
    public array $unknownDescriptors = [];

    #[Locked]
    public bool $hasUnknownRoot = false;

    #[Locked]
    public bool $hasUnknownTriggerFilters = false;

    #[Locked]
    public ?string $sourceDefinitionChecksum = null;

    #[Locked]
    public ?int $sourcePublishedVersionId = null;

    #[Locked]
    public bool $legacySource = false;

    public ?string $notice = null;

    public ?string $error = null;

    /** @var array<string, mixed>|null */
    private ?array $sourceDefinitionCache = null;

    public function mount(
        ?int $ruleId,
        TicketRuleBuilderCatalog $catalog,
        TicketRuleDefinitionCanonicalizer $canonicalizer,
        TicketRulePublicationTargetValidator $targets,
    ): void {
        $this->authorizeManager();
        $operator = $this->operator();
        $this->catalog = $catalog->get();
        $this->ruleId = $ruleId;

        if ($ruleId === null) {
            $this->creationToken = (string) Str::uuid();
            $this->name = 'New Ticket Rule';
            $this->addGroup();
            $this->conditionMode = 'always';
            $this->previewContext = $this->defaultPreviewContext($this->trigger);

            return;
        }

        $rule = TicketRule::query()->with('publishedVersion')->findOrFail($ruleId);
        $this->draftChecksum = $rule->draft_checksum;
        $this->expectedNoDraft = $rule->draft_checksum === null;

        if (is_array($rule->draft_payload_json)) {
            $payload = $rule->draft_payload_json;
            $this->authorizeDefinitionTargets(
                $targets,
                (array) ($payload['definition'] ?? []),
                $operator,
            );
            $this->name = (string) ($payload['name'] ?? $rule->name);
            $this->description = $payload['description'] ?? null;
            $this->loadDefinition((array) ($payload['definition'] ?? []));

            return;
        }

        if ((int) $rule->publishedVersion?->definition_schema_version
            === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION) {
            $this->authorizeDefinitionTargets(
                $targets,
                (array) $rule->publishedVersion->definition_json,
                $operator,
            );
            $this->name = (string) $rule->publishedVersion->name;
            $this->description = $rule->publishedVersion->description;
            $this->loadDefinition((array) $rule->publishedVersion->definition_json);
            $this->sourcePublishedVersionId = (int) $rule->publishedVersion->id;

            return;
        }

        $inspection = $canonicalizer->inspect($rule);
        $this->name = $rule->name;
        $this->description = $rule->description;
        $this->legacySource = true;

        if (($inspection['status'] ?? null) === TicketRuleDefinitionCanonicalizer::STATUS_VALID) {
            $this->loadLegacyDefinition((array) $inspection['definition']);
        } else {
            $definition = $this->legacyFallbackDefinition($rule);
            $this->startSourceDefinition($definition);
            $this->conditionMode = 'grouped';
            $this->groups = [$this->unknownGroup(
                data_get($definition, 'conditions.groups.0'),
                'conditions.groups.0',
            )];
            $this->thenActions = collect((array) ($definition['then_actions'] ?? []))
                ->map(fn (mixed $action, int $index): array => $this->unknownAction(
                    $action,
                    'then_actions.'.$index,
                    'then_action',
                ))
                ->values()
                ->all();
            $this->weight = (int) $rule->weight;
            $this->stopProcessing = (bool) $rule->stop_processing;
        }
    }

    public function hydrate(): void
    {
        $this->authorizeManager();
    }

    public function addGroup(): void
    {
        $this->conditionMode = 'grouped';
        $this->groups[] = [
            '_key' => (string) Str::uuid(),
            'match' => 'ALL',
            'conditions' => [],
        ];
        $key = (string) $this->groups[array_key_last($this->groups)]['_key'];
        $this->dispatch('ticket-rule-builder-focus', target: 'condition-group-'.$key);
    }

    public function removeGroup(int $index): void
    {
        if (($this->groups[$index]['_unknown'] ?? false) === true
            || $this->removalWouldShiftUnknown($this->groups, $index)) {
            return;
        }

        if (isset($this->groups[$index])) {
            array_splice($this->groups, $index, 1);
        }
        if ($this->groups === []) {
            $this->conditionMode = 'always';
        }

        $key = $this->groups[min($index, max(0, count($this->groups) - 1))]['_key'] ?? null;
        $this->dispatch(
            'ticket-rule-builder-focus',
            target: $key ? 'condition-group-'.$key : 'condition-add-group',
        );
    }

    public function moveGroup(int $index, int $direction): void
    {
        if (($this->groups[$index]['_unknown'] ?? false) === true) {
            return;
        }

        $key = $this->groups[$index]['_key'] ?? null;
        $this->move($this->groups, $index, $direction);
        if ($key) {
            $this->dispatch('ticket-rule-builder-focus', target: 'condition-group-'.$key);
        }
    }

    public function addCondition(int $groupIndex): void
    {
        if (! isset($this->groups[$groupIndex]) || ($this->groups[$groupIndex]['_unknown'] ?? false)) {
            return;
        }

        $this->groups[$groupIndex]['conditions'][] = [
            '_key' => (string) Str::uuid(),
            'field' => 'subject',
            'operator' => 'contains',
            'value' => '',
        ];
        $key = (string) $this->groups[$groupIndex]['conditions'][array_key_last($this->groups[$groupIndex]['conditions'])]['_key'];
        $this->dispatch('ticket-rule-builder-focus', target: 'condition-row-'.$key);
    }

    public function removeCondition(int $groupIndex, int $conditionIndex): void
    {
        $conditions = (array) ($this->groups[$groupIndex]['conditions'] ?? []);
        if (($this->groups[$groupIndex]['_unknown'] ?? false) === true
            || ($conditions[$conditionIndex]['_unknown'] ?? false) === true
            || $this->removalWouldShiftUnknown($conditions, $conditionIndex)) {
            return;
        }

        if (isset($this->groups[$groupIndex]['conditions'][$conditionIndex])) {
            array_splice($this->groups[$groupIndex]['conditions'], $conditionIndex, 1);
        }

        $groupKey = $this->groups[$groupIndex]['_key'] ?? null;
        if ($groupKey) {
            $this->dispatch('ticket-rule-builder-focus', target: 'condition-group-'.$groupKey);
        }
    }

    public function moveCondition(int $groupIndex, int $conditionIndex, int $direction): void
    {
        $key = $this->groups[$groupIndex]['conditions'][$conditionIndex]['_key'] ?? null;
        if (($this->groups[$groupIndex]['_unknown'] ?? false) === true
            || ($this->groups[$groupIndex]['conditions'][$conditionIndex]['_unknown'] ?? false) === true) {
            return;
        }

        if (isset($this->groups[$groupIndex]['conditions'])) {
            $this->move($this->groups[$groupIndex]['conditions'], $conditionIndex, $direction);
        }
        if ($key) {
            $this->dispatch('ticket-rule-builder-focus', target: 'condition-row-'.$key);
        }
    }

    public function addAction(string $branch): void
    {
        $property = $branch === 'else' ? 'elseActions' : 'thenActions';
        $this->{$property}[] = $this->newAction(TicketRuleActionProviderRegistry::SET_QUEUE);
        $key = (string) $this->{$property}[array_key_last($this->{$property})]['_key'];
        $this->dispatch('ticket-rule-builder-focus', target: $branch.'-action-toggle-'.$key);
    }

    public function removeAction(string $branch, int $index): void
    {
        $property = $branch === 'else' ? 'elseActions' : 'thenActions';
        if (isset($this->{$property}[$index])) {
            if (($this->{$property}[$index]['_unknown'] ?? false) === true
                || $this->removalWouldShiftUnknown($this->{$property}, $index)) {
                return;
            }

            array_splice($this->{$property}, $index, 1);
        }

        $key = $this->{$property}[min($index, max(0, count($this->{$property}) - 1))]['_key'] ?? null;
        $this->dispatch(
            'ticket-rule-builder-focus',
            target: $key ? $branch.'-action-toggle-'.$key : $branch.'-add-action',
        );
    }

    public function moveAction(string $branch, int $index, int $direction): void
    {
        $property = $branch === 'else' ? 'elseActions' : 'thenActions';
        $items = $this->{$property};
        if (($this->{$property}[$index]['_unknown'] ?? false) === true) {
            return;
        }

        $key = $items[$index]['_key'] ?? null;
        $this->move($items, $index, $direction);
        $this->{$property} = $items;
        if ($key) {
            $this->dispatch('ticket-rule-builder-focus', target: $branch.'-action-toggle-'.$key);
        }
    }

    public function setActionType(string $branch, int $index, string $type): void
    {
        $property = $branch === 'else' ? 'elseActions' : 'thenActions';
        if (isset($this->{$property}[$index])) {
            if (($this->{$property}[$index]['_unknown'] ?? false) === true) {
                return;
            }

            $this->{$property}[$index] = $this->newAction($type);
            $this->dispatch(
                'ticket-rule-builder-focus',
                target: $branch.'-action-toggle-'.$this->{$property}[$index]['_key'],
            );
        }
    }

    public function setTrigger(string $trigger): void
    {
        if (collect($this->catalog['triggers'])->contains('key', $trigger)) {
            $this->trigger = $trigger;
            $this->triggerFilters = [];
            $this->previewContext = $this->defaultPreviewContext($trigger);
        }
    }

    /**
     * Normalize dependent typed state after a selector changes so hidden stale
     * values can never be serialized under a different field or target type.
     */
    public function updated(string $property, mixed $value = null): void
    {
        if (preg_match('/^groups\.(\d+)\.conditions\.(\d+)\.(field|definition_id|operator)$/', $property, $matches)) {
            $this->normalizeConditionSelection(
                (int) $matches[1],
                (int) $matches[2],
                $matches[3] !== 'operator',
            );

            return;
        }

        if (preg_match('/^(thenActions|elseActions)\.(\d+)\.input\.(_field_key|definition_id|target_workflow_version_id)$/', $property, $matches)) {
            $this->normalizeActionSelection($matches[1], (int) $matches[2], $matches[3]);

            return;
        }

        if ($property === 'previewContext.definition_id') {
            $this->previewContext['before_value'] = null;
            $this->previewContext['after_value'] = null;
        }

        if ($property === 'previewContext.workflow_version_id') {
            $this->previewContext['workflow_state_key'] = null;
        }
    }

    public function saveDraft(
        SaveTicketRuleDraft $save,
        bool $showNotice = true,
    ): void {
        $this->resetFeedback();
        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:10000'],
            'weight' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        try {
            $rule = $this->ruleId ? TicketRule::query()->findOrFail($this->ruleId) : null;
            $saved = $save->handle(
                $rule,
                [
                    'name' => $this->name,
                    'description' => $this->description,
                    'definition' => $this->definition(),
                ],
                $this->operator(),
                $this->draftChecksum,
                $this->expectedNoDraft,
                $this->creationToken,
            );
            $this->ruleId = (int) $saved->id;
            $this->expectedNoDraft = false;
            $sourceDefinition = (array) data_get($saved->draft_payload_json, 'definition', []);
            $this->sourceDefinitionChecksum = TicketRuleStableJson::checksum($sourceDefinition);
            $this->sourceDefinitionCache = $sourceDefinition;
            $this->sourcePublishedVersionId = null;
            $this->legacySource = false;
            if ($showNotice) {
                $this->notice = 'Draft saved. Runtime behavior was not changed.';
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error = $this->safeMessage($exception);
        }
    }

    public function preview(
        TicketRulePreviewService $preview,
        TicketRuleBuilderPreviewPresenter $presenter,
    ): void {
        $this->resetFeedback();
        $this->previewResult = null;

        try {
            if (! $this->previewTicketId) {
                throw new \RuntimeException('Choose a Ticket for the preview.');
            }
            $ticket = Ticket::query()->findOrFail($this->previewTicketId);
            $draftResult = $preview->draft(
                $ticket,
                $this->definition(),
                $this->operator(),
                $this->typedPreviewContext(),
            );
            if ($this->trigger === TicketRuleTriggerRegistry::CREATED) {
                $draftResult['queue_preview'] = $preview->created(
                    $ticket,
                    [
                        'channel' => $ticket->channel,
                        'subject' => $ticket->subject,
                        'description' => $ticket->description,
                        '_source_action' => 'TicketRuleBuilderPublishedQueuePreview',
                    ],
                    $this->operator(),
                );
                $draftResult['queue_preview_scope'] = 'published_rules_only';
            }
            $this->previewResult = $presenter->present($draftResult);
            $this->notice = 'Preview completed with zero writes.';
        } catch (Throwable $exception) {
            report($exception);
            $this->error = $this->safeMessage($exception);
        }
    }

    public function publish(
        SaveTicketRuleDraft $save,
        PublishTicketRuleDraft $publish,
    ): void {
        $this->saveDraft($save, false);
        if ($this->error !== null || ! $this->ruleId || ! $this->draftChecksum) {
            return;
        }

        try {
            $version = $publish->handle(
                TicketRule::query()->findOrFail($this->ruleId),
                $this->operator(),
                $this->draftChecksum,
            );
            $this->draftChecksum = null;
            $this->expectedNoDraft = true;
            $this->sourcePublishedVersionId = (int) $version->id;
            $this->sourceDefinitionChecksum = (string) $version->definition_checksum;
            $this->sourceDefinitionCache = (array) $version->definition_json;
            $this->legacySource = false;
            $this->notice = 'Published immutable version '.$version->version_number
                .'. Runtime authority and capability gates were not changed.';
        } catch (Throwable $exception) {
            report($exception);
            $this->error = $this->safeMessage($exception);
        }
    }

    public function publicationReady(): bool
    {
        try {
            $operator = $this->operator();
            if (! $operator->can('ticket.rule_publish')
                || ! (bool) config('ticket_rules.v2_enabled', false)
                || $this->hasUnknownNodes()) {
                return false;
            }

            $definition = $this->definition();
            $validation = app(TicketRulePublishedDefinitionValidator::class)
                ->validateForPublication($definition);
            if (($validation['status'] ?? null) !== TicketRulePublishedDefinitionValidator::STATUS_VALID
                || ! ($validation['publishable'] ?? false)) {
                return false;
            }

            $definition = (array) $validation['definition'];
            $targets = app(TicketRulePublicationTargetValidator::class);
            $targets->validate($definition);
            $targets->validateCustomFieldAccess($definition, $operator);
            $actor = app(TicketRuleRuntimeGate::class)->requireExistingActor();
            $providers = app(TicketRuleActionProviderRegistry::class);

            foreach (
                array_merge(
                    (array) ($definition['then_actions'] ?? []),
                    (array) ($definition['else_actions'] ?? []),
                ) as $action
            ) {
                $type = is_array($action) ? ($action['type'] ?? null) : null;
                $provider = $providers->definition($type);
                if ($provider === null
                    || ! $providers->enabled((string) $type)
                    || ! in_array($definition['trigger'], $provider['permitted_triggers'], true)
                    || ! $operator->can((string) $provider['publication_permission'])
                    || ! $actor->can((string) $provider['runtime_permission'])) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function render(): View
    {
        $this->authorizeManager();

        return view('ticket::livewire.admin.rule-builder');
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        $preserveUnknownGroups = collect($this->unknownDescriptors)->contains(
            fn (array $descriptor): bool => in_array(
                $descriptor['kind'] ?? null,
                ['group', 'condition'],
                true,
            ),
        );
        $this->assertUnknownPlaceholdersIntact();
        $groups = $this->conditionMode === 'always' && ! $preserveUnknownGroups
            ? []
            : collect($this->groups)->map(function (array $group): mixed {
                if ($this->isUnknownPlaceholder($group, 'group')) {
                    return $this->unknownValue($group, 'group');
                }

                return [
                    'match' => in_array($group['match'] ?? null, ['ALL', 'ANY'], true)
                        ? $group['match']
                        : 'ALL',
                    'conditions' => collect((array) ($group['conditions'] ?? []))
                        ->map(fn (array $condition): mixed => $this->serializeCondition($condition))
                        ->values()
                        ->all(),
                ];
            })->values()->all();

        $unknownRoot = $this->hasUnknownRoot
            ? (array) $this->unknownValueForKind('root')
            : [];

        return $unknownRoot + [
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => $this->trigger,
            'trigger_filters' => $this->serializeTriggerFilters(),
            'conditions' => [
                'mode' => $this->conditionMode === 'always' ? 'always' : 'grouped',
                'match' => $this->conditionMode === 'always' ? 'ALL' : $this->rootMatch,
                'groups' => $groups,
            ],
            'then_actions' => collect($this->thenActions)
                ->map(fn (array $action): mixed => $this->serializeAction($action, 'then_action'))
                ->values()->all(),
            'else_actions' => collect($this->elseActions)
                ->map(fn (array $action): mixed => $this->serializeAction($action, 'else_action'))
                ->values()->all(),
            'flow' => ['stop_processing' => $this->stopProcessing],
            'order' => ['weight' => (int) $this->weight],
        ];
    }

    /** @param array<string, mixed> $definition */
    private function loadDefinition(array $definition): void
    {
        $this->startSourceDefinition($definition);
        $unknownRoot = $this->unknownRootFrom($definition);
        if ($unknownRoot !== []) {
            $this->registerUnknownDescriptor('root', '', $unknownRoot);
            $this->hasUnknownRoot = true;
        }

        $this->trigger = is_string($definition['trigger'] ?? null)
            ? $definition['trigger']
            : TicketRuleTriggerRegistry::CREATED;
        $this->loadTriggerFilters((array) ($definition['trigger_filters'] ?? []));

        $tree = (array) ($definition['conditions'] ?? []);
        $this->conditionMode = ($tree['mode'] ?? null) === 'always' ? 'always' : 'grouped';
        $this->rootMatch = in_array($tree['match'] ?? null, ['ALL', 'ANY'], true) ? $tree['match'] : 'ALL';
        $this->groups = collect((array) ($tree['groups'] ?? []))
            ->map(fn (mixed $group, int $index): array => $this->normalizeGroup(
                $group,
                'conditions.groups.'.$index,
            ))
            ->values()
            ->all();
        $this->thenActions = collect((array) ($definition['then_actions'] ?? []))
            ->map(fn (mixed $action, int $index): array => $this->normalizeAction(
                $action,
                'then_actions.'.$index,
                'then_action',
            ))
            ->values()
            ->all();
        $this->elseActions = collect((array) ($definition['else_actions'] ?? []))
            ->map(fn (mixed $action, int $index): array => $this->normalizeAction(
                $action,
                'else_actions.'.$index,
                'else_action',
            ))
            ->values()
            ->all();
        $this->stopProcessing = (bool) data_get($definition, 'flow.stop_processing', false);
        $this->weight = (int) data_get($definition, 'order.weight', 100);
        $this->previewContext = $this->defaultPreviewContext($this->trigger);
    }

    /** @param array<string, mixed> $definition */
    private function loadLegacyDefinition(array $definition): void
    {
        $this->startSourceDefinition($definition);
        $conditions = (array) data_get($definition, 'conditions.groups.0.conditions', []);
        $this->trigger = TicketRuleTriggerRegistry::CREATED;
        $this->conditionMode = 'grouped';
        $this->rootMatch = 'ALL';
        $this->groups = [[
            '_key' => (string) Str::uuid(),
            'match' => 'ALL',
            'conditions' => collect($conditions)
                ->map(fn (mixed $condition, int $index): array => $this->normalizeCondition(
                    $condition,
                    'conditions.groups.0.conditions.'.$index,
                ))
                ->values()
                ->all(),
        ]];
        $this->thenActions = collect((array) ($definition['then_actions'] ?? []))
            ->map(fn (mixed $action, int $index): array => $this->unknownAction(
                $action,
                'then_actions.'.$index,
                'then_action',
            ))
            ->values()
            ->all();
        $this->elseActions = [];
        $this->stopProcessing = (bool) data_get($definition, 'flow.stop_processing', false);
        $this->weight = (int) data_get($definition, 'order.weight', 100);
        $this->previewContext = $this->defaultPreviewContext($this->trigger);
    }

    private function normalizeGroup(mixed $group, string $path): array
    {
        if (! is_array($group)
            || array_diff(array_keys($group), ['match', 'conditions']) !== []
            || array_diff(['match', 'conditions'], array_keys($group)) !== []
            || ! in_array($group['match'] ?? null, ['ALL', 'ANY'], true)
            || ! is_array($group['conditions'] ?? null)
            || ! array_is_list($group['conditions'])) {
            return $this->unknownGroup($group, $path);
        }

        return [
            '_key' => (string) Str::uuid(),
            'match' => $group['match'],
            'conditions' => collect($group['conditions'])
                ->map(fn (mixed $condition, int $index): array => $this->normalizeCondition(
                    $condition,
                    $path.'.conditions.'.$index,
                ))
                ->values()
                ->all(),
        ];
    }

    private function normalizeConditionSelection(int $groupIndex, int $conditionIndex, bool $resetValue): void
    {
        if (! isset($this->groups[$groupIndex]['conditions'][$conditionIndex])) {
            return;
        }

        $condition = &$this->groups[$groupIndex]['conditions'][$conditionIndex];
        if ($condition['_unknown'] ?? false) {
            return;
        }

        $field = (string) ($condition['field'] ?? '');
        if (str_starts_with($field, 'custom_field.')) {
            $customField = collect($this->catalog['custom_fields'])
                ->firstWhere('value', (int) ($condition['definition_id'] ?? 0));
            if (! $customField) {
                $customField = collect($this->catalog['custom_fields'])->first();
                $condition['definition_id'] = (int) ($customField['value'] ?? 0);
            }
            $fact = collect((array) ($customField['facts'] ?? []))->firstWhere('key', $field);
        } else {
            unset($condition['definition_id']);
            $fact = collect($this->catalog['facts'])->firstWhere('key', $field);
        }

        $operators = array_values((array) ($fact['condition_operators'] ?? []));
        if (! in_array($condition['operator'] ?? null, $operators, true)) {
            $condition['operator'] = (string) ($operators[0] ?? '');
            $resetValue = true;
        }

        $operator = (string) ($condition['operator'] ?? '');
        $valueType = (string) ($fact['value_type'] ?? 'string');
        if ($resetValue) {
            $condition['value'] = $this->emptyTypedValue($operator, $valueType);
        } elseif (in_array($operator, ['in', 'not_in', 'intersects'], true)
            && ! is_array($condition['value'] ?? null)) {
            $condition['value'] = [];
        } elseif (! in_array($operator, ['in', 'not_in', 'intersects'], true)
            && is_array($condition['value'] ?? null)) {
            $condition['value'] = '';
        } elseif ($operator === 'present') {
            $condition['value'] = null;
        }

        unset($condition);
    }

    private function normalizeActionSelection(string $property, int $index, string $selector): void
    {
        if (! isset($this->{$property}[$index]) || ($this->{$property}[$index]['_unknown'] ?? false)) {
            return;
        }

        $action = &$this->{$property}[$index];
        $input = &$action['input'];
        if ($selector === '_field_key') {
            $input['_field_value'] = '';
        } elseif ($selector === 'definition_id') {
            $customField = collect($this->catalog['custom_fields'])
                ->firstWhere('value', (int) ($input['definition_id'] ?? 0));
            $input['value'] = $this->emptyTypedValue(
                'equals',
                (string) ($customField['field_type'] ?? 'text'),
            );
        } elseif ($selector === 'target_workflow_version_id') {
            $input['target_state_key'] = null;
        }

        unset($input, $action);
    }

    private function emptyTypedValue(string $operator, string $valueType): mixed
    {
        if ($operator === 'present') {
            return null;
        }
        if (in_array($operator, ['in', 'not_in', 'intersects'], true)
            || in_array($valueType, ['multiselect', 'string_list', 'integer_list', 'positive_integer_list'], true)) {
            return [];
        }
        if (in_array($valueType, ['boolean', 'checkbox'], true)) {
            return '0';
        }

        return '';
    }

    private function normalizeCondition(mixed $condition, string $path): array
    {
        if (! is_array($condition) || ! is_string($condition['field'] ?? null)) {
            return $this->unknownCondition($condition, $path);
        }

        $field = $condition['field'];
        if (str_starts_with($field, 'custom_field.')) {
            if (array_diff(array_keys($condition), ['field', 'target', 'operator', 'value']) !== []
                || array_diff(['field', 'target', 'operator', 'value'], array_keys($condition)) !== []) {
                return $this->unknownCondition($condition, $path);
            }
            $customField = collect($this->catalog['custom_fields'])->first(
                fn (array $candidate): bool => $candidate['target'] === ($condition['target'] ?? null),
            );
            $fact = collect((array) ($customField['facts'] ?? []))->firstWhere('key', $field);
            if (! $customField
                || ! $fact
                || ! in_array($condition['operator'] ?? null, $fact['condition_operators'], true)) {
                return $this->unknownCondition($condition, $path);
            }

            return [
                '_key' => (string) Str::uuid(),
                'field' => $field,
                'definition_id' => (int) $customField['value'],
                'operator' => $condition['operator'],
                'value' => $condition['value'],
            ];
        }

        $fact = collect($this->catalog['facts'])->firstWhere('key', $field);
        if (! $fact
            || array_diff(array_keys($condition), ['field', 'operator', 'value']) !== []
            || array_diff(['field', 'operator', 'value'], array_keys($condition)) !== []
            || ! in_array($condition['operator'] ?? null, $fact['condition_operators'], true)) {
            return $this->unknownCondition($condition, $path);
        }

        return [
            '_key' => (string) Str::uuid(),
            'field' => $field,
            'operator' => $condition['operator'],
            'value' => $condition['value'],
        ];
    }

    private function normalizeAction(mixed $action, string $path, string $kind): array
    {
        if (! is_array($action)
            || array_diff(array_keys($action), ['type', 'input']) !== []
            || array_diff(['type', 'input'], array_keys($action)) !== []
            || ! is_array($action['input'])) {
            return $this->unknownAction($action, $path, $kind);
        }

        $provider = collect($this->catalog['actions'])->firstWhere('key', $action['type'] ?? null);
        $allowedInput = array_keys((array) data_get($provider, 'input_schema.properties', []));
        if (! $provider || array_diff(array_keys($action['input']), $allowedInput) !== []) {
            return $this->unknownAction($action, $path, $kind);
        }

        $input = $action['input'];
        if ($action['type'] === TicketRuleActionProviderRegistry::SET_TICKET_FIELDS) {
            $fields = (array) ($input['fields'] ?? []);
            if (count($fields) !== 1
                || ! collect($this->catalog['action_fields'])->contains('key', array_key_first($fields))) {
                return $this->unknownAction($action, $path, $kind);
            }
            $fieldKey = (string) array_key_first($fields);
            $input = ['_field_key' => $fieldKey, '_field_value' => $fields[$fieldKey]];
        } elseif (in_array($action['type'], [
            TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
            TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD,
        ], true)) {
            $customField = collect($this->catalog['custom_fields'])->first(
                fn (array $candidate): bool => $candidate['target'] === ($input['target'] ?? null),
            );
            if (! $customField) {
                return $this->unknownAction($action, $path, $kind);
            }
            $input['definition_id'] = (int) $customField['value'];
            unset($input['target']);
        }

        return [
            '_key' => (string) Str::uuid(),
            'type' => $action['type'],
            'input' => $input,
        ];
    }

    private function newAction(string $type): array
    {
        return [
            '_key' => (string) Str::uuid(),
            'type' => collect($this->catalog['actions'])->contains('key', $type)
                ? $type
                : TicketRuleActionProviderRegistry::SET_QUEUE,
            'input' => [],
        ];
    }

    private function unknownAction(mixed $action, string $path, string $kind): array
    {
        return [
            '_key' => $this->registerUnknownDescriptor($kind, $path, $action),
            '_unknown' => true,
            'type' => 'unsupported',
            'input' => [],
        ];
    }

    private function unknownCondition(mixed $condition, string $path): array
    {
        return [
            '_key' => $this->registerUnknownDescriptor('condition', $path, $condition),
            '_unknown' => true,
        ];
    }

    private function unknownGroup(mixed $group, string $path): array
    {
        return [
            '_key' => $this->registerUnknownDescriptor('group', $path, $group),
            '_unknown' => true,
            'match' => 'ALL',
            'conditions' => [],
        ];
    }

    private function serializeCondition(array $condition): mixed
    {
        if ($this->isUnknownPlaceholder($condition, 'condition')) {
            return $this->unknownValue($condition, 'condition');
        }

        $operator = (string) ($condition['operator'] ?? 'equals');
        $value = $operator === 'present'
            ? null
            : $this->typedConditionValue($condition, $condition['value'] ?? null, $operator);
        $serialized = [
            'field' => (string) $condition['field'],
            'operator' => $operator,
            'value' => $value,
        ];
        if (str_starts_with($serialized['field'], 'custom_field.')) {
            $serialized = [
                'field' => $serialized['field'],
                'target' => $this->customFieldTarget((int) ($condition['definition_id'] ?? 0)),
                'operator' => $operator,
                'value' => $value,
            ];
        }

        return $serialized;
    }

    private function serializeAction(array $action, string $kind): mixed
    {
        if ($this->isUnknownPlaceholder($action, $kind)) {
            return $this->unknownValue($action, $kind);
        }

        $type = (string) $action['type'];
        $input = (array) ($action['input'] ?? []);
        if ($type === TicketRuleActionProviderRegistry::SET_TICKET_FIELDS
            && isset($input['_field_key'])) {
            $fieldKey = (string) $input['_field_key'];
            $field = collect($this->catalog['action_fields'])->firstWhere('key', $fieldKey) ?? [];
            $input = ['fields' => [
                $fieldKey => $this->castByType(
                    $input['_field_value'] ?? null,
                    (string) ($field['value_type'] ?? 'string'),
                ),
            ]];
        }
        if (in_array($type, [
            TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
            TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD,
        ], true)) {
            $definitionId = (int) ($input['definition_id'] ?? 0);
            $customField = collect($this->catalog['custom_fields'])->firstWhere('value', $definitionId);
            $input['target'] = $this->customFieldTarget($definitionId);
            unset($input['definition_id']);
            if ($type === TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD) {
                $input['value'] = $this->castByType(
                    $input['value'] ?? null,
                    (string) ($customField['field_type'] ?? 'text'),
                );
            }
        }

        foreach ([
            'queue_id', 'owner_id', 'workflow_version_id', 'source_workflow_version_id',
            'target_workflow_version_id', 'confidence',
        ] as $integerKey) {
            if (array_key_exists($integerKey, $input) && $input[$integerKey] !== '') {
                $input[$integerKey] = (int) $input[$integerKey];
            }
        }
        if (isset($input['tag_ids']) && is_array($input['tag_ids'])) {
            $input['tag_ids'] = array_values(array_map('intval', $input['tag_ids']));
        }

        return ['type' => $type, 'input' => $this->cleanInput($input)];
    }

    /** @return array<string, mixed> */
    private function serializeTriggerFilters(): array
    {
        $filters = $this->triggerFilters;
        if ($this->trigger === TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED) {
            $filters['targets'] = collect((array) ($filters['definition_ids'] ?? []))
                ->map(fn (mixed $id): array => $this->customFieldTarget((int) $id))
                ->values()
                ->all();
            unset($filters['definition_ids']);
        }
        foreach ([
            'added_tag_ids', 'removed_tag_ids', 'workflow_version_ids', 'status_ids',
        ] as $integerList) {
            if (isset($filters[$integerList]) && is_array($filters[$integerList])) {
                $filters[$integerList] = array_values(array_map('intval', $filters[$integerList]));
            }
        }

        $unknown = $this->hasUnknownTriggerFilters
            ? (array) $this->unknownValueForKind('trigger_filters')
            : [];

        return $unknown + $this->cleanInput($filters);
    }

    /** @param array<string, mixed> $filters */
    private function loadTriggerFilters(array $filters): void
    {
        [$known, $unknown] = $this->splitTriggerFilters($filters, $this->trigger);
        if ($unknown !== []) {
            $this->registerUnknownDescriptor('trigger_filters', 'trigger_filters', $unknown);
            $this->hasUnknownTriggerFilters = true;
        }
        $this->triggerFilters = $known;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function splitTriggerFilters(array $filters, string $trigger): array
    {
        $triggerDefinition = collect($this->catalog['triggers'])->firstWhere('key', $trigger);
        $knownKeys = array_keys((array) data_get($triggerDefinition, 'filter_schema.properties', []));
        $unknown = array_diff_key($filters, array_flip($knownKeys));
        $known = array_intersect_key($filters, array_flip($knownKeys));

        if ($trigger === TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED) {
            $targets = (array) ($known['targets'] ?? []);
            $resolved = collect($targets)->map(function (mixed $target): ?int {
                $customField = collect($this->catalog['custom_fields'])->first(
                    fn (array $candidate): bool => $candidate['target'] === $target,
                );

                return $customField ? (int) $customField['value'] : null;
            });
            if ($resolved->contains(null)) {
                $unknown['targets'] = $targets;
                unset($known['targets']);
            } else {
                $known['definition_ids'] = $resolved->values()->all();
                unset($known['targets']);
            }
        }

        return [$known, $unknown];
    }

    /** @return array<string, mixed> */

    /** @param array<string, mixed> $definition */
    private function startSourceDefinition(array $definition): void
    {
        $this->unknownDescriptors = [];
        $this->hasUnknownRoot = false;
        $this->hasUnknownTriggerFilters = false;
        $this->sourcePublishedVersionId = null;
        $this->sourceDefinitionChecksum = TicketRuleStableJson::checksum($definition);
        $this->sourceDefinitionCache = $definition;
    }

    private function registerUnknownDescriptor(string $kind, string $path, mixed $value): string
    {
        $key = (string) Str::uuid();
        $this->unknownDescriptors[] = [
            'key' => $key,
            'kind' => $kind,
            'path' => $path,
            'token' => $this->unknownToken($kind, $path, $value),
        ];

        return $key;
    }

    private function unknownToken(string $kind, string $path, mixed $value): string
    {
        return hash_hmac(
            'sha256',
            $kind.'|'.$path.'|'.TicketRuleStableJson::checksum($value),
            (string) config('app.key', ''),
        );
    }

    private function unknownValue(array $placeholder, string $kind): mixed
    {
        $key = (string) ($placeholder['_key'] ?? '');
        $descriptor = $this->descriptorFor($key, $kind);
        if ($descriptor === null) {
            throw ValidationException::withMessages([
                'draft' => 'The preserved unsupported definition node is invalid.',
            ]);
        }

        return $this->resolveUnknownDescriptor($descriptor);
    }

    private function unknownValueForKind(string $kind): mixed
    {
        $descriptors = collect($this->unknownDescriptors)
            ->filter(fn (array $descriptor): bool => ($descriptor['kind'] ?? null) === $kind)
            ->values();
        if ($descriptors->count() !== 1) {
            throw ValidationException::withMessages([
                'draft' => 'The preserved unsupported definition node is invalid.',
            ]);
        }

        return $this->resolveUnknownDescriptor((array) $descriptors->first());
    }

    /** @return array<string, mixed>|null */
    private function descriptorFor(string $key, string $kind): ?array
    {
        $descriptor = collect($this->unknownDescriptors)->first(
            fn (array $candidate): bool => hash_equals((string) ($candidate['key'] ?? ''), $key)
                && ($candidate['kind'] ?? null) === $kind,
        );

        return is_array($descriptor) ? $descriptor : null;
    }

    private function isUnknownPlaceholder(array $placeholder, string $kind): bool
    {
        $key = (string) ($placeholder['_key'] ?? '');
        $descriptor = $this->descriptorFor($key, $kind);
        if ($descriptor !== null) {
            return true;
        }

        if ($placeholder['_unknown'] ?? false) {
            throw ValidationException::withMessages([
                'draft' => 'The preserved unsupported definition node is invalid.',
            ]);
        }

        return false;
    }

    /** @param array<string, mixed> $descriptor */
    private function resolveUnknownDescriptor(array $descriptor): mixed
    {
        $source = $this->authoritativeSourceDefinition();
        $kind = (string) ($descriptor['kind'] ?? '');
        $path = (string) ($descriptor['path'] ?? '');
        $value = match ($kind) {
            'root' => $this->unknownRootFrom($source),
            'trigger_filters' => $this->splitTriggerFilters(
                (array) ($source['trigger_filters'] ?? []),
                (string) ($source['trigger'] ?? TicketRuleTriggerRegistry::CREATED),
            )[1],
            default => $this->sourceValueAt($source, $path),
        };

        $expected = (string) ($descriptor['token'] ?? '');
        $actual = $this->unknownToken($kind, $path, $value);
        if ($expected === '' || ! hash_equals($expected, $actual)) {
            throw ValidationException::withMessages([
                'draft' => 'The preserved unsupported definition changed. Reload the Rule before saving.',
            ]);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function authoritativeSourceDefinition(): array
    {
        if ($this->sourceDefinitionCache !== null) {
            return $this->sourceDefinitionCache;
        }
        if ($this->ruleId === null || $this->sourceDefinitionChecksum === null) {
            throw ValidationException::withMessages([
                'draft' => 'The preserved unsupported definition source is unavailable.',
            ]);
        }

        $rule = TicketRule::query()->with('publishedVersion')->findOrFail($this->ruleId);
        if ($this->draftChecksum !== null) {
            $currentChecksum = (string) ($rule->draft_checksum ?? '');
            if ($currentChecksum === '' || ! hash_equals($this->draftChecksum, $currentChecksum)) {
                throw ValidationException::withMessages([
                    'draft' => 'This draft changed after the builder was opened. Reload before saving.',
                ]);
            }
            $definition = (array) data_get($rule->draft_payload_json, 'definition', []);
        } else {
            if (! $this->expectedNoDraft || $rule->draft_checksum !== null) {
                throw ValidationException::withMessages([
                    'draft' => 'This draft changed after the builder was opened. Reload before saving.',
                ]);
            }

            if ((int) $rule->publishedVersion?->definition_schema_version
                === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION) {
                if ($this->sourcePublishedVersionId === null
                    || (int) $rule->publishedVersion->id !== $this->sourcePublishedVersionId) {
                    throw ValidationException::withMessages([
                        'draft' => 'The immutable published source changed. Reload the Rule.',
                    ]);
                }
                $definition = (array) $rule->publishedVersion->definition_json;
            } else {
                $inspection = app(TicketRuleDefinitionCanonicalizer::class)->inspect($rule);
                $definition = ($inspection['status'] ?? null)
                    === TicketRuleDefinitionCanonicalizer::STATUS_VALID
                        ? (array) $inspection['definition']
                        : $this->legacyFallbackDefinition($rule);
            }
        }

        $checksum = TicketRuleStableJson::checksum($definition);
        if (! hash_equals($this->sourceDefinitionChecksum, $checksum)) {
            throw ValidationException::withMessages([
                'draft' => 'The preserved unsupported definition changed. Reload the Rule before saving.',
            ]);
        }

        return $this->sourceDefinitionCache = $definition;
    }

    /** @return array<string, mixed> */
    private function legacyFallbackDefinition(TicketRule $rule): array
    {
        return [
            'schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::CREATED,
            'trigger_filters' => [],
            'conditions' => [
                'mode' => 'grouped',
                'match' => 'ALL',
                'groups' => [[
                    'match' => 'ALL',
                    'conditions' => (array) $rule->conditions_json,
                ]],
            ],
            'then_actions' => (array) $rule->actions_json,
            'else_actions' => [],
            'flow' => ['stop_processing' => (bool) $rule->stop_processing],
            'order' => ['weight' => (int) $rule->weight],
        ];
    }

    /** @param array<string, mixed> $definition */
    private function unknownRootFrom(array $definition): array
    {
        $knownRoot = [
            'schema_version', 'trigger', 'trigger_filters', 'conditions',
            'then_actions', 'else_actions', 'flow', 'order',
        ];

        return array_diff_key($definition, array_flip($knownRoot));
    }

    /** @param array<string, mixed> $source */
    private function sourceValueAt(array $source, string $path): mixed
    {
        $value = $source;
        foreach (explode('.', $path) as $segment) {
            $key = ctype_digit($segment) ? (int) $segment : $segment;
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                throw ValidationException::withMessages([
                    'draft' => 'The preserved unsupported definition changed. Reload the Rule before saving.',
                ]);
            }
            $value = $value[$key];
        }

        return $value;
    }

    private function assertUnknownPlaceholdersIntact(): void
    {
        /** @var array<string, array{kind: string, path: string}> $expected */
        $expected = collect($this->unknownDescriptors)
            ->filter(fn (array $descriptor): bool => in_array(
                $descriptor['kind'] ?? null,
                ['group', 'condition', 'then_action', 'else_action'],
                true,
            ))
            ->mapWithKeys(fn (array $descriptor): array => [
                (string) $descriptor['key'] => [
                    'kind' => (string) $descriptor['kind'],
                    'path' => (string) $descriptor['path'],
                ],
            ])
            ->all();
        $actual = [];

        foreach ($this->groups as $groupIndex => $group) {
            $this->collectUnknownPlaceholder(
                $actual,
                (array) $group,
                'group',
                'conditions.groups.'.$groupIndex,
                $expected,
            );
            foreach ((array) ($group['conditions'] ?? []) as $conditionIndex => $condition) {
                $this->collectUnknownPlaceholder(
                    $actual,
                    (array) $condition,
                    'condition',
                    'conditions.groups.'.$groupIndex.'.conditions.'.$conditionIndex,
                    $expected,
                );
            }
        }
        foreach ($this->thenActions as $index => $action) {
            $this->collectUnknownPlaceholder(
                $actual,
                (array) $action,
                'then_action',
                'then_actions.'.$index,
                $expected,
            );
        }
        foreach ($this->elseActions as $index => $action) {
            $this->collectUnknownPlaceholder(
                $actual,
                (array) $action,
                'else_action',
                'else_actions.'.$index,
                $expected,
            );
        }

        ksort($expected);
        ksort($actual);
        if ($actual !== $expected) {
            throw ValidationException::withMessages([
                'draft' => 'The preserved unsupported definition structure is invalid.',
            ]);
        }
    }

    /**
     * @param  array<string, array{kind: string, path: string}>  $actual
     * @param  array<string, array{kind: string, path: string}>  $expected
     */
    private function collectUnknownPlaceholder(
        array &$actual,
        array $node,
        string $containerKind,
        string $path,
        array $expected,
    ): void {
        $key = (string) ($node['_key'] ?? '');
        if (isset($expected[$key])) {
            if ($expected[$key] !== ['kind' => $containerKind, 'path' => $path]
                || isset($actual[$key])) {
                throw ValidationException::withMessages([
                    'draft' => 'The preserved unsupported definition structure is invalid.',
                ]);
            }
            $actual[$key] = ['kind' => $containerKind, 'path' => $path];
        } elseif ($node['_unknown'] ?? false) {
            throw ValidationException::withMessages([
                'draft' => 'The preserved unsupported definition node is invalid.',
            ]);
        }
    }

    private function customFieldTarget(int $definitionId): array
    {
        $definition = collect($this->catalog['custom_fields'])->firstWhere('value', $definitionId);

        return is_array($definition) ? (array) ($definition['target'] ?? []) : [];
    }

    private function typedConditionValue(array $condition, mixed $value, string $operator): mixed
    {
        if (in_array($operator, ['in', 'not_in', 'intersects'], true) && ! is_array($value)) {
            $value = array_values(array_filter(array_map('trim', explode(',', (string) $value))));
        }

        if (str_starts_with((string) ($condition['field'] ?? ''), 'custom_field.')) {
            $customField = collect($this->catalog['custom_fields'])
                ->firstWhere('value', (int) ($condition['definition_id'] ?? 0));
            $type = (string) ($customField['field_type'] ?? 'text');
        } else {
            $fact = collect($this->catalog['facts'])->firstWhere('key', $condition['field'] ?? null);
            $type = (string) ($fact['value_type'] ?? 'string');
        }

        if (is_array($value)) {
            return array_values(array_map(fn (mixed $item): mixed => $this->castByType(
                $item,
                in_array($type, ['integer_list', 'positive_integer_list'], true)
                    ? 'integer'
                    : ($type === 'string_list' ? 'string' : $type),
            ), $value));
        }

        return $this->castByType($value, $type);
    }

    private function castByType(mixed $value, string $type): mixed
    {
        return match ($type) {
            'positive_integer', 'integer' => is_numeric($value) ? (int) $value : $value,
            'number' => is_numeric($value)
                ? (((float) $value === (float) (int) $value) ? (int) $value : (float) $value)
                : $value,
            'boolean', 'checkbox' => is_bool($value)
                ? $value
                : in_array($value, [1, '1', 'true', 'on', 'yes'], true),
            'multiselect', 'string_list' => is_array($value)
                ? array_values(array_map('strval', $value))
                : [$value],
            default => $value,
        };
    }

    /** @param array<mixed> $input */
    private function cleanInput(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            if (str_starts_with((string) $key, '_') || $value === '' || $value === null) {
                continue;
            }
            $result[$key] = is_array($value) ? $this->cleanInput($value) : $value;
        }

        return $result;
    }

    private function hasUnknownNodes(): bool
    {
        if ($this->unknownDescriptors !== []) {
            return true;
        }

        foreach ($this->groups as $group) {
            if (($group['_unknown'] ?? false)
                || collect((array) ($group['conditions'] ?? []))->contains('_unknown', true)) {
                return true;
            }
        }

        return collect(array_merge($this->thenActions, $this->elseActions))
            ->contains('_unknown', true);
    }

    /** @return array<string, mixed> */
    private function typedPreviewContext(): array
    {
        $context = $this->previewContext;
        foreach (['added_tag_ids', 'removed_tag_ids'] as $listKey) {
            if (isset($context[$listKey]) && is_array($context[$listKey])) {
                $context[$listKey] = array_values(array_map('intval', $context[$listKey]));
            }
        }
        foreach (['queue_id', 'owner_id', 'status_id', 'definition_id', 'workflow_version_id'] as $idKey) {
            if (isset($context[$idKey]) && $context[$idKey] !== '') {
                $context[$idKey] = (int) $context[$idKey];
            }
        }

        if ($this->trigger === TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED) {
            $customField = collect($this->catalog['custom_fields'])
                ->firstWhere('value', (int) ($context['definition_id'] ?? 0));
            $type = (string) ($customField['field_type'] ?? 'text');
            foreach (['before_value', 'after_value'] as $valueKey) {
                if (array_key_exists($valueKey, $context)
                    && $context[$valueKey] !== ''
                    && $context[$valueKey] !== null) {
                    $context[$valueKey] = $this->castByType($context[$valueKey], $type);
                } elseif (($context[$valueKey] ?? null) === '') {
                    $context[$valueKey] = null;
                }
            }
        }

        return $context;
    }

    private function defaultPreviewContext(string $trigger): array
    {
        $firstFilter = static fn (mixed $value): mixed => is_array($value)
            ? ($value[0] ?? null)
            : null;

        return match ($trigger) {
            TicketRuleTriggerRegistry::UPDATED,
            TicketRuleTriggerRegistry::FIELD_CHANGED => [
                'changed_fields' => array_values((array) ($this->triggerFilters['fields'] ?? [])),
            ],
            TicketRuleTriggerRegistry::MESSAGE_ADDED => [
                'message_type' => $firstFilter($this->triggerFilters['message_types'] ?? null)
                    ?? 'customer_reply',
                'source_channel' => $firstFilter($this->triggerFilters['source_channels'] ?? null)
                    ?? 'email',
            ],
            TicketRuleTriggerRegistry::TAGS_CHANGED => [
                'added_tag_ids' => [],
                'removed_tag_ids' => [],
            ],
            TicketRuleTriggerRegistry::ASSIGNMENT_CHANGED => [
                'assignment_change' => $firstFilter($this->triggerFilters['changes'] ?? null)
                    ?? 'queue_changed',
                'queue_id' => null,
                'owner_id' => null,
            ],
            TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED => [
                'definition_id' => $firstFilter($this->triggerFilters['definition_ids'] ?? null),
                'direction' => $firstFilter($this->triggerFilters['directions'] ?? null) ?? 'changed',
                'before_value' => null,
                'after_value' => null,
            ],
            TicketRuleTriggerRegistry::WORKFLOW_CHANGED => [
                'workflow_version_id' => $firstFilter(
                    $this->triggerFilters['workflow_version_ids'] ?? null,
                ),
                'workflow_operation' => $firstFilter($this->triggerFilters['operations'] ?? null)
                    ?? 'transition',
            ],
            TicketRuleTriggerRegistry::WORKFLOW_STATE_CHANGED => [
                'workflow_version_id' => $firstFilter(
                    $this->triggerFilters['workflow_version_ids'] ?? null,
                ),
                'workflow_state_key' => null,
            ],
            TicketRuleTriggerRegistry::STATUS_CHANGED => [
                'status_id' => $firstFilter($this->triggerFilters['status_ids'] ?? null),
            ],
            default => [],
        };
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = $exception instanceof ValidationException
            ? (string) collect($exception->errors())->flatten()->first()
            : $exception->getMessage();
        $safePrefixes = [
            'Choose ',
            'Enter ',
            'Use ',
            'A preview ',
            'The selected ',
            'The synthetic ',
            'The trigger ',
            'The draft ',
            'This draft ',
            'Ticket Rule ',
            'The publisher ',
            'The immutable ',
            'A referenced ',
            'Published Ticket Rule ',
            'This published ',
            'The Ticket Rule catalog ',
        ];

        if (collect($safePrefixes)->contains(
            fn (string $prefix): bool => str_starts_with($message, $prefix),
        )) {
            return $message;
        }

        $reasonMessages = [
            'invalid_custom_field_target' => 'The selected Custom Field is not available.',
            'custom_field_target_unavailable' => 'The selected Custom Field is not available.',
            'unsupported_custom_field_condition_operator' => 'The operator is not valid for this Custom Field type.',
            'invalid_custom_field_condition_value' => 'The value is not valid for this Custom Field type.',
            'invalid_trigger_filter_value' => 'A trigger filter value is invalid.',
            'missing_trigger_filter' => 'A required trigger filter is missing.',
            'empty_required_trigger_filter' => 'Choose at least one required trigger filter value.',
        ];
        if (isset($reasonMessages[$message])) {
            return $reasonMessages[$message];
        }

        $reference = substr(hash('sha256', $exception::class.'|'.$exception->getMessage()), 0, 12);

        return 'The operation could not be completed. Reference: '.$reference.'.';
    }

    private function move(array &$items, int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);
        if (! isset($items[$index], $items[$target])) {
            return;
        }

        if ($this->containsUnknownNode($items[$index])
            || $this->containsUnknownNode($items[$target])) {
            return;
        }

        [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
        $items = array_values($items);
    }

    private function containsUnknownNode(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }
        if (($node['_unknown'] ?? false) === true) {
            return true;
        }

        return collect($node)->contains(
            fn (mixed $child): bool => $this->containsUnknownNode($child),
        );
    }

    /** @param array<int, mixed> $items */
    private function removalWouldShiftUnknown(array $items, int $index): bool
    {
        if ($this->containsUnknownNode($items[$index] ?? null)) {
            return true;
        }

        foreach (array_slice($items, $index + 1) as $item) {
            if ($this->containsUnknownNode($item)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $definition */
    private function authorizeDefinitionTargets(
        TicketRulePublicationTargetValidator $targets,
        array $definition,
        User $operator,
    ): void {
        try {
            $targets->validateCustomFieldAccess($definition, $operator);
        } catch (ValidationException) {
            abort(
                403,
                'This Ticket Rule references a Custom Field that is unavailable for your account.',
            );
        }
    }

    private function operator(): User
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new \RuntimeException('An authenticated operator is required.');
        }

        return $user;
    }

    private function authorizeManager(): void
    {
        $operator = $this->operator();
        abort_unless($operator->isActive() && $operator->can('ticket.manage_rules'), 403);
    }

    private function resetFeedback(): void
    {
        $this->notice = null;
        $this->error = null;
    }
}
