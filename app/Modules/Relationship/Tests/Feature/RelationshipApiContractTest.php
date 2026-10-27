<?php

namespace App\Modules\Relationship\Tests\Feature;

use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Exact runtime/Swagger equality prevents both missing and phantom domain operations. */
class RelationshipApiContractTest extends TestCase
{
    #[Test]
    public function every_runtime_method_has_explicit_auth_request_and_typed_response_contracts(): void
    {
        $schema = \App\Modules\Relationship\Tests\Support\RelationshipSchemaSnapshot::document();
        $runtime = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! str_starts_with($route->getActionName(), 'App\\Modules\\Relationship\\')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $runtime[] = strtolower($method).' /'.$route->uri();
                $operation = $schema['paths']['/'.$route->uri()][strtolower($method)] ?? null;
                $this->assertIsArray($operation);
                $this->assertNotEmpty($operation['security']);
                if (isset($route->defaults['relationship_ability'])) {
                    $ability = $route->defaults['relationship_ability'];
                    $this->assertContains($ability, app(ApiAbilityCatalog::class)->values());
                    $this->assertStringContainsString($ability, $operation['description']);
                } else {
                    $this->assertArrayHasKey('nexumRelationshipSignature', $operation['security'][0]);
                    $this->assertArrayNotHasKey('bearerAuth', $operation['security'][0]);
                }
                preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
                $this->assertEqualsCanonicalizing($matches[1], collect($operation['parameters'] ?? [])->where('in', 'path')->pluck('name')->all());
                foreach ($operation['responses'] as $response) {
                    $this->assertArrayHasKey('schema', $response['content']['application/json']);
                }
            }
        }
        $published = [];
        $ids = [];
        foreach ($schema['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (! is_array($operation) || ! in_array('Relationships', $operation['tags'] ?? [], true)) {
                    continue;
                }
                $published[] = $method.' '.$path;
                $ids[] = $operation['operationId'];
            }
        }
        $this->assertCount(26, $runtime);
        $this->assertCount(26, array_unique($ids));
        $this->assertEqualsCanonicalizing($runtime, $published);
        foreach (['inbound_token_hash', 'webhook_secret_encrypted', 'outbound_token_encrypted', 'failure_summary'] as $field) {
            $this->assertArrayNotHasKey($field, $schema['components']['schemas']['RelationshipApiRelationship']['properties']);
        }
    }

    #[Test]
    public function reused_ticket_workflow_is_present_and_documented_on_the_actual_release_branch(): void
    {
        $schema = \App\Modules\Relationship\Tests\Support\RelationshipSchemaSnapshot::document();
        foreach (['api.v1.tickets.relationship-targets.index', 'api.v1.tickets.relationships.escalate',
            'api.v1.tickets.relationship-escalations.index', 'api.v1.tickets.relationship-escalations.show',
            'api.v1.tickets.relationship-links.index'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->assertArrayHasKey(strtolower($method), $schema['paths']['/'.$route->uri()]);
            }
        }
    }
}
