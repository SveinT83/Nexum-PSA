<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Runtime methods must exactly match published schemas and explicit token abilities. */
class NotificationApiContractTest extends TestCase
{
    #[Test]
    public function runtime_operations_scopes_path_parameters_and_swagger_contracts_match_exactly(): void
    {
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true);
        $runtime = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/notifications')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $runtime[] = strtolower($method).' /'.$route->uri();
                $operation = $document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
                $this->assertIsArray($operation);
                $ability = $route->defaults['notification_ability'];
                $this->assertContains($ability, app(ApiAbilityCatalog::class)->values());
                $this->assertStringContainsString($ability, $operation['description']);
                $this->assertContains(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':'.$ability, $route->gatherMiddleware());
                $this->assertNotEmpty($operation['security']);
                foreach ([401, 403, 404, 409, 412, 422, 428, 429, 503] as $status) {
                    $this->assertArrayHasKey($status, $operation['responses']);
                }
                preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
                $this->assertEqualsCanonicalizing($matches[1], collect($operation['parameters'] ?? [])->where('in', 'path')->pluck('name')->all());
                if ($route->defaults['notification_command'] ?? false) {
                    $this->assertTrue(collect($operation['parameters'])->firstWhere('name', 'Idempotency-Key')['required']);
                }
            }
        }
        $published = [];
        foreach ($document['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'delete'], true) && in_array('Notifications', $operation['tags'] ?? [], true)) {
                    $published[] = $method.' '.$path;
                    $this->assertNotEmpty($operation['responses'][200]['content'] ?? $operation['responses'][202]['content'] ?? null);
                }
            }
        }
        $this->assertCount(30, $runtime);
        $this->assertEqualsCanonicalizing($runtime, $published);
        $schemas = $document['components']['schemas'];
        $check = function (mixed $node) use (&$check, $schemas): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['$ref'])) {
                $this->assertArrayHasKey(basename($node['$ref']), $schemas);
            }
            foreach ($node as $child) {
                $check($child);
            }
        };
        foreach ($schemas as $name => $schema) {
            if (str_starts_with($name, 'NotificationApi')) {
                $check($schema);
            }
        }
        $this->assertCount(39, array_filter(array_keys($schemas), fn ($name) => str_starts_with($name, 'NotificationApi')));
        foreach (['endpoint', 'public_key', 'auth_token', 'secrets'] as $secret) {
            $this->assertArrayNotHasKey($secret, $schemas['NotificationApiDevice']['properties']);
            $this->assertArrayNotHasKey($secret, $schemas['NotificationApiChannel']['properties']);
        }
    }
}
