<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Support\TicketRuleStableJson;

/**
 * Resolves immutable Ticket Custom Field targets without trusting rule JSON.
 */
final class TicketCustomFieldTargetValidator
{
    public const CURRENT = 'custom_field.current';

    public const BEFORE = 'custom_field.before';

    public const AFTER = 'custom_field.after';

    public const CHANGED = 'custom_field.changed';

    public const PRESENT = 'custom_field.present';

    private const CONDITION_FIELDS = [
        self::CURRENT,
        self::BEFORE,
        self::AFTER,
        self::CHANGED,
        self::PRESENT,
    ];

    private const TARGET_KEYS = [
        'definition_id',
        'expected_model_type',
        'expected_field_type',
        'options_checksum',
    ];

    /** @var array<string, bool> */
    private array $definitionVisibilityCache = [];

    public function __construct(
        private readonly CustomFieldModelRegistry $models,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function targetFor(CustomFieldDefinition $definition): array
    {
        return [
            'definition_id' => (int) $definition->id,
            'expected_model_type' => Ticket::class,
            'expected_field_type' => (string) $definition->field_type,
            'options_checksum' => $this->optionsChecksum($definition),
        ];
    }

    /**
     * @return array{valid: bool, target: array<string, mixed>|null, reason_code: string|null}
     */
    public function canonicalizeTarget(mixed $target): array
    {
        if (! is_array($target)
            || array_is_list($target)
            || array_diff(array_keys($target), self::TARGET_KEYS) !== []
            || array_diff(self::TARGET_KEYS, array_keys($target)) !== []) {
            return $this->invalidTarget('invalid_custom_field_target');
        }

        $definitionId = $this->positiveInteger($target['definition_id'] ?? null);
        $modelType = is_string($target['expected_model_type'] ?? null)
            ? $this->models->canonicalStorageType($target['expected_model_type'])
            : null;
        $fieldType = $target['expected_field_type'] ?? null;
        $checksum = $target['options_checksum'] ?? null;

        if ($definitionId === null
            || $modelType !== Ticket::class
            || ! is_string($fieldType)
            || ! in_array($fieldType, CustomFieldDefinition::SUPPORTED_TYPES, true)
            || ! is_string($checksum)
            || preg_match('/\A[0-9a-f]{64}\z/', $checksum) !== 1) {
            return $this->invalidTarget('invalid_custom_field_target');
        }

        return [
            'valid' => true,
            'target' => [
                'definition_id' => $definitionId,
                'expected_model_type' => Ticket::class,
                'expected_field_type' => $fieldType,
                'options_checksum' => $checksum,
            ],
            'reason_code' => null,
        ];
    }

    /**
     * Revalidate an immutable target against the live definition and actor.
     *
     * @return array{valid: bool, target: array<string, mixed>|null, definition: CustomFieldDefinition|null, reason_code: string|null}
     */
    public function resolve(
        mixed $target,
        string $access = 'view',
        ?User $actor = null,
    ): array {
        $canonical = $this->canonicalizeTarget($target);
        if (! $canonical['valid']) {
            return $this->invalidResolution((string) $canonical['reason_code']);
        }

        $target = $canonical['target'];
        $definition = CustomFieldDefinition::query()
            ->withTrashed()
            ->find($target['definition_id']);
        if (! $definition || $definition->trashed() || ! $definition->active) {
            return $this->invalidResolution('custom_field_target_unavailable');
        }

        if ($this->models->classFor((string) $definition->model_type) !== Ticket::class) {
            return $this->invalidResolution('custom_field_target_retargeted');
        }

        if ((string) $definition->field_type !== $target['expected_field_type']) {
            return $this->invalidResolution('custom_field_target_type_changed');
        }

        if (! hash_equals($target['options_checksum'], $this->optionsChecksum($definition))) {
            return $this->invalidResolution('custom_field_target_options_changed');
        }

        if ($this->hasAmbiguousDefinitionAlias($definition)) {
            return $this->invalidResolution('custom_field_target_alias_ambiguous');
        }

        if (! in_array($access, ['view', 'edit'], true)
            || ! $this->authorized($definition, $access, $actor)) {
            return $this->invalidResolution('custom_field_target_unauthorized');
        }

        return [
            'valid' => true,
            'target' => $target,
            'definition' => $definition,
            'reason_code' => null,
        ];
    }

    /**
     * Publication must prove that the protected actor can still inspect or
     * mutate the exact definition. The publisher never lends authority.
     *
     * @return array{valid: bool, target: array<string, mixed>|null, definition: CustomFieldDefinition|null, reason_code: string|null}
     */
    public function validateForPublication(mixed $target, string $usage): array
    {
        return $this->resolveForAutomation($target, $usage);
    }

    /**
     * @return array{valid: bool, target: array<string, mixed>|null, definition: CustomFieldDefinition|null, reason_code: string|null}
     */
    public function resolveForAutomation(mixed $target, string $usage): array
    {
        $actor = $this->automationActor();
        if (! $actor) {
            return $this->invalidResolution('custom_field_runtime_actor_unavailable');
        }

        return $this->resolve(
            $target,
            $usage === 'action' ? 'edit' : 'view',
            $actor,
        );
    }

    public function automationActor(): ?User
    {
        return User::query()
            ->where('system_actor_key', TicketRuleAutomationActor::KEY)
            ->where('is_system_actor', true)
            ->first();
    }

    /**
     * Historical presenters can enforce current definition visibility without
     * loading a Custom Field value or reconstructing an obsolete target.
     */
    public function canViewDefinitionId(mixed $definitionId, User $actor): bool
    {
        $definitionId = $this->positiveInteger($definitionId);
        if ($definitionId === null) {
            return false;
        }
        $actorKey = is_numeric($actor->getKey())
            ? (string) $actor->getKey()
            : 'object:'.spl_object_id($actor);
        $cacheKey = $actorKey.':'.$definitionId;
        if (array_key_exists($cacheKey, $this->definitionVisibilityCache)) {
            return $this->definitionVisibilityCache[$cacheKey];
        }

        $definition = CustomFieldDefinition::query()
            ->withTrashed()
            ->find($definitionId);
        if (! $definition
            || $definition->trashed()
            || ! $definition->active
            || $this->models->classFor((string) $definition->model_type) !== Ticket::class
            || $this->hasAmbiguousDefinitionAlias($definition)) {
            return $this->definitionVisibilityCache[$cacheKey] = false;
        }

        return $this->definitionVisibilityCache[$cacheKey] = $this->authorized($definition, 'view', $actor);
    }

    public function supportsConditionField(mixed $field): bool
    {
        return is_string($field) && in_array($field, self::CONDITION_FIELDS, true);
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>|null
     */
    public function conditionFact(mixed $field, array $target): ?array
    {
        if (! $this->supportsConditionField($field)) {
            return null;
        }

        if (in_array($field, [self::CHANGED, self::PRESENT], true)) {
            return [
                'label' => $field === self::CHANGED ? 'Custom Field changed' : 'Custom Field present',
                'value_type' => 'boolean',
                'nullable' => false,
                'condition_operators' => ['equals', 'not_equals'],
                'safe_audit_type' => 'boolean',
                'custom_field_target' => $target,
            ];
        }

        $fieldType = $target['expected_field_type'] ?? null;
        $definition = match ($fieldType) {
            CustomFieldDefinition::TYPE_NUMBER => [
                'value_type' => 'number',
                'condition_operators' => [
                    'equals',
                    'not_equals',
                    'greater_than',
                    'greater_than_or_equal',
                    'less_than',
                    'less_than_or_equal',
                    'present',
                ],
                'safe_audit_type' => 'number',
            ],
            CustomFieldDefinition::TYPE_DATE => [
                'value_type' => 'date',
                'condition_operators' => [
                    'equals',
                    'not_equals',
                    'before',
                    'before_or_equal',
                    'after',
                    'after_or_equal',
                    'present',
                ],
                'safe_audit_type' => 'date_fingerprint',
            ],
            CustomFieldDefinition::TYPE_DATETIME => [
                'value_type' => 'datetime',
                'condition_operators' => [
                    'equals',
                    'not_equals',
                    'before',
                    'before_or_equal',
                    'after',
                    'after_or_equal',
                    'present',
                ],
                'safe_audit_type' => 'datetime_fingerprint',
            ],
            CustomFieldDefinition::TYPE_CHECKBOX => [
                'value_type' => 'boolean',
                'condition_operators' => ['equals', 'not_equals', 'present'],
                'safe_audit_type' => 'boolean',
            ],
            CustomFieldDefinition::TYPE_MULTISELECT => [
                'value_type' => 'string_list',
                'condition_operators' => ['contains', 'not_contains', 'intersects', 'present'],
                'safe_audit_type' => 'structured_fingerprint',
            ],
            default => [
                'value_type' => 'string',
                'condition_operators' => [
                    'equals',
                    'not_equals',
                    'contains',
                    'starts_with',
                    'ends_with',
                    'present',
                ],
                'safe_audit_type' => 'bounded_text_fingerprint',
            ],
        };

        return $definition + [
            'label' => match ($field) {
                self::CURRENT => 'Custom Field current value',
                self::BEFORE => 'Custom Field before value',
                default => 'Custom Field after value',
            },
            'nullable' => true,
            'custom_field_target' => $target,
        ];
    }

    public function optionsChecksum(CustomFieldDefinition $definition): string
    {
        return TicketRuleStableJson::checksum(array_values((array) ($definition->options ?? [])));
    }

    private function hasAmbiguousDefinitionAlias(CustomFieldDefinition $definition): bool
    {
        return CustomFieldDefinition::query()
            ->where('id', '!=', $definition->id)
            ->whereIn('model_type', $this->models->storageTypesFor(Ticket::class))
            ->where('key', $definition->key)
            ->where('active', true)
            ->exists();
    }

    private function authorized(
        CustomFieldDefinition $definition,
        string $access,
        ?User $actor,
    ): bool {
        if (! $actor || (! $actor->isActive() && ! $actor->isSystemActor())) {
            return false;
        }

        if ($definition->admin_only && ! $actor->hasAnyRole(['Admin', 'Superuser'])) {
            return false;
        }

        $permission = $access === 'edit'
            ? $definition->edit_permission
            : $definition->view_permission;

        return ! $permission || $actor->can($permission);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /** @return array{valid: false, target: null, reason_code: string} */
    private function invalidTarget(string $reasonCode): array
    {
        return ['valid' => false, 'target' => null, 'reason_code' => $reasonCode];
    }

    /** @return array{valid: false, target: null, definition: null, reason_code: string} */
    private function invalidResolution(string $reasonCode): array
    {
        return [
            'valid' => false,
            'target' => null,
            'definition' => null,
            'reason_code' => $reasonCode,
        ];
    }
}
