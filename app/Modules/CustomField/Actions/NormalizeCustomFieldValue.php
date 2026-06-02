<?php

namespace App\Modules\CustomField\Actions;

use App\Modules\CustomField\Models\CustomFieldDefinition;
use Illuminate\Validation\ValidationException;

class NormalizeCustomFieldValue
{
    /*
    |--------------------------------------------------------------------------
    | Custom field value normalization
    |--------------------------------------------------------------------------
    |
    | Values are stored in type-specific columns while also keeping value_text
    | populated for searchable scalar fields. Empty optional values become null.
    |
    */
    public function handle(CustomFieldDefinition $definition, mixed $value): array
    {
        if ($value === '' || $value === []) {
            $value = null;
        }

        if ($definition->required && $value === null) {
            throw ValidationException::withMessages([
                "custom_fields.{$definition->key}" => "{$definition->label} is required.",
            ]);
        }

        if ($value === null) {
            return $this->emptyPayload();
        }

        return match ($definition->field_type) {
            CustomFieldDefinition::TYPE_NUMBER => $this->numberPayload($definition, $value),
            CustomFieldDefinition::TYPE_DATE => $this->datePayload($definition, $value),
            CustomFieldDefinition::TYPE_DATETIME => $this->dateTimePayload($definition, $value),
            CustomFieldDefinition::TYPE_SELECT => $this->selectPayload($definition, $value),
            CustomFieldDefinition::TYPE_MULTISELECT => $this->multiSelectPayload($definition, $value),
            CustomFieldDefinition::TYPE_CHECKBOX => $this->checkboxPayload($value),
            CustomFieldDefinition::TYPE_EMAIL => $this->emailPayload($definition, $value),
            CustomFieldDefinition::TYPE_URL => $this->urlPayload($definition, $value),
            default => $this->textPayload((string) $value),
        };
    }

    private function emptyPayload(): array
    {
        return [
            'value_text' => null,
            'value_number' => null,
            'value_boolean' => null,
            'value_date' => null,
            'value_datetime' => null,
            'value_json' => null,
        ];
    }

    private function numberPayload(CustomFieldDefinition $definition, mixed $value): array
    {
        if (! is_numeric($value)) {
            $this->fail($definition, 'must be a number');
        }

        return array_merge($this->emptyPayload(), [
            'value_text' => (string) $value,
            'value_number' => $value,
        ]);
    }

    private function datePayload(CustomFieldDefinition $definition, mixed $value): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
            $this->fail($definition, 'must be a date formatted as YYYY-MM-DD');
        }

        return array_merge($this->emptyPayload(), [
            'value_text' => (string) $value,
            'value_date' => (string) $value,
        ]);
    }

    private function dateTimePayload(CustomFieldDefinition $definition, mixed $value): array
    {
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            $this->fail($definition, 'must be a valid datetime');
        }

        $normalized = date('Y-m-d H:i:s', $timestamp);

        return array_merge($this->emptyPayload(), [
            'value_text' => $normalized,
            'value_datetime' => $normalized,
        ]);
    }

    private function selectPayload(CustomFieldDefinition $definition, mixed $value): array
    {
        $value = (string) $value;
        $options = $definition->options ?? [];

        if ($options !== [] && ! in_array($value, $options, true)) {
            $this->fail($definition, 'must be one of the configured options');
        }

        return $this->textPayload($value);
    }

    private function multiSelectPayload(CustomFieldDefinition $definition, mixed $value): array
    {
        $values = is_array($value) ? array_values(array_filter($value, fn ($item) => $item !== '')) : [(string) $value];
        $options = $definition->options ?? [];

        if ($options !== []) {
            foreach ($values as $item) {
                if (! in_array((string) $item, $options, true)) {
                    $this->fail($definition, 'contains an invalid option');
                }
            }
        }

        return array_merge($this->emptyPayload(), [
            'value_text' => implode(',', array_map('strval', $values)),
            'value_json' => array_map('strval', $values),
        ]);
    }

    private function checkboxPayload(mixed $value): array
    {
        $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

        return array_merge($this->emptyPayload(), [
            'value_text' => $boolean ? '1' : '0',
            'value_boolean' => $boolean,
        ]);
    }

    private function emailPayload(CustomFieldDefinition $definition, mixed $value): array
    {
        $value = (string) $value;
        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->fail($definition, 'must be a valid email address');
        }

        return $this->textPayload($value);
    }

    private function urlPayload(CustomFieldDefinition $definition, mixed $value): array
    {
        $value = (string) $value;
        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            $this->fail($definition, 'must be a valid URL');
        }

        return $this->textPayload($value);
    }

    private function textPayload(string $value): array
    {
        return array_merge($this->emptyPayload(), [
            'value_text' => $value,
        ]);
    }

    private function fail(CustomFieldDefinition $definition, string $message): never
    {
        throw ValidationException::withMessages([
            "custom_fields.{$definition->key}" => "{$definition->label} {$message}.",
        ]);
    }
}
