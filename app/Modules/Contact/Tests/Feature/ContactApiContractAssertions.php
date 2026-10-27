<?php

namespace App\Modules\Contact\Tests\Feature;

/** Successful HTTP results must match the published concrete OpenAPI response. */
trait ContactApiContractAssertions
{
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $response = parent::json($method, $uri, $data, $headers, $options);
        if ($response->status() < 200 || $response->status() >= 300 || ! str_starts_with($uri, '/api/v1/')) {
            return $response;
        }
        $route = \Illuminate\Support\Facades\Route::getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));
        if (! str_starts_with($route->getActionName(), 'App\\Modules\\Contact\\')) {
            return $response;
        }
        $document = json_decode(file_get_contents(getenv('CONTACT_API_CONTRACT_DOCUMENT') ?: storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
        $this->assertNotNull($operation, $method.' '.$route->uri().' has a published operation');
        $this->assertArrayHasKey($response->status(), $operation['responses']);
        if ($response->status() !== 204) {
            $schema = $operation['responses'][$response->status()]['content']['application/json']['schema'] ?? null;
            $this->assertIsArray($schema, 'A successful response must have a typed contract');
            $errors = [];
            $this->checkSchema(json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR),
                $schema, $document['components']['schemas'], '$', $errors);
            $this->assertSame([], $errors, $method.' '.$route->uri().' response contract');
        }
        if ($response->headers->has('ETag') && is_string($response->json('data.revision'))) {
            $this->assertSame($response->headers->get('ETag'), $response->json('data.revision'), 'Body revision and ETag must agree');
        }
        self::$observed[strtolower($method).' /'.$route->uri()] = $response->status();

        return $response;
    }

    private static array $observed = [];

    public static function tearDownAfterClass(): void
    {
        if ($path = getenv('CONTACT_API_EVIDENCE_PATH')) {
            $file = fopen($path, 'c+');
            flock($file, LOCK_EX);
            $old = stream_get_contents($file);
            $results = $old ? json_decode($old, true) : [];
            $results = array_merge($results, self::$observed);
            ksort($results);
            ftruncate($file, 0);
            rewind($file);
            fwrite($file, json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            flock($file, LOCK_UN);
            fclose($file);
        }
        parent::tearDownAfterClass();
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
