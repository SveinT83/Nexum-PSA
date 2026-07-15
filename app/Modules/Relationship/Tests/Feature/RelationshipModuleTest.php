<?php

namespace App\Modules\Relationship\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Models\DocumentationTemplate;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncLink;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Relationship\Support\RelationshipDirection;
use App\Modules\Relationship\Support\RelationshipHealthStatus;
use App\Modules\Relationship\Support\RelationshipStatus;
use App\Modules\Relationship\Support\RelationshipType;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketStatus;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RelationshipModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $viewer;
    private User $limited;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->manager = $this->userWithPermissions('Relationship Manager', [
            'relationships.view',
            'relationships.manage',
            'relationships.escalate',
            'relationships.sync',
            'ticket.view',
        ]);
        $this->viewer = $this->userWithPermissions('Relationship Viewer', [
            'relationships.view',
        ]);
        $this->limited = $this->userWithPermissions('Limited Admin', [
            'system.view',
        ]);
    }

    #[Test]
    public function admin_without_relationship_permission_cannot_open_settings(): void
    {
        $this->actingAs($this->limited)
            ->get(route('tech.admin.system.relationships.index'))
            ->assertForbidden();
    }

    #[Test]
    public function view_permission_can_open_index_but_cannot_save(): void
    {
        $this->actingAs($this->viewer)
            ->get(route('tech.admin.system.relationships.index'))
            ->assertOk()
            ->assertSee('Nexum relationships');

        $this->actingAs($this->viewer)
            ->post(route('tech.admin.system.relationships.store'), [
                'name' => 'Blocked relationship',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function direction_validation_requires_client_or_vendor(): void
    {
        $this->actingAs($this->manager)
            ->post(route('tech.admin.system.relationships.store'), $this->relationshipPayload([
                'direction' => RelationshipDirection::WE_ARE_PROVIDER,
                'client_id' => null,
            ]))
            ->assertSessionHasErrors('client_id');

        $this->actingAs($this->manager)
            ->post(route('tech.admin.system.relationships.store'), $this->relationshipPayload([
                'direction' => RelationshipDirection::WE_USE_PROVIDER,
                'client_id' => null,
                'vendor_id' => null,
            ]))
            ->assertSessionHasErrors('vendor_id');
    }

    #[Test]
    public function secrets_are_not_stored_as_plaintext(): void
    {
        $client = Client::factory()->create(['name' => 'Relationship Client']);

        $this->actingAs($this->manager)
            ->post(route('tech.admin.system.relationships.store'), $this->relationshipPayload([
                'client_id' => $client->id,
                'status' => RelationshipStatus::ACTIVE,
                'capabilities' => [
                    RelationshipCapability::TICKET_SYNC,
                    RelationshipCapability::STATUS_SYNC,
                ],
                'outbound_token' => 'plain-outbound-token',
                'inbound_token' => 'plain-inbound-token',
                'webhook_secret' => 'plain-webhook-secret',
            ]))
            ->assertRedirect();

        $relationship = NexumRelationship::query()->where('name', 'Managed relationship')->firstOrFail();
        $raw = $relationship->getRawOriginal();

        $this->assertNotSame('plain-outbound-token', $raw['outbound_token_encrypted']);
        $this->assertNotSame('plain-webhook-secret', $raw['webhook_secret_encrypted']);
        $this->assertNotSame('plain-inbound-token', $raw['inbound_token_hash']);
        $this->assertTrue(Hash::check('plain-inbound-token', $relationship->inbound_token_hash));
        $this->assertSame('plain-outbound-token', $relationship->outbound_token_encrypted);
        $this->assertSame('plain-webhook-secret', $relationship->webhook_secret_encrypted);
    }

    #[Test]
    public function signed_relationship_webhook_creates_provider_ticket_idempotently(): void
    {
        $client = Client::factory()->create(['name' => 'Remote Customer']);
        $relationship = $this->activeRelationship($client, [
            RelationshipCapability::TICKET_SYNC => true,
        ]);

        $payload = [
            'source_ticket_id' => 'remote-123',
            'source_ticket_key' => 'R-123',
            'source_url' => 'https://remote.example.test/tickets/R-123',
            'subject' => 'Remote printer issue',
            'description' => 'Customer needs help.',
            'client' => ['name' => 'Remote Customer'],
        ];

        $this->signedJson('post', '/api/v1/nexum/relationships/tickets', $relationship, 'inbound-token', 'webhook-secret', $payload)
            ->assertCreated()
            ->assertJsonStructure(['data' => ['ticket_key']]);

        $this->signedJson('post', '/api/v1/nexum/relationships/tickets', $relationship, 'inbound-token', 'webhook-secret', $payload)
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertSame(1, Ticket::query()->where('subject', 'Remote printer issue')->count());
        $this->assertDatabaseHas('nexum_sync_links', [
            'relationship_id' => $relationship->id,
            'domain' => 'ticket',
            'remote_id' => 'remote-123',
            'sync_status' => 'synced',
        ]);
    }

    #[Test]
    public function public_reply_sync_sends_signed_payload_and_records_audit(): void
    {
        Http::fake([
            'remote.example.test/*' => Http::response([
                'data' => [
                    'id' => 'remote-message-1',
                ],
            ], 200),
        ]);

        $client = Client::factory()->create(['name' => 'Ticket Client']);
        $relationship = $this->activeRelationship($client, [
            RelationshipCapability::TICKET_SYNC => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'portal_visible_at' => now(),
            'portal_visible_by' => $this->manager->id,
        ]);
        NexumSyncLink::query()->create([
            'relationship_id' => $relationship->id,
            'domain' => 'ticket',
            'local_type' => Ticket::class,
            'local_id' => $ticket->id,
            'remote_type' => 'ticket',
            'remote_id' => 'remote-ticket-1',
            'direction' => 'outbound',
            'sync_status' => 'failed',
            'last_error' => 'Previous remote lookup failed.',
        ]);

        $message = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->manager->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Public answer from provider.',
            'metadata' => [],
        ]);

        app(\App\Modules\Relationship\Actions\SyncTicketMessageToRelationship::class)->handle($message->id);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Nexum-Signature')
            && $request->hasHeader('X-Nexum-Token', 'outbound-token')
            && str_contains($request->url(), '/api/v1/nexum/relationships/tickets/remote-ticket-1/messages'));
        $this->assertDatabaseHas('nexum_sync_events', [
            'relationship_id' => $relationship->id,
            'event_type' => 'ticket_message_synced',
            'outcome' => 'synced',
        ]);
        $this->assertDatabaseHas('nexum_sync_links', [
            'relationship_id' => $relationship->id,
            'domain' => 'ticket',
            'local_id' => $ticket->id,
            'sync_status' => 'synced',
            'last_error' => null,
        ]);
    }

    #[Test]
    public function inbound_ticket_reply_can_match_an_outbound_link_by_local_ticket_id(): void
    {
        $client = Client::factory()->create(['name' => 'Ticket Client']);
        $relationship = $this->activeRelationship($client, [
            RelationshipCapability::TICKET_SYNC => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        NexumSyncLink::query()->create([
            'relationship_id' => $relationship->id,
            'domain' => 'ticket',
            'local_type' => Ticket::class,
            'local_id' => $ticket->id,
            'remote_type' => 'ticket',
            'remote_id' => 'TD-2026-000079',
            'direction' => 'outbound',
            'sync_status' => 'synced',
        ]);

        $this->signedJson('post', '/api/v1/nexum/relationships/tickets/'.$ticket->id.'/messages', $relationship, 'inbound-token', 'webhook-secret', [
            'source_message_id' => 'provider-message-1',
            'source_ticket_key' => 'TD-2026-000079',
            'body' => 'Reply from provider.',
            'author_name' => 'Provider Tech',
            'author_email' => 'provider@example.test',
        ])->assertCreated();

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Reply from provider.',
        ]);
        $this->assertDatabaseHas('nexum_sync_events', [
            'relationship_id' => $relationship->id,
            'event_type' => 'ticket_message_received',
            'outcome' => 'synced',
        ]);
    }

    #[Test]
    public function inbound_ticket_status_can_match_an_inbound_link_by_local_ticket_key(): void
    {
        $client = Client::factory()->create(['name' => 'Ticket Client']);
        $relationship = $this->activeRelationship($client, [
            RelationshipCapability::STATUS_SYNC => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'ticket_key' => 'TD-2026-000079']);
        $targetStatus = TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();

        NexumSyncLink::query()->create([
            'relationship_id' => $relationship->id,
            'domain' => 'ticket',
            'local_type' => Ticket::class,
            'local_id' => $ticket->id,
            'remote_type' => 'ticket',
            'remote_id' => 'remote-ticket-1',
            'direction' => 'inbound',
            'sync_status' => 'synced',
        ]);

        $this->signedJson('post', '/api/v1/nexum/relationships/tickets/'.$ticket->ticket_key.'/status', $relationship, 'inbound-token', 'webhook-secret', [
            'status' => 'in-progress',
        ])->assertOk();

        $this->assertSame($targetStatus->id, $ticket->refresh()->status_id);
        $this->assertDatabaseHas('nexum_sync_events', [
            'relationship_id' => $relationship->id,
            'event_type' => 'ticket_status_received',
            'outcome' => 'synced',
        ]);
    }

    #[Test]
    public function inbound_documentation_sync_marks_conflict_when_local_copy_changed(): void
    {
        $client = Client::factory()->create(['name' => 'Documentation Client']);
        $relationship = $this->activeRelationship($client, [
            RelationshipCapability::DOCUMENTATION_SYNC => true,
        ]);

        $payload = [
            'source_documentation_id' => 'doc-1',
            'title' => 'Shared Runbook',
            'scope_type' => 'client',
            'category' => ['name' => 'Runbooks', 'slug' => 'runbooks'],
            'template' => ['name' => 'Runbook', 'fields' => []],
            'data' => ['content' => 'Initial shared content.'],
            'content' => 'Initial shared content.',
        ];

        $this->signedJson('post', '/api/v1/nexum/relationships/documentation', $relationship, 'inbound-token', 'webhook-secret', $payload)
            ->assertCreated();

        $documentation = Documentation::query()->where('title', 'Shared Runbook')->firstOrFail();
        $documentation->forceFill([
            'data_json' => ['content' => 'Local edit that must be reviewed.'],
        ])->save();

        $payload['content'] = 'Remote changed content.';
        $payload['data'] = ['content' => 'Remote changed content.'];

        $this->signedJson('post', '/api/v1/nexum/relationships/documentation', $relationship, 'inbound-token', 'webhook-secret', $payload)
            ->assertOk()
            ->assertJsonPath('data.conflict', true);

        $this->assertDatabaseHas('nexum_sync_links', [
            'relationship_id' => $relationship->id,
            'domain' => 'documentation',
            'remote_id' => 'doc-1',
            'sync_status' => 'conflict',
            'conflict_status' => 'needs_review',
        ]);
    }

    #[Test]
    public function inbound_knowledge_rejects_internal_articles(): void
    {
        $client = Client::factory()->create();
        $relationship = $this->activeRelationship($client, [
            RelationshipCapability::KNOWLEDGE_SYNC => true,
        ]);

        $this->signedJson('post', '/api/v1/nexum/relationships/knowledge/articles', $relationship, 'inbound-token', 'webhook-secret', [
            'source_article_id' => 'article-1',
            'title' => 'Internal note',
            'body_markdown' => 'Private content.',
            'visibility' => 'internal',
        ])
            ->assertUnprocessable();

        $this->assertSame(0, Article::query()->where('title', 'Internal note')->count());
    }

    private function userWithPermissions(string $name, array $permissions): User
    {
        $role = Role::query()->create(['name' => $name, 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        $user = User::query()->create([
            'name' => $name,
            'email' => str($name)->slug().'-relationship@example.test',
            'password' => Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function relationshipPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Managed relationship',
            'direction' => RelationshipDirection::WE_ARE_PROVIDER,
            'relationship_type' => RelationshipType::CUSTOMER_PROVIDER,
            'client_id' => Client::factory()->create()->id,
            'vendor_id' => null,
            'remote_base_url' => 'https://remote.example.test',
            'remote_organization_name' => 'Remote Nexum',
            'remote_organization_identifier' => 'remote-nexum',
            'status' => RelationshipStatus::DRAFT,
            'capabilities' => [],
            'ticket_auto_create_queue' => '1',
            'documentation_two_way' => '1',
            'attachment_max_mb' => '10',
            'service_areas' => 'it',
        ], $overrides);
    }

    private function activeRelationship(Client $client, array $capabilities): NexumRelationship
    {
        $relationship = NexumRelationship::query()->create([
            'name' => 'Active relationship',
            'direction' => RelationshipDirection::WE_ARE_PROVIDER,
            'relationship_type' => RelationshipType::CUSTOMER_PROVIDER,
            'client_id' => $client->id,
            'remote_base_url' => 'https://remote.example.test',
            'remote_instance_id' => 'remote-instance',
            'remote_organization_name' => 'Remote Nexum',
            'status' => RelationshipStatus::ACTIVE,
            'health_status' => RelationshipHealthStatus::UNKNOWN,
            'capabilities' => array_merge(RelationshipCapability::defaults(), $capabilities),
            'ticket_policy' => ['auto_create_queue' => true],
            'documentation_policy' => ['two_way' => true],
            'attachment_policy' => ['max_mb' => 10, 'allowed_content_types' => []],
            'status_mapping' => [],
            'service_areas' => ['it'],
            'outbound_token_encrypted' => 'outbound-token',
            'webhook_secret_encrypted' => 'webhook-secret',
        ]);
        $relationship->rotateInboundToken('inbound-token');
        $relationship->save();

        return $relationship->refresh();
    }

    private function signedJson(string $method, string $uri, NexumRelationship $relationship, string $token, string $secret, array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->timestamp;
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->call(
            strtoupper($method),
            $uri,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_NEXUM_TOKEN' => $token,
                'HTTP_X_NEXUM_TIMESTAMP' => $timestamp,
                'HTTP_X_NEXUM_SIGNATURE' => $signature,
                'HTTP_X_NEXUM_RELATIONSHIP' => (string) $relationship->id,
            ],
            $body
        );
    }
}
