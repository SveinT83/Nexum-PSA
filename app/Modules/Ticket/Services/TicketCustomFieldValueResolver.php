<?php

namespace App\Modules\Ticket\Services;

use App\Modules\CustomField\Actions\NormalizeCustomFieldValue;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Models\CustomFieldValue;
use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Validation\ValidationException;

/**
 * One typed representation for Ticket Custom Field values in mutation events,
 * rule conditions, previews, and runtime target checks.
 */
final class TicketCustomFieldValueResolver
{
    public function __construct(
        private readonly NormalizeCustomFieldValue $normalizer,
        private readonly CustomFieldModelRegistry $models,
    ) {}

    public function current(Ticket $ticket, CustomFieldDefinition $definition): mixed
    {
        return $this->fromRecord($definition, $this->record($ticket, $definition));
    }

    public function normalize(CustomFieldDefinition $definition, mixed $value): mixed
    {
        return $this->fromPayload($definition, $this->normalizer->handle($definition, $value));
    }

    public function equivalent(mixed $before, mixed $after): bool
    {
        return TicketRuleStableJson::checksum(['value' => $before])
            === TicketRuleStableJson::checksum(['value' => $after]);
    }

    /**
     * Runtime facts retain the exact normalized value in memory. This array is
     * never used as the durable audit projection.
     *
     * @return array<string, mixed>
     */
    public function fact(
        CustomFieldDefinition $definition,
        mixed $before,
        mixed $after,
    ): array {
        return [
            'definition_id' => (int) $definition->id,
            'model_type' => Ticket::class,
            'field_type' => (string) $definition->field_type,
            'before' => $before,
            'after' => $after,
            'current' => $after,
            'changed' => ! $this->equivalent($before, $after),
            'present' => $this->present($after),
            'before_present' => $this->present($before),
            'after_present' => $this->present($after),
        ];
    }

    /**
     * Durable before/after evidence deliberately excludes the raw value.
     *
     * @return array<string, mixed>
     */
    public function auditProjection(
        CustomFieldDefinition $definition,
        mixed $value,
    ): array {
        return [
            'definition_id' => (int) $definition->id,
            'field_type' => (string) $definition->field_type,
            'present' => $this->present($value),
            'value_fingerprint' => TicketRuleStableJson::checksum(['value' => $value]),
        ];
    }

    public function present(mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            is_string($value) => $value !== '',
            is_array($value) => $value !== [],
            default => true,
        };
    }

    public function record(
        Ticket $ticket,
        CustomFieldDefinition $definition,
    ): ?CustomFieldValue {
        $records = CustomFieldValue::query()
            ->where('custom_field_definition_id', $definition->id)
            ->whereIn('model_type', $this->models->storageTypesFor(Ticket::class))
            ->where('model_id', $ticket->getKey())
            ->orderBy('id')
            ->get();

        if ($records->count() > 1) {
            throw ValidationException::withMessages([
                'custom_fields' => 'Ticket Custom Field storage aliases are ambiguous.',
            ]);
        }

        return $records->first();
    }

    /** @param array<string, mixed> $payload */
    private function fromPayload(
        CustomFieldDefinition $definition,
        array $payload,
    ): mixed {
        if ($payload['value_text'] === null
            && $payload['value_number'] === null
            && $payload['value_boolean'] === null
            && $payload['value_date'] === null
            && $payload['value_datetime'] === null
            && $payload['value_json'] === null) {
            return null;
        }

        return match ($definition->field_type) {
            CustomFieldDefinition::TYPE_NUMBER => (float) $payload['value_number'],
            CustomFieldDefinition::TYPE_CHECKBOX => (bool) $payload['value_boolean'],
            CustomFieldDefinition::TYPE_DATE => (string) $payload['value_date'],
            CustomFieldDefinition::TYPE_DATETIME => (string) $payload['value_datetime'],
            CustomFieldDefinition::TYPE_MULTISELECT => array_values((array) $payload['value_json']),
            default => $payload['value_text'],
        };
    }

    private function fromRecord(
        CustomFieldDefinition $definition,
        ?CustomFieldValue $record,
    ): mixed {
        if (! $record) {
            return null;
        }

        return match ($definition->field_type) {
            CustomFieldDefinition::TYPE_NUMBER => $record->value_number === null ? null : (float) $record->value_number,
            CustomFieldDefinition::TYPE_CHECKBOX => $record->value_boolean,
            CustomFieldDefinition::TYPE_DATE => $record->value_date?->toDateString(),
            CustomFieldDefinition::TYPE_DATETIME => $record->value_datetime?->format('Y-m-d H:i:s'),
            CustomFieldDefinition::TYPE_MULTISELECT => $record->value_json ?? [],
            default => $record->value_text,
        };
    }
}
