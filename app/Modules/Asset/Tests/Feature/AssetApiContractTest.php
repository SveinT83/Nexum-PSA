<?php

namespace App\Modules\Asset\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Independently compares the registered method/path/scope contract with generated Swagger. */
class AssetApiContractTest extends TestCase
{
    public function test_every_asset_operation_is_documented_typed_and_authorized(): void
    {
        $contract = json_decode(file_get_contents(base_path('docs/plans/2026-10-26-assets-api-contract.json')), true, 512, JSON_THROW_ON_ERROR);
        $spec = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $runtime = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/assets')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' /'.$route->uri();
                $runtime[$key] = $route;
            }
        }
        $expected = [];
        foreach ($contract['operations'] as $op) {
            $key = $op['method'].' '.$op['path'];
            $expected[] = $key;
            $this->assertArrayHasKey($key, $runtime);
            $route = $runtime[$key];
            $this->assertSame($op['permissions'], $route->defaults['asset_permissions']);
            $this->assertContains(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':'.$op['scope'], $route->gatherMiddleware());
            $this->assertContains(\App\Modules\Asset\Support\AssetApiAccess::class, $route->gatherMiddleware());
            $swagger = $spec['paths'][$op['path']][strtolower($op['method'])] ?? null;
            $this->assertNotNull($swagger, $key);
            $this->assertSame($op['operation_id'], $swagger['operationId']);
            $this->assertNotEmpty($swagger['responses'][$op['action'] === 'store' ? '201' : '200']['content']['application/json']['schema']);
            if ($op['method'] !== 'GET') {
                $this->assertNotEmpty($swagger['requestBody']['content']['application/json']['schema']);
            }
        }
        $actual = array_keys($runtime);
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
        $documented = [];
        foreach ($spec['paths'] as $path => $methods) {
            if (! str_starts_with($path, '/api/v1/assets')) {
                continue;
            }
            foreach (array_keys($methods) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }
        sort($documented);
        $this->assertSame($expected, $documented);
        foreach ($contract['schemas'] as $name) {
            $this->assertArrayHasKey($name, $spec['components']['schemas']);
        }
        $walk = function ($value) use (&$walk, $spec): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['$ref'])) {
                $name = basename($value['$ref']);
                $this->assertArrayHasKey($name, $spec['components']['schemas']);
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        foreach ($contract['schemas'] as $name) {
            $walk($spec['components']['schemas'][$name]);
        }
        foreach (['user_id', 'vendor_id', 'ip_type', 'sensitivity_level', 'criticality_level'] as $field) {
            $this->assertArrayHasKey($field, $spec['components']['schemas']['AssetApiCreate']['properties']);
            $this->assertArrayHasKey($field, $spec['components']['schemas']['AssetApiPatch']['properties']);
        }
    }
}
