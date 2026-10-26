<?php

namespace App\Modules\Storage\Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorageApiContractTest extends TestCase
{
    #[Test]
    public function generated_swagger_exactly_matches_every_runtime_storage_http_operation(): void
    {
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $runtime = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/storage')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $runtime[] = strtolower($method).' /'.$route->uri();
            }
        }
        $published = [];
        $ids = [];
        foreach ($document['paths'] as $path => $item) {
            if (! str_starts_with($path, '/api/v1/storage')) {
                continue;
            }
            foreach ($item as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }
                $published[] = $method.' '.$path;
                $this->assertNotEmpty($operation['operationId']);
                $ids[] = $operation['operationId'];
                $this->assertNotEmpty($operation['security']);
                $this->assertStringContainsString('Required token abilities:', $operation['description']);
                foreach ([401, 403, 404, 409, 412, 422, 428, 503] as $status) {
                    $this->assertArrayHasKey($status, $operation['responses']);
                }
                preg_match_all('/\{([^}]+)\}/', $path, $matches);
                $parameters = collect($operation['parameters'] ?? [])->where('in', 'path')->pluck('name')->all();
                $this->assertEqualsCanonicalizing($matches[1], $parameters, $method.' '.$path);
            }
        }
        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertEqualsCanonicalizing($runtime, $published);
        $this->assertGreaterThanOrEqual(74, count($runtime));
        $schemas = $document['components']['schemas'];
        $refs = function ($node) use (&$refs, $schemas): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['$ref']) && str_starts_with($node['$ref'], '#/components/schemas/')) {
                $this->assertArrayHasKey(substr($node['$ref'], strlen('#/components/schemas/')), $schemas);
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $refs($child);
                }
            }
        };
        foreach ($document['paths'] as $path => $item) {
            if (str_starts_with($path, '/api/v1/storage')) {
                $refs($item);
            }
        }
        foreach ($schemas as $name => $schema) {
            if (str_starts_with($name, 'StorageApi')) {
                $refs($schema);
            }
        }
        foreach (['note', 'terms', 'email', 'source_provenance'] as $field) {
            $this->assertArrayNotHasKey($field, $schemas['StorageApiSupplier']['properties']);
        }
        $this->assertArrayHasKey('qty_incoming', $schemas['StorageApiItem']['properties']);
        $this->assertArrayHasKey('supplier_lines', $schemas['StorageApiItem']['properties']);
        $this->assertArrayHasKey('version', $schemas['StorageApiProfileExport']['properties']);
    }
}
