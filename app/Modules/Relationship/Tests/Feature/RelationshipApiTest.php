<?php

namespace App\Modules\Relationship\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Models\DocumentationTemplate;
use App\Modules\Relationship\Actions\SyncDocumentationToRelationship;
use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncEvent;
use App\Modules\Relationship\Models\NexumSyncLink;
use App\Modules\Relationship\Models\RelationshipApiCommand;
use App\Modules\Taxonomy\Models\Category;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Real HTTP, persisted read-back, current authority and schema contracts for all staff operations. */
class RelationshipApiTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private string $token;

    private Client $client;

    private array $schema;

    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $role = Role::create(['name' => 'Relationship API operator', 'guard_name' => 'web']);
        $role->givePermissionTo(['relationships.view', 'relationships.manage', 'relationships.sync', 'documentation.view', 'knowledge.view']);
        $this->manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->manager->assignRole($role);
        $this->token = $this->manager->createToken('relationship-contract', ['*'])->plainTextToken;
        $this->client = Client::factory()->create();
        $this->schema = \App\Modules\Relationship\Tests\Support\RelationshipSchemaSnapshot::document();
        Http::preventStrayRequests();
    }

    private function requestApi(string $method, string $path, array $body = [], array $headers = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $response = $this->json($method, '/api/v1/'.$path, $body, array_merge(['Authorization' => 'Bearer '.($token ?? $this->token)], $headers));
        if ($response->isSuccessful()) {
            $template = $response->baseResponse->headers->get('X-Test-Route');
            $route = Route::getRoutes()->match(\Illuminate\Http\Request::create('/api/v1/'.$path, $method));
            $operation = $this->schema['paths']['/'.$route->uri()][strtolower($method)] ?? null;
            $this->assertIsArray($operation, $path);
            $contract = $operation['responses'][$response->status()]['content']['application/json']['schema'] ?? null;
            $this->assertIsArray($contract, $path);
            $this->validateSchema(json_decode($response->getContent()), $contract, $path);
            $this->seen[strtolower($method).' /'.$route->uri()] = true;
        }

        return $response;
    }

    private function validateSchema($value, array $schema, string $path): void
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return;
        }
        if (isset($schema['$ref'])) {
            $this->validateSchema($value, $this->schema['components']['schemas'][basename($schema['$ref'])], $path);

            return;
        }
        if (isset($schema['enum'])) {
            $this->assertContains($value, $schema['enum'], $path);
        }
        if (($schema['type'] ?? null) === 'object') {
            $this->assertIsObject($value, $path);
            foreach ($schema['required'] ?? [] as $field) {
                $this->assertTrue(property_exists($value, $field), $path.'.'.$field);
            }
            foreach (get_object_vars($value) as $field => $child) {
                if (isset($schema['properties'][$field])) {
                    $this->validateSchema($child, $schema['properties'][$field], $path.'.'.$field);
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->validateSchema($child, $schema['additionalProperties'], $path.'.'.$field);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $this->fail('Unknown response field '.$path.'.'.$field);
                }
            }
        } elseif (($schema['type'] ?? null) === 'array') {
            $this->assertIsArray($value, $path);
            foreach ($value as $child) {
                $this->validateSchema($child, $schema['items'], $path.'[]');
            }
        } elseif (($schema['type'] ?? null) === 'integer') {
            $this->assertIsInt($value, $path);
        } elseif (($schema['type'] ?? null) === 'boolean') {
            $this->assertIsBool($value, $path);
        } elseif (($schema['type'] ?? null) === 'string') {
            $this->assertIsString($value, $path);
        }
    }

    private function configuration(array $overrides = []): array
    {
        return array_replace(['name' => 'Contract relationship', 'direction' => 'we_are_provider', 'relationship_type' => 'customer_provider',
            'client_id' => $this->client->id, 'remote_base_url' => 'https://remote.example.test',
            'remote_organization_name' => 'Synthetic remote', 'status' => 'active',
            'capabilities' => ['documentation_sync', 'knowledge_sync'], 'inbound_token' => 'synthetic-inbound',
            'outbound_token' => 'synthetic-outbound', 'webhook_secret' => 'synthetic-signing',
            'service_areas' => ['it', 'accounting'], 'status_mapping' => ['open' => 'new'],
            'attachment_allowed_content_types' => ['text/plain']], $overrides);
    }

    private function createRelationship(): array
    {
        return $this->requestApi('POST', 'relationships', $this->configuration(), ['Idempotency-Key' => 'create-contract-one'])->assertCreated()->json('data.result.relationship');
    }

    private function document(): Documentation
    {
        $category = Category::create(['name' => 'Contract docs', 'slug' => 'contract-docs', 'type' => 'documentation', 'is_active' => true]);
        $template = DocumentationTemplate::create(['name' => 'Contract template', 'category_id' => $category->id, 'fields' => []]);

        return Documentation::create(['template_snapshot_json' => [], 'title' => 'Synthetic shared runbook', 'category_id' => $category->id, 'template_id' => $template->id,
            'scope_type' => 'client', 'client_id' => $this->client->id, 'data_json' => ['content' => 'Safe contract content.']]);
    }

    #[Test]
    public function every_staff_operation_has_positive_typed_http_and_persisted_result_evidence(): void
    {
        config(['relationship-api.outbound_execution_enabled' => true]);
        Http::fake(['remote.example.test/*' => Http::response(['data' => ['id' => 901, 'remote_id' => 901, 'conflict' => false]], 201)]);
        $row = $this->createRelationship();
        $id = $row['id'];
        $this->requestApi('GET', 'relationships')->assertOk()->assertJsonPath('stats.active', 1);
        $this->requestApi('GET', 'relationships/context')->assertOk();
        $this->requestApi('GET', 'relationships/lookups?type=clients')->assertOk();
        $this->requestApi('GET', "relationships/$id")->assertOk();
        $this->requestApi('GET', "relationships/$id/context")->assertOk();
        $this->requestApi('GET', "relationships/$id/health")->assertOk();
        $updated = $this->requestApi('PATCH', "relationships/$id", ['name' => 'Updated synthetic'], ['Idempotency-Key' => 'update-contract-one', 'If-Match' => $row['version']])->assertOk()->json('data.result.relationship');
        $this->assertSame(['it', 'accounting'], $updated['service_areas']);
        $this->assertSame(['open' => 'new'], $updated['status_mapping']);
        $rotated = $this->requestApi('POST', "relationships/$id/rotate-secrets", ['rotate_inbound_token' => true, 'rotate_webhook_secret' => true],
            ['Idempotency-Key' => 'rotate-contract-one', 'If-Match' => $updated['version']])->assertOk();
        $this->assertTrue(Hash::check($rotated->json('data.result.inbound_token'), NexumRelationship::findOrFail($id)->inbound_token_hash));
        $raw = DB::table('relationship_api_commands')->where('id', $rotated->json('data.operation_id'))->value('result');
        $this->assertStringNotContainsString($rotated->json('data.result.inbound_token'), $raw);
        $doc = $this->document();
        $article = Article::create(['owner_id' => $this->manager->id, 'title' => 'Shared contract', 'slug' => 'shared-contract', 'body_markdown' => 'Public synthetic content.', 'visibility' => 'public', 'status' => 'published']);
        foreach (['documentations' => $doc, 'knowledge' => $article] as $segment => $source) {
            $this->requestApi('GET', "relationships/$id/eligible-".($segment === 'documentations' ? 'documentations' : 'knowledge'))->assertOk();
            $preview = $this->requestApi('POST', "relationships/$id/$segment/$source->id/preview")->assertOk()->json('data');
            $push = $this->requestApi('POST', "relationships/$id/$segment/$source->id/push", ['source_version' => $preview['source']['version']],
                ['Idempotency-Key' => 'push-contract-'.$segment, 'If-Match' => $preview['relationship_version']])->assertOk()->assertJsonPath('data.state', 'remote_accepted');
            $this->requestApi('GET', 'relationships/operations/'.$push->json('data.operation_id'))->assertOk();
        }
        $link = NexumSyncLink::where('relationship_id', $id)->firstOrFail();
        $event = NexumSyncEvent::where('relationship_id', $id)->firstOrFail();
        $this->requestApi('GET', "relationships/$id/sync-links")->assertOk();
        $this->requestApi('GET', "relationships/$id/sync-links/$link->id")->assertOk();
        $this->requestApi('GET', "relationships/$id/sync-events")->assertOk();
        $this->requestApi('GET', "relationships/$id/sync-events/$event->id")->assertOk();
        $this->requestApi('GET', 'relationships/operations')->assertOk();
        Http::assertSentCount(2);
        $this->assertCount(21, $this->seen);
        $fresh = $this->requestApi('GET', "relationships/$id")->assertOk()->getContent();
        foreach (['synthetic-inbound', 'synthetic-outbound', 'synthetic-signing', 'inbound_token_hash', 'outbound_token_encrypted', 'webhook_secret_encrypted'] as $secret) {
            $this->assertStringNotContainsString($secret, $fresh);
        }
    }

    #[Test]
    public function replay_stale_updates_and_secret_rotation_do_not_repeat_mutations(): void
    {
        $row = $this->createRelationship();
        $this->requestApi('POST', 'relationships', $this->configuration(), ['Idempotency-Key' => 'create-contract-one'])->assertOk()->assertJsonPath('data.replayed', true);
        $this->requestApi('POST', 'relationships', $this->configuration(['name' => 'Different']), ['Idempotency-Key' => 'create-contract-one'])->assertStatus(409);
        $headers = ['Idempotency-Key' => 'rotation-replay-key', 'If-Match' => $row['version']];
        $one = $this->requestApi('POST', "relationships/$row[id]/rotate-secrets", ['rotate_inbound_token' => true], $headers)->assertOk();
        $two = $this->requestApi('POST', "relationships/$row[id]/rotate-secrets", ['rotate_inbound_token' => true], $headers)->assertOk();
        $this->assertSame($one->json('data.result.inbound_token'), $two->json('data.result.inbound_token'));
        $this->assertSame(1, NexumSyncEvent::where('event_type', 'credentials_rotated')->count());
        $this->requestApi('PATCH', "relationships/$row[id]", ['name' => 'Stale'], ['Idempotency-Key' => 'stale-update-one', 'If-Match' => $row['version']])->assertStatus(412);
        $this->requestApi('PATCH', "relationships/$row[id]", ['name' => 'Missing key'])->assertStatus(428);
        $this->requestApi('GET', 'relationships?per_page=101')->assertUnprocessable();
        $this->requestApi('PATCH', "relationships/$row[id]", ['outbound_token_encrypted' => 'forbidden'])->assertUnprocessable();
    }

    #[Test]
    public function permissions_credentials_scope_and_current_actor_remain_independent(): void
    {
        $row = $this->createRelationship();
        $narrow = $this->manager->createToken('read-only', ['relationships.read'])->plainTextToken;
        $this->requestApi('GET', "relationships/$row[id]", [], [], $narrow)->assertOk();
        $this->requestApi('POST', 'relationships', $this->configuration(), ['Idempotency-Key' => 'denied-create-key'], $narrow)->assertForbidden();
        $manage = $this->manager->createToken('manage-only', ['relationships.manage'])->plainTextToken;
        $this->requestApi('POST', 'relationships', $this->configuration(), ['Idempotency-Key' => 'credential-denial'], $manage)->assertForbidden();
        $this->requestApi('GET', 'relationships/context', [], [], $manage)->assertOk();
        $this->manager->syncRoles([]);
        $this->manager->givePermissionTo('relationships.manage');
        $this->requestApi('GET', 'relationships/context')->assertOk();
        $this->requestApi('GET', 'relationships')->assertForbidden();
        $this->manager->syncPermissions([]);
        $this->requestApi('GET', 'relationships/context')->assertForbidden();
        $this->manager->forceFill(['status' => 'inactive'])->save();
        $this->requestApi('GET', 'relationships/context')->assertForbidden();
    }

    #[Test]
    public function source_privacy_parent_and_receipt_ownership_fail_closed(): void
    {
        $row = $this->createRelationship();
        $doc = $this->document();
        $doc->forceFill(['scope_type' => 'internal'])->save();
        $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/preview")->assertNotFound();
        $article = Article::create(['owner_id' => $this->manager->id, 'title' => 'Draft', 'slug' => 'draft-contract', 'body_markdown' => 'Private', 'status' => 'draft', 'visibility' => 'public']);
        $this->requestApi('POST', "relationships/$row[id]/knowledge/$article->id/preview")->assertNotFound();
        $receipt = RelationshipApiCommand::firstOrFail();
        $other = $this->manager->createToken('different-token', ['*'])->plainTextToken;
        $this->requestApi('GET', 'relationships/operations/'.$receipt->id, [], [], $other)->assertNotFound();
        $this->requestApi('GET', "relationships/$row[id]/sync-links/999999")->assertNotFound();
        $this->requestApi('GET', "relationships/$row[id]/sync-events/999999")->assertNotFound();
        $this->requestApi('GET', "relationships/$row[id]/sync-links/not-a-number")->assertNotFound();
        $this->requestApi('POST', "relationships/$row[id]/documentations/not-a-number/preview")->assertNotFound();

    }

    #[Test]
    public function outbound_gate_unknown_conflict_and_browser_replay_are_truthful(): void
    {
        $row = $this->createRelationship();
        $doc = $this->document();
        $preview = $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/preview")->assertOk()->json('data');
        $body = ['source_version' => $preview['source']['version']];
        $headers = ['Idempotency-Key' => 'disabled-outbound', 'If-Match' => $preview['relationship_version']];
        $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/push", $body, $headers)->assertStatus(409);
        Http::assertNothingSent();
        config(['relationship-api.outbound_execution_enabled' => true]);
        Http::fake(['remote.example.test/*' => Http::sequence()->push(['data' => ['id' => 901, 'conflict' => true]], 200)->push(['message' => 'synthetic-secret-provider-text'], 503)]);
        $headers['Idempotency-Key'] = 'conflict-outbound';
        $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/push", $body, $headers)->assertOk()->assertJsonPath('data.state', 'conflict');
        $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/push", $body, $headers)->assertOk()->assertJsonPath('data.replayed', true);
        app(SyncDocumentationToRelationship::class)->handle($doc, NexumRelationship::findOrFail($row['id']));
        Http::assertSentCount(1);
        $doc->forceFill(['data_json' => ['content' => 'Changed synthetic content.']])->save();
        $preview = $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/preview")->assertOk()->json('data');
        $unknown = $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/push", ['source_version' => $preview['source']['version']],
            ['Idempotency-Key' => 'unknown-outbound', 'If-Match' => $preview['relationship_version']])->assertStatus(202)->assertJsonPath('data.state', 'unresolved');
        $this->assertStringNotContainsString('synthetic-secret-provider-text', $unknown->getContent());
        $this->assertDatabaseHas('nexum_sync_events', ['relationship_id' => $row['id'], 'outcome' => 'unresolved']);
        $this->assertDatabaseHas('nexum_sync_events', ['relationship_id' => $row['id'], 'outcome' => 'conflict']);
        $this->requestApi('GET', 'relationships/operations/'.$unknown->json('data.operation_id'))->assertOk()->assertJsonPath('data.reconciliation_required', true);
    }

    #[Test]
    public function all_five_signed_ingress_operations_keep_their_machine_auth_and_typed_results(): void
    {
        $row = $this->createRelationship();
        $relationship = NexumRelationship::findOrFail($row['id']);
        $relationship->capabilities = array_merge($relationship->capabilities, ['ticket_sync' => true, 'status_sync' => true]);
        $relationship->save();
        $send = function (string $suffix, array $payload) {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $this->app['auth']->forgetGuards();
            $response = $this->call('POST', '/api/v1/nexum/relationships/'.$suffix, [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_NEXUM_TOKEN' => 'synthetic-inbound',
                'HTTP_X_NEXUM_TIMESTAMP' => $timestamp, 'HTTP_X_NEXUM_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'synthetic-signing')], $body);
            $response->assertSuccessful();
            $route = Route::getRoutes()->match(\Illuminate\Http\Request::create('/api/v1/nexum/relationships/'.$suffix, 'POST'));
            $schema = $this->schema['paths']['/'.$route->uri()]['post']['responses'][$response->status()]['content']['application/json']['schema'];
            $this->validateSchema(json_decode($response->getContent()), $schema, $suffix);

            return $response;
        };
        $ticket = $send('tickets', ['source_ticket_id' => 'signed-contract-ticket', 'subject' => 'Synthetic signed Ticket']);
        $key = $ticket->json('data.ticket_key');
        $send("tickets/$key/messages", ['source_message_id' => 'signed-message', 'body' => 'Public synthetic answer']);
        $send("tickets/$key/status", ['status' => 'in-progress']);
        $send('documentation', ['source_documentation_id' => 'signed-doc', 'title' => 'Synthetic inbound document', 'scope_type' => 'client',
            'category' => ['name' => 'Inbound', 'slug' => 'inbound-contract'], 'template' => ['name' => 'Inbound template', 'fields' => []],
            'data' => ['content' => 'Synthetic remote content']]);
        $send('knowledge/articles', ['source_article_id' => 'signed-article', 'title' => 'Synthetic inbound article',
            'body_markdown' => 'Synthetic public content', 'visibility' => 'public', 'status' => 'published']);
        $this->call('POST', '/api/v1/nexum/relationships/tickets', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertUnauthorized();
    }

    #[Test]
    public function changed_source_and_explicit_configuration_validation_do_not_send_or_grant_access(): void
    {
        $row = $this->createRelationship();
        $doc = $this->document();
        config(['relationship-api.outbound_execution_enabled' => true]);
        $preview = $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/preview")->assertOk()->json('data');
        $doc->forceFill(['data_json' => ['content' => 'New version']])->save();
        $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/push", ['source_version' => $preview['source']['version']],
            ['Idempotency-Key' => 'stale-source-push', 'If-Match' => $preview['relationship_version']])->assertStatus(412);
        Http::assertNothingSent();
        $otherClient = Client::factory()->create();
        $doc->forceFill(['client_id' => $otherClient->id])->save();
        $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/preview")->assertNotFound();
        $this->requestApi('POST', 'relationships', $this->configuration(['direction' => 'we_use_provider', 'client_id' => null]),
            ['Idempotency-Key' => 'invalid-vendor-one'])->assertUnprocessable();
        $this->requestApi('POST', 'relationships', $this->configuration(['direction' => 'collaboration']),
            ['Idempotency-Key' => 'invalid-collaboration'])->assertUnprocessable();
        $this->requestApi('POST', 'relationships', $this->configuration(['remote_base_url' => 'http://remote.example.test']),
            ['Idempotency-Key' => 'invalid-http-destination'])->assertUnprocessable();
    }

    #[Test]
    public function malformed_success_and_unresolved_prior_source_never_allow_a_second_send(): void
    {
        $row = $this->createRelationship();
        $doc = $this->document();
        config(['relationship-api.outbound_execution_enabled' => true]);
        Http::fake(['remote.example.test/*' => Http::response(['data' => []], 200)]);
        $preview = $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/preview")->assertOk()->json('data');
        $headers = ['Idempotency-Key' => 'malformed-response-one', 'If-Match' => $preview['relationship_version']];
        $body = ['source_version' => $preview['source']['version']];
        $first = $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/push", $body, $headers)
            ->assertStatus(202)->assertJsonPath('data.state', 'unresolved');
        $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/push", $body, $headers)
            ->assertStatus(202)->assertJsonPath('data.replayed', true);
        $doc->forceFill(['data_json' => ['content' => 'Later source version']])->save();
        $new = $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/preview")->assertOk()->json('data');
        $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/push", ['source_version' => $new['source']['version']],
            ['Idempotency-Key' => 'later-source-blocked', 'If-Match' => $new['relationship_version']])->assertStatus(409);
        Http::assertSentCount(1);
    }

    #[Test]
    public function in_flight_shared_reservation_blocks_an_overlapping_browser_attempt(): void
    {
        $row = $this->createRelationship();
        $doc = $this->document();
        config(['relationship-api.outbound_execution_enabled' => true]);
        $blocked = false;
        Http::fake(function () use (&$blocked, $doc, $row) {
            try {
                app(SyncDocumentationToRelationship::class)->handle($doc->fresh(), NexumRelationship::findOrFail($row['id']));
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $blocked = $error->getStatusCode() === 409;
            }

            return Http::response(['data' => ['id' => 902, 'remote_id' => 902]], 201);
        });
        $preview = $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/preview")->assertOk()->json('data');
        $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/push", ['source_version' => $preview['source']['version']],
            ['Idempotency-Key' => 'overlapping-browser', 'If-Match' => $preview['relationship_version']])->assertOk()->assertJsonPath('data.state', 'remote_accepted');
        $this->assertTrue($blocked);
        Http::assertSentCount(1);
    }

    #[Test]
    public function revoked_bearer_and_missing_source_rights_do_not_expose_configuration_or_eligible_content(): void
    {
        $row = $this->createRelationship();
        $token = $this->manager->createToken('revoked-relationship', ['relationships.read']);
        $plain = $token->plainTextToken;
        $token->accessToken->delete();
        $this->requestApi('GET', 'relationships', [], [], $plain)->assertUnauthorized();
        $this->manager->syncRoles([]);
        $this->manager->givePermissionTo(['relationships.view', 'relationships.sync']);
        $this->requestApi('GET', "relationships/$row[id]/eligible-documentations")->assertForbidden();
        $this->requestApi('GET', "relationships/$row[id]/eligible-knowledge")->assertForbidden();
    }

    #[Test]
    public function definite_missing_credentials_can_be_repaired_without_an_unknown_outcome_or_duplicate_effect(): void
    {
        $row = $this->createRelationship();
        $doc = $this->document();
        $relationship = NexumRelationship::findOrFail($row['id']);
        $relationship->forceFill(['outbound_token_encrypted' => null])->save();
        config(['relationship-api.outbound_execution_enabled' => true]);
        $preview = $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/preview")->assertOk()->json('data');
        $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/push", ['source_version' => $preview['source']['version']],
            ['Idempotency-Key' => 'definite-local-refusal', 'If-Match' => $preview['relationship_version']])
            ->assertOk()->assertJsonPath('data.state', 'failed')->assertJsonPath('data.reconciliation_required', false);
        Http::assertNothingSent();
        $fresh = $this->requestApi('GET', "relationships/$row[id]")->assertOk()->json('data');
        $this->requestApi('PATCH', "relationships/$row[id]", ['outbound_token' => 'synthetic-repaired-token'],
            ['Idempotency-Key' => 'repair-local-credentials', 'If-Match' => $fresh['version']])->assertOk();
        Http::fake(['remote.example.test/*' => Http::response(['data' => ['id' => 903]], 201)]);
        $preview = $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/preview")->assertOk()->json('data');
        $this->requestApi('POST', "relationships/$row[id]/documentations/$doc->id/push", ['source_version' => $preview['source']['version']],
            ['Idempotency-Key' => 'repaired-content-push', 'If-Match' => $preview['relationship_version']])
            ->assertOk()->assertJsonPath('data.state', 'remote_accepted');
        Http::assertSentCount(1);
    }

    #[Test]
    public function all_five_ticket_relationship_dependencies_have_real_typed_success_readback(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        app(\App\Modules\Ticket\Actions\EnsureTicketDefaults::class)->handle();
        $this->manager->givePermissionTo(['ticket.view', 'relationships.escalate']);
        $row = $this->createRelationship();
        $relationship = NexumRelationship::findOrFail($row['id']);
        $relationship->capabilities = array_merge($relationship->capabilities, ['ticket_sync' => true]);
        $relationship->save();
        $ticket = app(\App\Modules\Ticket\Actions\StoreTicket::class)->handle([
            'subject' => 'Synthetic Relationship parity dependency', 'client_id' => $this->client->id,
        ], $this->manager);
        $ticket->forceFill(['portal_visible_at' => now()])->save();
        Http::fake(['remote.example.test/*' => Http::response(['data' => ['remote_id' => 'REMOTE-100', 'url' => 'https://remote.example.test/tickets/REMOTE-100']], 200)]);
        $this->requestApi('GET', "tickets/$ticket->ticket_key/relationship-targets")->assertOk();
        $receipt = $this->requestApi('POST', "tickets/$ticket->ticket_key/relationships/escalate", [
            'relationship_id' => $row['id'], 'idempotency_key' => 'synthetic-dependent-escalation',
        ])->assertOk()->assertJsonPath('data.operation_status', 'completed')->json('data');
        $this->requestApi('GET', "tickets/$ticket->ticket_key/relationship-escalations")->assertOk();
        $this->requestApi('GET', "tickets/$ticket->ticket_key/relationship-escalations/$receipt[id]")->assertOk();
        $this->requestApi('GET', "tickets/$ticket->ticket_key/relationship-links")->assertOk();
        Http::assertSentCount(1);
        $this->assertCount(6, $this->seen); // One Relationship create plus all five Ticket operations.
    }
}
