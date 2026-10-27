<?php

namespace App\Modules\Marketing\Tests\Support;

/** Optional synthetic contract evidence; destination is supplied by the test runner. */
trait CollectsMarketingApiSamples
{
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        if (str_contains($uri, '/api/v1/marketing') && $response->status() >= 200 && $response->status() < 300) {
            $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));
            $document = json_decode(file_get_contents(getenv('MARKETING_API_CONTRACT_DOCUMENT') ?: storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
            $contract = $document['paths']['/'.$route->uri()][strtolower($method)]['responses'][$response->status()] ?? null;
            $this->assertIsArray($contract, 'Every successful HTTP method/status must have a published Marketing contract.');
            if ($response->status() !== 204) {
                $schema = $contract['content']['application/json']['schema'] ?? null;
                $this->assertIsArray($schema);
                $errors = [];
                $this->checkSchema(json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR), $schema, $document['components']['schemas'], '$', $errors);
                $this->assertSame([], $errors, $method.' /'.$route->uri().' response contract');
            }
        }
        if (($path = getenv('MARKETING_API_CONTRACT_SAMPLES')) && str_contains($uri, '/api/v1/marketing') && $response->status() < 300) {
            $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));
            file_put_contents($path, json_encode(['method' => strtolower($method), 'path' => '/'.$route->uri(), 'status' => $response->status(),
                'body' => $response->status() === 204 ? null : json_decode($response->getContent(), false), 'fields' => $route->defaults['marketing_fields'] ?? [], 'defaults' => $route->defaults], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        }

        return $response;
    }

    private function checkSchema(mixed $value, array $schema, array $schemas, string $path, array &$errors): void
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return;
        }
        if (isset($schema['$ref'])) {
            $this->checkSchema($value, $schemas[basename($schema['$ref'])], $schemas, $path, $errors);

            return;
        }
        foreach (['oneOf', 'anyOf'] as $mode) {
            if (! isset($schema[$mode])) {
                continue;
            }
            $matches = 0;
            foreach ($schema[$mode] as $part) {
                $candidateErrors = [];
                $this->checkSchema($value, $part, $schemas, $path, $candidateErrors);
                if ($candidateErrors === []) {
                    $matches++;
                }
            }
            if (($mode === 'oneOf' && $matches !== 1) || ($mode === 'anyOf' && $matches < 1)) {
                $errors[] = $path.' does not match '.$mode.' (matches '.$matches.')';
            }
        }
        $type = $schema['type'] ?? null;
        $valid = match ($type) {
            'object' => $value instanceof \stdClass,
            'array' => is_array($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            default => true,
        };
        if (! $valid) {
            $errors[] = $path.' expected '.$type.', got '.get_debug_type($value);

            return;
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.' outside declared enum';
        }
        if ($type === 'object') {
            $fields = get_object_vars($value);
            foreach ($schema['required'] ?? [] as $field) {
                if (! array_key_exists($field, $fields)) {
                    $errors[] = $path.'.'.$field.' required';
                }
            }
            foreach ($fields as $field => $child) {
                if (isset($schema['properties'][$field])) {
                    $this->checkSchema($child, $schema['properties'][$field], $schemas, $path.'.'.$field, $errors);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = $path.'.'.$field.' undeclared';
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->checkSchema($child, $schema['additionalProperties'], $schemas, $path.'.'.$field, $errors);
                }
            }
        } elseif ($type === 'array') {
            foreach ($value as $index => $child) {
                $this->checkSchema($child, $schema['items'] ?? [], $schemas, $path.'.'.$index, $errors);
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = $path.' has too many items';
            }
        }
        if ($type === 'string' && isset($schema['format']) && in_array($schema['format'], ['date-time', 'date'], true)
            && strtotime($value) === false) {
            $errors[] = $path.' invalid '.$schema['format'];
        }
    }
}
