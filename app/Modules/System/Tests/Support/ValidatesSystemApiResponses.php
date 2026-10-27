<?php

namespace App\Modules\System\Tests\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/** Validate real response types, required fields, unions and closed projections; evidence excludes values. */
trait ValidatesSystemApiResponses
{
    private function validateSystemResponse(string $method, string $path, $response): void
    {
        static $document = null;
        $document ??= json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $route = Route::getRoutes()->match(Request::create('/api/v1/system'.$path, $method));
        $operation = $document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
        $this->assertIsArray($operation, $method.' '.$path.' has no Swagger operation.');
        $schema = $operation['responses'][$response->status()]['content']['application/json']['schema'] ?? null;
        $this->assertIsArray($schema, 'Missing typed response for HTTP '.$response->status().' ['.$response->json('exception').'] '.$response->json('message'));
        $this->assertSame([], $this->systemSchemaErrors(json_decode($response->getContent()), $schema, $document),
            $method.' '.$path.' HTTP '.$response->status());
        if ($response->status() < 300 && ($file = getenv('SYSTEM_API_EVIDENCE'))) {
            file_put_contents($file, json_encode(['method' => strtolower($method), 'path' => '/'.$route->uri(),
                'status' => $response->status(), 'schema_validated' => true])."\n", FILE_APPEND);
        }
    }

    private function systemSchemaErrors($value, array $schema, array $document, string $path = '$'): array
    {
        if (isset($schema['$ref'])) {
            $schema = array_merge($document['components']['schemas'][basename($schema['$ref'])],
                array_diff_key($schema, ['$ref' => true]));
        }
        if ($value === null && ($schema['nullable'] ?? false)) {
            return [];
        }
        foreach (['oneOf', 'anyOf'] as $union) {
            if (isset($schema[$union])) {
                $matches = 0;
                foreach ($schema[$union] as $candidate) {
                    $matches += $this->systemSchemaErrors($value, $candidate, $document, $path) === [] ? 1 : 0;
                }

                return ($union === 'oneOf' ? $matches === 1 : $matches > 0) ? [] : [$path.' has invalid schema alternatives'];
            }
        }
        $errors = [];
        foreach ($schema['allOf'] ?? [] as $candidate) {
            $errors = array_merge($errors, $this->systemSchemaErrors($value, $candidate, $document, $path));
        }
        $valid = match ($schema['type'] ?? null) {
            'object' => is_object($value), 'array' => is_array($value), 'integer' => is_int($value),
            'number' => is_int($value) || is_float($value), 'boolean' => is_bool($value), 'string' => is_string($value), default => true,
        };
        if (! $valid) {
            return [$path.' expected '.($schema['type'] ?? 'value').' received '.get_debug_type($value)];
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.' is outside enum';
        }
        if (is_string($value)) {
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $errors[] = $path.' exceeds maxLength';
            }
            if (isset($schema['pattern']) && preg_match('~'.str_replace('~', '\~', $schema['pattern']).'~u', $value) !== 1) {
                $errors[] = $path.' violates pattern';
            }
        }
        if (is_object($value)) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! property_exists($value, $key)) {
                    $errors[] = $path.'.'.$key.' is required';
                }
            }
            foreach (get_object_vars($value) as $key => $child) {
                $properties = $schema['properties'] ?? [];
                if (! array_key_exists($key, $properties) && ($schema['additionalProperties'] ?? null) === false) {
                    $errors[] = $path.'.'.$key.' is unsupported';
                }
                $childSchema = $properties[$key] ?? (is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : null);
                if ($childSchema) {
                    $errors = array_merge($errors, $this->systemSchemaErrors($child, $childSchema, $document, $path.'.'.$key));
                }
            }
        }
        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $i => $child) {
                $errors = array_merge($errors, $this->systemSchemaErrors($child, $schema['items'], $document, $path.'['.$i.']'));
            }
        }

        return $errors;
    }
}
