<?php

namespace App\Modules\Intake\Support;

use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeFormField;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntakeFormFieldInput
{
    public const LAYOUT_WIDTHS = [12, 6, 4, 3];

    public function normalize(array $rows): array
    {
        $normalized = [];
        $seenKeys = [];
        $availableSourceKeys = [];

        foreach (array_values($rows) as $index => $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $key = $this->key($row['key'] ?? '', $label);

            if ($label === '' && $key === '') {
                continue;
            }

            if ($label === '') {
                throw ValidationException::withMessages(['fields' => 'Every intake field needs a label.']);
            }

            if ($key === '') {
                throw ValidationException::withMessages(['fields' => 'Every intake field needs a key.']);
            }

            if (isset($seenKeys[$key])) {
                throw ValidationException::withMessages(['fields' => 'Field keys must be unique. Duplicate key: '.$key]);
            }

            $seenKeys[$key] = true;
            $fieldType = (string) ($row['field_type'] ?? IntakeFormField::TYPE_TEXT);

            if (! in_array($fieldType, IntakeFormField::FIELD_TYPES, true)) {
                throw ValidationException::withMessages(['fields' => 'Unsupported field type for '.$label.'.']);
            }

            $mapsTo = trim((string) ($row['maps_to'] ?? '')) ?: null;
            $choiceFieldTypes = [
                IntakeFormField::TYPE_SELECT,
                IntakeFormField::TYPE_MULTISELECT,
            ];

            if ($mapsTo && ! in_array($mapsTo, IntakeFormField::MAP_TARGETS, true)) {
                throw ValidationException::withMessages(['fields' => 'Unsupported mapping for '.$label.'.']);
            }

            if ($fieldType === IntakeFormField::TYPE_FILE) {
                $mapsTo = null;
            }

            $layoutWidth = $this->layoutWidth($row['layout_width'] ?? 12);
            $visibility = $this->visibility($row, $availableSourceKeys, $label);

            $normalized[] = [
                'id' => isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null,
                'key' => $key,
                'label' => $label,
                'field_type' => $fieldType,
                'maps_to' => $mapsTo,
                'help_text' => trim((string) ($row['help_text'] ?? '')) ?: null,
                'placeholder' => trim((string) ($row['placeholder'] ?? '')) ?: null,
                'options' => in_array($fieldType, $choiceFieldTypes, true)
                    ? $this->options($row['options_text'] ?? '')
                    : null,
                'is_required' => ! empty($row['is_required']),
                'is_active' => ! empty($row['is_active']),
                'sort_order' => $index * 10,
                'max_files' => $fieldType === IntakeFormField::TYPE_FILE ? $this->nullableInteger($row['max_files'] ?? null) : null,
                'max_file_size_kb' => $fieldType === IntakeFormField::TYPE_FILE ? $this->nullableInteger($row['max_file_size_kb'] ?? null) : null,
                'allowed_mime_types' => $fieldType === IntakeFormField::TYPE_FILE
                    ? $this->mimeTypes($row['allowed_mime_types_text'] ?? '')
                    : null,
                'metadata' => [
                    'layout' => [
                        'width' => $layoutWidth,
                    ],
                    'visibility' => $visibility,
                ],
            ];

            $availableSourceKeys[$key] = true;
        }

        if ($normalized === []) {
            throw ValidationException::withMessages(['fields' => 'Create at least one intake field.']);
        }

        if (! collect($normalized)->contains(fn ($field) => $field['is_active'] === true)) {
            throw ValidationException::withMessages(['fields' => 'At least one intake field must be active.']);
        }

        return $normalized;
    }

    public function mimeTypes(string $value): ?array
    {
        $items = preg_split('/[\s,]+/', trim($value)) ?: [];
        $items = array_values(array_unique(array_filter(array_map('trim', $items))));

        return $items ?: null;
    }

    public function optionsText(?array $options): string
    {
        return collect($options ?: [])
            ->map(fn ($option) => is_array($option) ? ($option['value'] ?? $option['label'] ?? null) : $option)
            ->filter()
            ->implode("\n");
    }

    public function mimeTypesText(?array $mimeTypes): string
    {
        return implode("\n", $mimeTypes ?: []);
    }

    private function key(string $key, string $label): string
    {
        $key = trim($key) ?: Str::slug($label, '_');
        $key = strtolower((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $key));
        $key = trim((string) preg_replace('/_+/', '_', $key), '_');

        return $key;
    }

    private function options(mixed $value): ?array
    {
        $lines = preg_split('/\R+/', (string) $value) ?: [];
        $options = collect($lines)
            ->map(fn ($line) => trim($line))
            ->filter()
            ->map(fn ($line) => ['label' => $line, 'value' => $line])
            ->values()
            ->all();

        return $options ?: null;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    private function layoutWidth(mixed $value): int
    {
        $width = (int) $value;

        return in_array($width, self::LAYOUT_WIDTHS, true) ? $width : 12;
    }

    private function visibility(array $row, array $availableSourceKeys, string $label): array
    {
        $mode = (string) ($row['visibility_mode'] ?? IntakeFormField::VISIBILITY_MODE_ALWAYS);

        if ($mode !== IntakeFormField::VISIBILITY_MODE_CONDITIONAL) {
            return [
                'mode' => IntakeFormField::VISIBILITY_MODE_ALWAYS,
                'match' => IntakeFormField::VISIBILITY_MATCH_ALL,
                'rules' => [],
            ];
        }

        $match = (string) ($row['visibility_match'] ?? IntakeFormField::VISIBILITY_MATCH_ALL);

        if (! in_array($match, IntakeFormField::VISIBILITY_MATCH_MODES, true)) {
            $match = IntakeFormField::VISIBILITY_MATCH_ALL;
        }

        $rules = [];

        foreach (($row['visibility_rules'] ?? []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $sourceKey = $this->key((string) ($rule['source_key'] ?? ''), '');
            $operator = (string) ($rule['operator'] ?? IntakeFormField::VISIBILITY_OPERATOR_HAS_VALUE);
            $value = trim((string) ($rule['value'] ?? ''));

            if ($sourceKey === '') {
                continue;
            }

            if (! isset($availableSourceKeys[$sourceKey])) {
                throw ValidationException::withMessages([
                    'fields' => 'Visibility rules for '.$label.' must use a field above it.',
                ]);
            }

            if (! in_array($operator, IntakeFormField::VISIBILITY_OPERATORS, true)) {
                throw ValidationException::withMessages([
                    'fields' => 'Unsupported visibility operator for '.$label.'.',
                ]);
            }

            if (in_array($operator, [
                IntakeFormField::VISIBILITY_OPERATOR_EQUALS,
                IntakeFormField::VISIBILITY_OPERATOR_NOT_EQUALS,
                IntakeFormField::VISIBILITY_OPERATOR_CONTAINS,
            ], true) && $value === '') {
                throw ValidationException::withMessages([
                    'fields' => 'Visibility rules for '.$label.' need a value.',
                ]);
            }

            $rules[] = [
                'source_key' => $sourceKey,
                'operator' => $operator,
                'value' => $value,
            ];
        }

        if ($rules === []) {
            throw ValidationException::withMessages([
                'fields' => 'Conditional visibility for '.$label.' needs at least one rule.',
            ]);
        }

        return [
            'mode' => IntakeFormField::VISIBILITY_MODE_CONDITIONAL,
            'match' => $match,
            'rules' => $rules,
        ];
    }
}
