<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Exact operation/ability/schema evidence is required in addition to workflow tests. */
class UserManagementApiContractTest extends TestCase
{
    public function test_every_runtime_method_is_typed_and_all_new_scopes_exist_with_no_duplicate_operations(): void
    {
        $this->assertSame(dirname(__DIR__, 5), base_path(), 'Tests must boot this exact checkout.');
        $this->assertStringStartsWith(base_path().'/app/', (new \ReflectionClass(\App\Modules\UserManagement\Controllers\Api\V1\UserManagementController::class))->getFileName());
        $other = \Illuminate\Http\Request::create('/api/v1/users/notification-context');
        $other->setRouteResolver(fn () => new \Illuminate\Routing\Route('GET', 'api/v1/users/notification-context',
            ['uses' => 'App\\Modules\\Notification\\Controllers\\Api\\V1\\NotificationController@index']));
        $this->assertFalse(\App\Modules\UserManagement\Support\UserManagementApiPrivacy::matches($other), 'Another domain keeps its own error policy.');
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $runtime = [];
        $ids = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! str_contains($route->getActionName(), 'Modules\\UserManagement\\')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = strtolower($method).' /'.$route->uri();
                $runtime[] = $key;
                $operation = $document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
                $this->assertIsArray($operation, $key);
                $ids[] = $operation['operationId'];
                $success = collect($operation['responses'])->filter(fn ($r, $status) => $status >= 200 && $status < 300);
                $this->assertNotEmpty($success);
                foreach ($success as $response) {
                    $this->assertNotEmpty($response['content']['application/json']['schema']);
                }
                preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
                $this->assertEqualsCanonicalizing($matches[1], collect($operation['parameters'] ?? [])->where('in', 'path')->pluck('name')->all());
                foreach ($route->gatherMiddleware() as $middleware) {
                    if (! str_starts_with($middleware, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':')) {
                        continue;
                    }
                    $ability = explode(':', $middleware, 2)[1];
                    $this->assertContains($ability, app(ApiAbilityCatalog::class)->values());
                    $this->assertNotEmpty($operation['security']);
                    if (isset($route->defaults['um_ability'])) {
                        $this->assertSame([$ability], $operation['x-required-scopes']);
                    }
                }
                if ($route->defaults['um_key_required'] ?? false) {
                    if ($method !== 'GET' && ! ($route->defaults['um_owner_replay'] ?? false)) {
                        $this->assertTrue(collect($operation['parameters'])->firstWhere('name', 'Idempotency-Key')['required']);
                    }
                }
            }
        }
        $this->assertCount(56, $runtime);
        $this->assertCount(count($ids), array_unique($ids));
        $published = [];
        foreach ($document['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)
                    && in_array('UserManagement', $operation['tags'] ?? [], true)) {
                    $published[] = $method.' '.$path;
                }
            }
        }
        $this->assertEqualsCanonicalizing(array_values(array_filter($runtime,
            fn ($key) => ! str_contains($key, '/users/me/work-plan') && ! str_contains($key, '/users/me/calendar-preferences'))), $published);
        $this->assertNotContains('delete /api/v1/users/{user}', $runtime);
        $schemas = $document['components']['schemas'];
        $walk = function ($node) use (&$walk, $schemas): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['$ref']) && str_starts_with($node['$ref'], '#/components/schemas/')) {
                $this->assertArrayHasKey(basename($node['$ref']), $schemas);
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        foreach ($schemas as $name => $schema) {
            if (str_starts_with($name, 'UserManagementApi')) {
                $walk($schema);
            }
        }
        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'] as $field) {
            $this->assertArrayNotHasKey($field, $schemas['UserManagementApiUser']['properties']);
        }
    }
}
