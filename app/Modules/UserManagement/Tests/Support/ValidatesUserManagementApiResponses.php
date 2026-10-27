<?php

namespace App\Modules\UserManagement\Tests\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/** Validate actual HTTP JSON against the published schema; retain only non-secret evidence. */
trait ValidatesUserManagementApiResponses
{
    protected function validateApiResponse(string $method, string $uri, $response): void
    {
        if ($response->status() >= 300) {
            return;
        }
        $uri = str_starts_with($uri, '/') ? $uri : '/api/v1/'.$uri;
        $route = Route::getRoutes()->match(Request::create($uri, $method));
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
        $this->assertIsArray($operation, 'Missing published HTTP operation: '.$method.' '.$route->uri());
        $schema = $operation['responses'][$response->status()]['content']['application/json']['schema'] ?? null;
        $this->assertIsArray($schema, 'Missing typed success response.');
        $this->assertSame([], $this->schemaErrors(json_decode($response->getContent()), $schema, $document), $method.' '.$route->uri());
        if ($path = getenv('USERMANAGEMENT_API_EVIDENCE')) {
            file_put_contents($path, json_encode(['method' => strtolower($method), 'path' => '/'.$route->uri(),
                'status' => $response->status(), 'validated' => true])."\n", FILE_APPEND);
        }
    }

    private function schemaErrors($value, array $schema, array $document, string $path = '$'): array
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return [];
        }
        if (isset($schema['$ref'])) {
            $schema = array_merge($document['components']['schemas'][basename($schema['$ref'])], array_diff_key($schema, ['$ref' => true]));
        }
        if ($value === null && ($schema['nullable'] ?? false)) {
            return [];
        }
        foreach (['oneOf', 'anyOf'] as $union) {
            if (isset($schema[$union])) {
                foreach ($schema[$union] as $candidate) {
                    if ($this->schemaErrors($value, $candidate, $document, $path) === []) {
                        return [];
                    }
                }

                return [$path.' matches no declared alternative'];
            }
        }
        $errors = [];
        foreach ($schema['allOf'] ?? [] as $candidate) {
            $errors = array_merge($errors, $this->schemaErrors($value, $candidate, $document, $path));
        }
        $valid = match ($schema['type'] ?? null) {
            'object' => is_object($value), 'array' => is_array($value), 'integer' => is_int($value),
            'number' => is_int($value) || is_float($value), 'boolean' => is_bool($value), 'string' => is_string($value), default => true,
        };
        if (! $valid) {
            return [$path.' expected '.($schema['type'] ?? 'value').' but received '.get_debug_type($value)];
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.' is outside enum';
        }
        if (is_object($value)) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! property_exists($value, $key)) {
                    $errors[] = $path.'.'.$key.' is required';
                }
            }
            foreach (get_object_vars($value) as $key => $child) {
                $childSchema = $schema['properties'][$key] ?? (is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : null);
                if ($childSchema) {
                    $errors = array_merge($errors, $this->schemaErrors($child, $childSchema, $document, $path.'.'.$key));
                }
            }
        }
        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $i => $child) {
                $errors = array_merge($errors, $this->schemaErrors($child, $schema['items'], $document, $path.'['.$i.']'));
            }
        }

        return $errors;
    }
}
