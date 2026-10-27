<?php

namespace App\Modules\Warroom\Tests\Feature;

use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Runtime methods, scope catalog and complete Swagger are checked in both directions. */
class WarroomApiContractTest extends TestCase
{
    #[Test]
    public function runtime_and_swagger_cover_exactly_the_same_seven_operations_and_scopes(): void
    {
        $spec = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $actual = [];
        $ids = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/warroom')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = strtolower($method).' /'.$route->uri();
                $actual[] = $key;
                $operation = $spec['paths']['/'.$route->uri()][strtolower($method)] ?? null;
                $this->assertIsArray($operation, $key);
                $ids[] = $operation['operationId'];
                $ability = $route->defaults['warroom_ability'];
                $this->assertContains($ability, app(ApiAbilityCatalog::class)->values());
                $this->assertSame($ability !== 'warroom.settings.manage', app(ApiAbilityCatalog::class)->isReadOnly($ability));
                $this->assertStringContainsString($ability, $operation['description']);
                $this->assertStringContainsString($route->defaults['warroom_permission'], $operation['description']);
                $this->assertSame([['sanctum' => []]], $operation['security']);
                foreach ([200, 401, 403, 404, 409, 412, 422, 428, 503] as $status) {
                    $this->assertArrayHasKey($status, $operation['responses']);
                    $this->assertArrayHasKey('schema', $operation['responses'][$status]['content']['application/json']);
                }
                preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
                $this->assertEqualsCanonicalizing($matches[1], collect($operation['parameters'] ?? [])->where('in', 'path')->pluck('name')->all());
                if ($method === 'PUT') {
                    foreach (['If-Match', 'Idempotency-Key'] as $header) {
                        $this->assertTrue(collect($operation['parameters'])->firstWhere('name', $header)['required']);
                    }
                    $this->assertTrue($operation['requestBody']['required']);
                }
            }
        }
        $published = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (in_array('Warroom', $operation['tags'] ?? [], true)) {
                    $published[] = $method.' '.$path;
                }
            }
        }
        $this->assertCount(7, $actual);
        $this->assertCount(7, array_unique($ids));
        $this->assertEqualsCanonicalizing($actual, $published);
        $schemas = $spec['components']['schemas'];
        $walk = function ($node) use (&$walk, $schemas): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['$ref'])) {
                $this->assertArrayHasKey(basename($node['$ref']), $schemas);
            }
            foreach ($node as $value) {
                $walk($value);
            }
        };
        $count = 0;
        foreach ($schemas as $name => $schema) {
            if (str_starts_with($name, 'WarroomApi')) {
                $count++;
                $walk($schema);
            }
        }
        $this->assertSame(25, $count);
        $this->assertSame(false, $schemas['WarroomApiSettings']['additionalProperties']);
        $this->assertSame('uuid', $schemas['WarroomApiIntegration']['properties']['id']['format']);
        $this->assertArrayNotHasKey('last_error', $schemas['WarroomApiIntegration']['properties']);
    }
}
