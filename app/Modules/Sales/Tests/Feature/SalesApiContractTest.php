<?php

namespace App\Modules\Sales\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Guard the complete registered interface and Swagger against drift from the frozen approved contract. */
class SalesApiContractTest extends TestCase
{
    public function test_every_registered_method_and_closed_input_has_a_typed_documented_contract(): void
    {
        $contract = json_decode(File::get(app_path('Modules/Sales/Docs/api-contract.json')), true, 512, JSON_THROW_ON_ERROR);
        $directory = sys_get_temp_dir().'/sales-api-schema-'.Str::uuid();
        File::makeDirectory($directory, 0700);
        config(['l5-swagger.defaults.paths.docs' => $directory]);
        try {
            $this->artisan('l5-swagger:generate')->assertSuccessful();
            $spec = json_decode(File::get($directory.'/api-docs.json'), true, 512, JSON_THROW_ON_ERROR);
            $expected = [];
            foreach ($contract['operations'] as $operation) {
                $path = '/api/v1/'.$operation['path'];
                $method = strtolower($operation['method']);
                $key = strtoupper($method).' '.$path;
                $expected[] = $key;
                $route = Route::getRoutes()->getByName('api.v1.'.$operation['name']);
                $this->assertNotNull($route, $key);
                $this->assertContains(strtoupper($method), $route->methods());
                $this->assertSame(ltrim($path, '/'), $route->uri());
                $this->assertSame($operation['controller'].'@'.$operation['action'], $route->getActionName());
                $this->assertSame($operation['fields'], $route->defaults['sales_fields']);
                $this->assertContains(\App\Modules\Sales\Http\Middleware\SalesApiFields::class, $route->gatherMiddleware());
                if ($operation['mode'] === 'staff') {
                    $this->assertContains('auth:sanctum', $route->gatherMiddleware());
                    $this->assertContains(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':'.$operation['ability'], $route->gatherMiddleware());
                    $this->assertContains(\App\Modules\Sales\Http\Middleware\SalesApiGuard::class, $route->gatherMiddleware());
                    $this->assertSame($operation['permission'], $route->defaults['sales_permission']);
                } elseif ($operation['mode'] === 'portal') {
                    $this->assertContains('web', $route->gatherMiddleware());
                    $this->assertContains(\App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess::class, $route->gatherMiddleware());
                }
                $documented = $spec['paths'][$path][$method] ?? null;
                $this->assertNotNull($documented, $key);
                foreach ([403, 404, 409, 422] as $status) {
                    $this->assertArrayHasKey($status, $documented['responses'], $key);
                }
                if ($operation['input_schema']) {
                    $schema = $spec['components']['schemas'][$operation['input_schema']];
                    $this->assertFalse($schema['additionalProperties']);
                    $this->assertSame($operation['fields'], array_keys($schema['properties'] ?? []), $key);
                    $this->assertSame('#/components/schemas/'.$operation['input_schema'], $documented['requestBody']['content']['application/json']['schema']['$ref']);
                }
                if ($operation['output'] !== 'binary' && $operation['output'] !== 'none') {
                    $success = collect($documented['responses'])->first(fn ($value, $status) => (int) $status >= 200 && (int) $status < 300);
                    $this->assertSame('#/components/schemas/'.$operation['output'], $success['content']['application/json']['schema']['properties']['data']['$ref'], $key);
                }
            }
            $actual = [];
            foreach (Route::getRoutes() as $route) {
                if (! str_starts_with($route->uri(), 'api/v1/sales/')) {
                    continue;
                }
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $actual[] = $method.' /'.$route->uri();
                }
            }
            sort($actual);
            sort($expected);
            $this->assertSame($expected, $actual);
            $documented = [];
            foreach ($spec['paths'] as $path => $methods) {
                if (! str_starts_with($path, '/api/v1/sales/')) {
                    continue;
                }
                foreach (array_intersect(array_keys($methods), ['get', 'post', 'put', 'patch', 'delete']) as $method) {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
            sort($documented);
            $this->assertSame($expected, $documented);
            foreach (['unit_cost_ex_vat', 'margin_amount', 'approval_status', 'secure_token'] as $field) {
                $this->assertArrayNotHasKey($field, $spec['components']['schemas']['SalesCustomerVersion']['properties']);
            }
            $this->assertFalse($spec['components']['schemas']['SalesInputSalesPublicQuotesAcceptPost']['properties']['quantities']['additionalProperties']['type'] === 'object');
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
