<?php

namespace App\Modules\System\Tests\Feature;

use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SystemApiContractTest extends TestCase
{
    #[Test]
    public function every_runtime_operation_has_exact_typed_swagger_scope_native_policy_and_inputs(): void
    {
        $doc = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $runtime = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/system/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $runtime[] = strtolower($method).' /'.$route->uri();
                $op = $doc['paths']['/'.$route->uri()][strtolower($method)] ?? null;
                $this->assertIsArray($op);
                $scope = $route->defaults['system_scope'];
                $this->assertContains($scope, app(ApiAbilityCatalog::class)->values());
                $this->assertStringContainsString($scope, $op['description']);
                if ($permission = $route->defaults['system_permission']) {
                    $this->assertStringContainsString($permission, $op['description']);
                }
                $this->assertNotEmpty($op['security']);
                foreach ([200, 401, 403, 404, 409, 412, 422, 428, 503] as $status) {
                    $this->assertArrayHasKey($status, $op['responses']);
                }
                if ($method !== 'GET' && ! str_ends_with($route->uri(), '/preview')) {
                    $this->assertTrue(collect($op['parameters'])->firstWhere('name', 'Idempotency-Key')['required']);
                }
                $content = $op['requestBody']['content'] ?? [];
                if ($content) {
                    $requestSchema = reset($content)['schema'];
                    $schema = $doc['components']['schemas'][basename($requestSchema['$ref'])];
                    $this->assertFalse($schema['additionalProperties']);
                    $this->assertEqualsCanonicalizing($route->defaults['system_fields'], array_keys($schema['properties']));
                } else {
                    $queryFields = collect($op['parameters'] ?? [])->where('in', 'query')->pluck('name')->all();
                    $this->assertEqualsCanonicalizing($route->defaults['system_fields'] ?? [], $queryFields, 'Undocumented request fields.');
                }
            }
        }
        $published = [];
        foreach ($doc['paths'] as $path => $item) {
            foreach ($item as $method => $op) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete']) && in_array('System', $op['tags'] ?? [], true)) {
                    $published[] = $method.' '.$path;
                }
            }
        }
        $this->assertCount(19, $runtime);
        $this->assertEqualsCanonicalizing($runtime, $published);
        $scan = function ($node) use (&$scan, $doc): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['$ref']) && str_starts_with($node['$ref'], '#/components/schemas/')) {
                $this->assertArrayHasKey(basename($node['$ref']), $doc['components']['schemas']);
            }
            foreach ($node as $child) {
                $scan($child);
            }
        };
        foreach ($doc['components']['schemas'] as $name => $schema) {
            if (str_starts_with($name, 'System')) {
                $scan($schema);
            }
        }
    }
}
