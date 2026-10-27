<?php

namespace App\Modules\Knowledge\Tests\Feature;

use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KnowledgeApiContractTest extends TestCase
{
    #[Test]
    public function swagger_exactly_matches_knowledge_runtime_methods_scopes_and_schema_references(): void
    {
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $runtime = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! str_starts_with($route->getActionName(), 'App\\Modules\\Knowledge\\')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $runtime[] = strtolower($method).' /'.$route->uri();
                $operation = $document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
                $this->assertIsArray($operation);
                $ability = $route->defaults['knowledge_ability'] ?? 'knowledge.portal.read';
                $this->assertContains($ability, app(ApiAbilityCatalog::class)->values());
                $this->assertStringContainsString($ability, $operation['description']);
                $this->assertNotEmpty($operation['security']);
                foreach ([401, 403, 404, 409, 412, 422, 428, 503] as $status) {
                    $this->assertArrayHasKey($status, $operation['responses']);
                }
                preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
                $params = collect($operation['parameters'] ?? [])->where('in', 'path')->pluck('name')->all();
                $this->assertEqualsCanonicalizing($matches[1], $params);
                if ($route->defaults['knowledge_key_required'] ?? false) {
                    $this->assertTrue(collect($operation['parameters'])->firstWhere('name', 'Idempotency-Key')['required']);
                }
            }
        }
        $published = [];
        $ids = [];
        foreach ($document['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true) || ! in_array('Knowledge', $operation['tags'] ?? [], true)) {
                    continue;
                }
                $published[] = $method.' '.$path;
                $ids[] = $operation['operationId'];
            }
        }
        $this->assertCount(44, $runtime);
        $this->assertCount(44, array_unique($ids));
        $this->assertEqualsCanonicalizing($runtime, $published);

        // Existing Swagger operation IDs are integration/SDK contracts, not disposable labels.
        foreach ([
            ['/api/v1/knowledge/articles', 'get', 'getKnowledgeArticleList'],
            ['/api/v1/knowledge/articles', 'post', 'createKnowledgeArticle'],
            ['/api/v1/knowledge/articles/{article}', 'get', 'getKnowledgeArticleById'],
            ['/api/v1/knowledge/articles/{article}', 'put', 'replaceKnowledgeArticle'],
            ['/api/v1/knowledge/articles/{article}', 'patch', 'patchKnowledgeArticle'],
        ] as [$path, $method, $id]) {
            $this->assertSame($id, $document['paths'][$path][$method]['operationId']);
        }
        foreach ((new \ReflectionClass(\App\Modules\Knowledge\Resources\Api\V1\KnowledgeApiOpenApi::class))->getMethods() as $method) {
            foreach ($method->getAttributes() as $attribute) {
                $operation = $attribute->newInstance();
                $verb = strtolower(class_basename($attribute->getName()));
                $this->assertSame($operation->operationId, $document['paths'][$operation->path][$verb]['operationId']);
            }
        }

        $schemas = $document['components']['schemas'];
        $this->assertContains(['$ref' => '#/components/schemas/KnowledgeApiError'],
            $schemas['KnowledgeApiOperation']['properties']['result']['oneOf']);
        $refs = function ($node) use (&$refs, $schemas): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['$ref']) && str_starts_with($node['$ref'], '#/components/schemas/')) {
                $this->assertArrayHasKey(basename($node['$ref']), $schemas);
            }
            foreach ($node as $child) {
                $refs($child);
            }
        };
        foreach ($schemas as $name => $schema) {
            if (str_starts_with($name, 'KnowledgeApi')) {
                $refs($schema);
            }
        }
        foreach (['body_markdown', 'owner_id', 'source_system', 'source_id', 'publication_read_back'] as $field) {
            $this->assertArrayNotHasKey($field, $schemas['KnowledgeApiPortalArticle']['properties']);
        }
    }
}
