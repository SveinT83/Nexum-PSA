<?php

namespace App\Modules\Intake\Tests\Feature;

use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntakeApiContractTest extends TestCase
{
    #[Test]
    public function runtime_swagger_scopes_and_success_contracts_cover_all_23_operations(): void
    {
        $doc = json_decode(file_get_contents(getenv('INTAKE_API_SCHEMA_PATH') ?: storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $runtime = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! str_starts_with($route->getActionName(), 'App\\Modules\\Intake\\')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $runtime[] = strtolower($method).' /'.$route->uri();
                $op = $doc['paths']['/'.$route->uri()][strtolower($method)] ?? null;
                $this->assertIsArray($op);
                foreach ($route->defaults['intake_branches'] ?? [] as [$ability, $permission]) {
                    $this->assertContains($ability, app(ApiAbilityCatalog::class)->values());
                    $this->assertStringContainsString($ability, $op['description']);
                }
                preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
                $this->assertEqualsCanonicalizing($matches[1], collect($op['parameters'] ?? [])->where('in', 'path')->pluck('name')->all());
                if ($method !== 'GET' && ! str_ends_with($route->uri(), 'previews')) {
                    $this->assertTrue(collect($op['parameters'])->firstWhere('name', 'Idempotency-Key')['required']);
                }
            }
        }
        $published = [];
        $ids = [];
        foreach ($doc['paths'] as $path => $item) {
            foreach ($item as $method => $op) {
                if (! in_array($method, ['get', 'post', 'put'], true) || ! in_array('Intake', $op['tags'] ?? [], true)) {
                    continue;
                }
                $published[] = $method.' '.$path;
                $ids[] = $op['operationId'];
            }
        }
        foreach (app(ApiAbilityCatalog::class)->all() as $key => $entry) {
            if (str_starts_with($key, 'intake.')) {
                $this->assertSame('Intake', $entry['domain']);
                $this->assertNotEmpty($entry['description']);
            }
        }
        $this->assertCount(23, $runtime);
        $this->assertCount(23, array_unique($ids));
        $this->assertEqualsCanonicalizing($runtime, $published);
        foreach (['owner_id', 'scope_client_id', 'routing_mode', 'target_type', 'auto_create_client'] as $private) {
            $this->assertArrayNotHasKey($private, $doc['components']['schemas']['IntakePublicForm']['properties']);
        }
        foreach (['disk', 'path'] as $private) {
            $this->assertArrayNotHasKey($private, $doc['components']['schemas']['IntakeAttachment']['properties']);
        }
        $walk = function ($node) use (&$walk, $doc): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['$ref'])) {
                $this->assertArrayHasKey(basename($node['$ref']), $doc['components']['schemas']);
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        foreach ($doc['components']['schemas'] as $name => $node) {
            if (str_starts_with($name, 'Intake')) {
                $walk($node);
            }
        }
    }
}
