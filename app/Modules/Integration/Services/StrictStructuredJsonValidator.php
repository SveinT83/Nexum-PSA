<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\StructuredAiValidationException;
use DateTimeImmutable;

class StrictStructuredJsonValidator
{
    private const TYPES = [
        'object',
        'array',
        'string',
        'number',
        'integer',
        'boolean',
        'null',
    ];

    private const KEYWORDS = [
        'type',
        'description',
        'enum',
        'const',
        'properties',
        'required',
        'additionalProperties',
        'items',
        'minItems',
        'maxItems',
        'minLength',
        'maxLength',
        'pattern',
        'format',
        'minimum',
        'maximum',
    ];

    private const MAX_STRING_LENGTH = 16_000;

    public function assertStrictDataSchema(array $schema): void
    {
        $this->assertStrictSchema($schema);
        if (! in_array('object', $this->types($schema), true)) {
            throw new StructuredAiValidationException('response_schema_not_strict');
        }
    }

    public function assertStrictSchema(array $schema): void
    {
        $this->validateSchema($schema);
    }

    public function assertMatches(mixed $value, array $schema): void
    {
        $this->validateValue($value, $schema);
    }

    private function validateSchema(array $schema): void
    {
        if ($schema === []
            || array_diff(array_keys($schema), self::KEYWORDS) !== []) {
            throw new StructuredAiValidationException('response_schema_not_strict');
        }

        $types = $this->types($schema);
        if ($types === []
            || count($types) !== count(array_unique($types))
            || array_diff($types, self::TYPES) !== []) {
            throw new StructuredAiValidationException('response_schema_not_strict');
        }

        if (isset($schema['description'])
            && (! is_string($schema['description']) || mb_strlen($schema['description']) > 512)) {
            throw new StructuredAiValidationException('response_schema_not_strict');
        }
        if (isset($schema['enum'])
            && (! is_array($schema['enum']) || $schema['enum'] === [] || count($schema['enum']) > 100)) {
            throw new StructuredAiValidationException('response_schema_not_strict');
        }

        if (in_array('object', $types, true)) {
            $properties = $schema['properties'] ?? null;
            $required = $schema['required'] ?? null;
            if (! is_array($properties)
                || ! is_array($required)
                || ! array_is_list($required)
                || ($schema['additionalProperties'] ?? null) !== false) {
                throw new StructuredAiValidationException('response_schema_not_strict');
            }
            if ($properties !== [] && array_is_list($properties)) {
                throw new StructuredAiValidationException('response_schema_not_strict');
            }
            if (count($properties) > 150
                || count($required) !== count(array_unique($required))) {
                throw new StructuredAiValidationException('response_schema_not_strict');
            }
            foreach ($required as $property) {
                if (! is_string($property) || ! array_key_exists($property, $properties)) {
                    throw new StructuredAiValidationException('response_schema_not_strict');
                }
            }
            foreach ($properties as $property => $propertySchema) {
                if (! is_string($property)
                    || mb_strlen($property) > 120
                    || ! is_array($propertySchema)) {
                    throw new StructuredAiValidationException('response_schema_not_strict');
                }
                $this->validateSchema($propertySchema);
            }
        }

        if (in_array('array', $types, true)) {
            $maxItems = $schema['maxItems'] ?? null;
            if (! is_array($schema['items'] ?? null)
                || ! is_int($maxItems)
                || $maxItems < 0
                || $maxItems > 500) {
                throw new StructuredAiValidationException('response_schema_not_strict');
            }
            if (isset($schema['minItems'])
                && (! is_int($schema['minItems'])
                    || $schema['minItems'] < 0
                    || $schema['minItems'] > $maxItems)) {
                throw new StructuredAiValidationException('response_schema_not_strict');
            }
            $this->validateSchema($schema['items']);
        }

        if (in_array('string', $types, true)) {
            $hasBoundedValues = array_key_exists('const', $schema) || isset($schema['enum']);
            if (! $hasBoundedValues
                && (! is_int($schema['maxLength'] ?? null)
                    || $schema['maxLength'] < 0
                    || $schema['maxLength'] > self::MAX_STRING_LENGTH)) {
                throw new StructuredAiValidationException('response_schema_not_strict');
            }
            if (isset($schema['minLength'])
                && (! is_int($schema['minLength'])
                    || $schema['minLength'] < 0
                    || (isset($schema['maxLength']) && $schema['minLength'] > $schema['maxLength']))) {
                throw new StructuredAiValidationException('response_schema_not_strict');
            }
            if (isset($schema['pattern']) && ! is_string($schema['pattern'])) {
                throw new StructuredAiValidationException('response_schema_not_strict');
            }
            if (isset($schema['format'])
                && ! in_array($schema['format'], ['date', 'date-time', 'uuid'], true)) {
                throw new StructuredAiValidationException('response_schema_not_strict');
            }
        }

        foreach (['minimum', 'maximum'] as $bound) {
            if (isset($schema[$bound]) && ! is_int($schema[$bound]) && ! is_float($schema[$bound])) {
                throw new StructuredAiValidationException('response_schema_not_strict');
            }
        }
        if (isset($schema['minimum'], $schema['maximum'])
            && $schema['minimum'] > $schema['maximum']) {
            throw new StructuredAiValidationException('response_schema_not_strict');
        }
    }

    private function validateValue(mixed $value, array $schema): void
    {
        $types = $this->types($schema);
        if (! $this->matchesAnyType($value, $types)) {
            throw new StructuredAiValidationException('response_schema_mismatch');
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            throw new StructuredAiValidationException('response_schema_mismatch');
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            throw new StructuredAiValidationException('response_schema_mismatch');
        }
        if ($value === null) {
            return;
        }

        if (is_array($value) && $this->isObjectValue($value, $types)) {
            $properties = $schema['properties'] ?? [];
            foreach ($schema['required'] ?? [] as $required) {
                if (! array_key_exists($required, $value)) {
                    throw new StructuredAiValidationException('response_schema_mismatch');
                }
            }
            if (array_diff(array_keys($value), array_keys($properties)) !== []) {
                throw new StructuredAiValidationException('response_schema_mismatch');
            }
            foreach ($value as $property => $item) {
                $this->validateValue($item, $properties[$property]);
            }

            return;
        }

        if (is_array($value)) {
            $count = count($value);
            if ($count < ($schema['minItems'] ?? 0)
                || $count > ($schema['maxItems'] ?? 0)) {
                throw new StructuredAiValidationException('response_schema_mismatch');
            }
            foreach ($value as $item) {
                $this->validateValue($item, $schema['items']);
            }

            return;
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if ($length < ($schema['minLength'] ?? 0)
                || (isset($schema['maxLength']) && $length > $schema['maxLength'])) {
                throw new StructuredAiValidationException('response_schema_mismatch');
            }
            if (isset($schema['pattern'])) {
                $pattern = '~'.str_replace('~', '\\~', $schema['pattern']).'~u';
                if (@preg_match($pattern, $value) !== 1) {
                    throw new StructuredAiValidationException('response_schema_mismatch');
                }
            }
            if (isset($schema['format']) && ! $this->matchesFormat($value, $schema['format'])) {
                throw new StructuredAiValidationException('response_schema_mismatch');
            }

            return;
        }

        if (is_int($value) || is_float($value)) {
            if ((isset($schema['minimum']) && $value < $schema['minimum'])
                || (isset($schema['maximum']) && $value > $schema['maximum'])) {
                throw new StructuredAiValidationException('response_schema_mismatch');
            }
        }
    }

    private function types(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            return [$type];
        }

        return is_array($type) && array_is_list($type)
            ? $type
            : [];
    }

    private function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            if (match ($type) {
                'object' => is_array($value) && (! array_is_list($value) || $value === []),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'number' => is_int($value) || is_float($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            }) {
                return true;
            }
        }

        return false;
    }

    private function isObjectValue(array $value, array $types): bool
    {
        return ! array_is_list($value)
            || ($value === []
                && in_array('object', $types, true)
                && ! in_array('array', $types, true));
    }

    private function matchesFormat(string $value, string $format): bool
    {
        return match ($format) {
            'date' => $this->isDate($value),
            'date-time' => str_contains($value, 'T') && strtotime($value) !== false,
            'uuid' => preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $value,
            ) === 1,
            default => false,
        };
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
