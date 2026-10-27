<?php

namespace App\Modules\Intake\Tests\Feature;

/** Validate real HTTP JSON results against the generated contract, retaining only non-sensitive evidence. */
trait IntakeApiContractAssertions
{
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        $path = parse_url($uri, PHP_URL_PATH);
        if (! str_starts_with($path, '/api/v1/intake/') && ! str_starts_with($path, '/api/v1/public/intake/')) {
            return $response;
        }
        $document = json_decode(file_get_contents(getenv('INTAKE_API_SCHEMA_PATH') ?: storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));
        $spec = $document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
        $this->assertIsArray($spec, 'Missing Intake Swagger route '.$method.' '.$route->uri());
        $status = $response->getStatusCode();
        $this->assertArrayHasKey((string) $status, $spec['responses']);
        if ($response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            $this->assertSame(200, $status);
        } else {
            $schema = $spec['responses'][(string) $status]['content']['application/json']['schema'] ?? null;
            $this->assertIsArray($schema);
            $body = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
            $this->assertTrue($this->matchesIntakeSchema($body, $schema, $document['components']['schemas']),
                'Intake schema mismatch for '.$spec['operationId'].' '.$status.': '.$response->getContent());
        }
        if ($directory = getenv('INTAKE_API_EVIDENCE_DIR')) {
            $file = $directory.'/http-observed.json';
            $observed = is_file($file) ? json_decode(file_get_contents($file), true) : [];
            $observed[$spec['operationId']][(string) $status] = ['method' => strtoupper($method),
                'path' => '/'.$route->uri(), 'schema_validated' => true];
            file_put_contents($file, json_encode($observed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $response;
    }

    private function matchesIntakeSchema(mixed $value, array $schema, array $schemas): bool
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return true;
        }
        if (isset($schema['$ref'])) {
            return $this->matchesIntakeSchema($value, $schemas[basename($schema['$ref'])], $schemas);
        }
        if (isset($schema['oneOf'])) {
            return count(array_filter($schema['oneOf'], fn ($branch) => $this->matchesIntakeSchema($value, $branch, $schemas))) === 1;
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return false;
        }
        $type = $schema['type'] ?? null;
        if ($type === 'object') {
            if (! is_object($value)) {
                return false;
            }
            foreach ($schema['required'] ?? [] as $key) {
                if (! property_exists($value, $key)) {
                    return false;
                }
            }
            foreach (get_object_vars($value) as $key => $child) {
                if (isset($schema['properties'][$key])) {
                    if (! $this->matchesIntakeSchema($child, $schema['properties'][$key], $schemas)) {
                        return false;
                    }
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    return false;
                } elseif (is_array($schema['additionalProperties'] ?? null)
                    && ! $this->matchesIntakeSchema($child, $schema['additionalProperties'], $schemas)) {
                    return false;
                }
            }
        } elseif ($type === 'array') {
            if (! is_array($value) || count($value) > ($schema['maxItems'] ?? PHP_INT_MAX)) {
                return false;
            }
            foreach ($value as $child) {
                if (! $this->matchesIntakeSchema($child, $schema['items'] ?? [], $schemas)) {
                    return false;
                }
            }
        } elseif ($type === 'integer' && ! is_int($value)) {
            return false;
        } elseif ($type === 'number' && ! is_numeric($value)) {
            return false;
        } elseif ($type === 'string' && ! is_string($value)) {
            return false;
        } elseif ($type === 'boolean' && ! is_bool($value)) {
            return false;
        }
        if (is_numeric($value) && (($schema['minimum'] ?? -INF) > $value || ($schema['maximum'] ?? INF) < $value)) {
            return false;
        }

        return true;
    }
}
