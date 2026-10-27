<?php

namespace App\Modules\CustomField\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Compare the public artifact to actual routes and actual successful HTTP responses. */
class CustomFieldApiContractTest extends TestCase
{
    use RefreshDatabase;

    private array $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function swagger_exactly_matches_all_runtime_customfield_operations_scopes_and_headers(): void
    {
        $runtime = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! str_starts_with($route->getActionName(), 'App\\Modules\\CustomField\\')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $runtime[] = strtolower($method).' /'.$route->uri();
                $operation = $this->document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
                $this->assertIsArray($operation);
                $ability = $route->defaults['customfield_ability'];
                $this->assertContains($ability, app(ApiAbilityCatalog::class)->values());
                $this->assertContains('Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities:'.$ability, $route->gatherMiddleware());
                $this->assertStringContainsString($ability, $operation['description']);
                $this->assertNotEmpty($operation['security']);
                foreach ([401, 403, 404, 409, 412, 422, 428, 503] as $status) {
                    $this->assertArrayHasKey($status, $operation['responses']);
                }
                preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
                $this->assertEqualsCanonicalizing($matches[1], collect($operation['parameters'] ?? [])->where('in', 'path')->pluck('name')->all());
                if ($method !== 'GET') {
                    $this->assertTrue(collect($operation['parameters'])->firstWhere('name', 'Idempotency-Key')['required']);
                }
                if (in_array($method, ['PATCH', 'DELETE'], true)) {
                    $this->assertTrue(collect($operation['parameters'])->firstWhere('name', 'If-Match')['required']);
                }
            }
        }
        $published = [];
        $ids = [];
        foreach ($this->document['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (in_array($method, ['get', 'post', 'patch', 'delete'], true) && in_array('Custom Fields', $operation['tags'] ?? [], true)) {
                    $published[] = $method.' '.$path;
                    $ids[] = $operation['operationId'];
                }
            }
        }
        $this->assertCount(7, $runtime);
        $this->assertCount(7, array_unique($ids));
        $this->assertEqualsCanonicalizing($runtime, $published);
        $this->assertSame('getCustomFields', $this->document['paths']['/api/v1/custom-fields']['get']['operationId']);
        $this->assertSame('getCustomField', $this->document['paths']['/api/v1/custom-fields/{definition}']['get']['operationId']);
        $schemas = array_filter($this->document['components']['schemas'], fn ($key) => str_starts_with($key, 'CustomFieldApi'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(19, $schemas);
        $refs = function ($node) use (&$refs) {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['$ref'])) {
                $this->assertArrayHasKey(basename($node['$ref']), $this->document['components']['schemas']);
            }
            foreach ($node as $child) {
                $refs($child);
            }
        };
        $refs($schemas);
    }

    #[Test]
    public function all_seven_successful_http_operations_match_their_published_typed_schema(): void
    {
        Role::findOrCreate('Tech', 'web');
        Permission::findOrCreate('customfield.manage_settings', 'web');
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $actor->assignRole('Tech');
        $actor->givePermissionTo('customfield.manage_settings');
        $this->withToken($actor->createToken('Contract fixture', ['custom-fields.read', 'custom-fields.create', 'custom-fields.update', 'custom-fields.delete', 'custom-fields.operations.read'])->plainTextToken);
        $observed = [];
        $check = function ($response, string $path, string $method) use (&$observed) {
            $this->assertTrue($response->status() < 300, $response->getContent());
            $schema = $this->document['paths'][$path][$method]['responses'][$response->status()]['content']['application/json']['schema'];
            $this->validateSchema(json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR), $schema, $method.' '.$path);
            $observed[] = ['path' => $path, 'method' => $method, 'status' => $response->status(), 'schema_verified' => true];

            return $response;
        };
        $check($this->getJson('/api/v1/custom-fields/context'), '/api/v1/custom-fields/context', 'get');
        $created = $check($this->postJson('/api/v1/custom-fields', ['model_type' => 'client', 'key' => 'contract_field',
            'label' => 'Contract field', 'field_type' => 'text'], ['Idempotency-Key' => 'contract-create-001']), '/api/v1/custom-fields', 'post');
        $id = $created->json('data.id');
        $check($this->getJson('/api/v1/custom-fields'), '/api/v1/custom-fields', 'get');
        $read = $check($this->getJson('/api/v1/custom-fields/'.$id), '/api/v1/custom-fields/{definition}', 'get');
        $updated = $check($this->patchJson('/api/v1/custom-fields/'.$id, ['help_text' => 'Updated'],
            ['Idempotency-Key' => 'contract-update-001', 'If-Match' => $read->headers->get('ETag')]), '/api/v1/custom-fields/{definition}', 'patch');
        $deleted = $check($this->deleteJson('/api/v1/custom-fields/'.$id, [],
            ['Idempotency-Key' => 'contract-delete-001', 'If-Match' => $updated->headers->get('ETag')]), '/api/v1/custom-fields/{definition}', 'delete');
        $check($this->getJson('/api/v1/custom-fields/operations/'.$deleted->json('operation_id')), '/api/v1/custom-fields/operations/{operation}', 'get');
        $check($this->getJson('/api/v1/custom-fields/operations/'.$created->json('operation_id')), '/api/v1/custom-fields/operations/{operation}', 'get');
        if ($file = getenv('CUSTOM_FIELD_API_OBSERVED')) {
            file_put_contents($file, json_encode($observed, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    private function validateSchema(mixed $value, array $schema, string $where): void
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return;
        }
        if (isset($schema['$ref'])) {
            $this->validateSchema($value, $this->document['components']['schemas'][basename($schema['$ref'])], $where);

            return;
        }
        if (isset($schema['oneOf'])) {
            $matched = 0;
            foreach ($schema['oneOf'] as $option) {
                try {
                    $this->validateSchema($value, $option, $where);
                    $matched++;
                } catch (AssertionFailedError $error) {
                    // Match alternatives independently; the exact published union must have one match.
                }
            }
            $this->assertSame(1, $matched, $where);

            return;
        }
        $type = $schema['type'] ?? null;
        if ($type) {
            $this->assertTrue(match ($type) {
                'object' => is_object($value), 'array' => is_array($value), 'string' => is_string($value),
                'integer' => is_int($value), 'number' => is_int($value) || is_float($value), 'boolean' => is_bool($value),
                default => false,
            }, $where.' expected '.$type);
        }
        if (isset($schema['enum'])) {
            $this->assertContains($value, $schema['enum'], $where);
        }
        if (is_object($value)) {
            foreach ($schema['required'] ?? [] as $required) {
                $this->assertObjectHasProperty($required, $value, $where);
            }
            foreach (get_object_vars($value) as $key => $child) {
                if (isset($schema['properties'][$key])) {
                    $this->validateSchema($child, $schema['properties'][$key], $where.'.'.$key);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $this->fail($where.' contains unsupported property '.$key);
                }
            }
        }
        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $index => $child) {
                $this->validateSchema($child, $schema['items'], $where.'.'.$index);
            }
        }
    }
}
