<?php

namespace App\Modules\Nextcloud\Tests;

/** Validate actual JSON against local static OpenAPI schema definitions. */
trait AssertsNextcloudApiSchema
{
    protected function schema(mixed $value, array $schema, string $path): void
    {
        if (isset($schema['$ref'])) {
            $this->schema($value, $this->document['components']['schemas'][basename($schema['$ref'])], $path);

            return;
        }
        if ($value === null && ($schema['nullable'] ?? false)) {
            return;
        }
        foreach (['oneOf', 'anyOf'] as $union) {
            if (isset($schema[$union])) {
                $matched = false;
                foreach ($schema[$union] as $choice) {
                    try {
                        $this->schema($value, $choice, $path);
                        $matched = true;
                        break;
                    } catch (\PHPUnit\Framework\AssertionFailedError) {
                    }
                }
                $this->assertTrue($matched, 'No typed alternative matches '.$path);

                return;
            }
        }
        $type = $schema['type'] ?? null;
        if ($type === 'object') {
            $this->assertIsObject($value, $path);
            foreach ($schema['required'] ?? [] as $key) {
                $this->assertTrue(property_exists($value, $key), $path.'.'.$key.' missing');
            }
            foreach (get_object_vars($value) as $key => $child) {
                if (isset($schema['properties'][$key])) {
                    $this->schema($child, $schema['properties'][$key], $path.'.'.$key);
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->schema($child, $schema['additionalProperties'], $path.'.'.$key);
                } else {
                    $this->assertNotFalse($schema['additionalProperties'] ?? true, $path.'.'.$key.' undocumented');
                }
            }
        } elseif ($type === 'array') {
            $this->assertIsArray($value, $path);
            foreach ($value as $i => $child) {
                $this->schema($child, $schema['items'], $path.'.'.$i);
            }
        } elseif ($type === 'string') {
            $this->assertIsString($value, $path);
        } elseif ($type === 'integer') {
            $this->assertIsInt($value, $path);
        } elseif ($type === 'boolean') {
            $this->assertIsBool($value, $path);
        }
        if (isset($schema['enum'])) {
            $this->assertContains($value, $schema['enum'], $path);
        }
    }
}
