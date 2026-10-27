<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Relationship\Support\RelationshipDirection;
use App\Modules\Relationship\Support\RelationshipStatus;
use App\Modules\Relationship\Support\RelationshipType;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketRelationshipApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        app(EnsureTicketDefaults::class)->handle();
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        foreach (['ticket.view', 'relationships.escalate'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->actor->givePermissionTo($permission);
        }
        $client = Client::factory()->create(['active' => true]);
        $this->ticket = app(StoreTicket::class)->handle(['subject' => 'Relationship API Ticket', 'client_id' => $client->id], $this->actor);
        $this->ticket->forceFill(['portal_visible_at' => now()])->save();
        Sanctum::actingAs($this->actor, ['tickets.relationships.read', 'tickets.relationships.escalate']);
    }

    public function test_destination_lookup_is_scoped_bounded_and_contains_no_credentials(): void
    {
        $allowed = $this->relationship();
        $this->relationship(['client_id' => Client::factory()->create(['active' => true])->id]);
        $this->relationship(['status' => RelationshipStatus::DRAFT]);
        $read = $this->getJson(route('api.v1.tickets.relationship-targets.index', $this->ticket))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $allowed->id);
        $this->assertStringNotContainsString('synthetic-token', $read->getContent());
        $read->assertJsonMissingPath('data.0.outbound_token_encrypted')->assertJsonMissingPath('data.0.webhook_secret_encrypted');
        $this->getJson(route('api.v1.tickets.relationship-targets.index', ['ticket' => $this->ticket, 'per_page' => 101]))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_success_has_exact_readback_and_transport_replay_never_posts_twice(): void
    {
        Http::fake(['https://peer.example.test/*' => Http::response(['data' => ['remote_id' => 'REMOTE-42', 'url' => 'https://peer.example.test/tickets/REMOTE-42']], 200)]);
        $relationship = $this->relationship();
        $payload = ['relationship_id' => $relationship->id, 'idempotency_key' => 'escalate-once'];
        $url = route('api.v1.tickets.relationships.escalate', $this->ticket);
        $read = $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.operation_status', 'completed')->assertJsonPath('data.sync_link.sync_status', 'synced');
        $id = $read->json('data.id');
        $events = TicketEvent::count();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $id);
        Http::assertSentCount(1);
        $this->assertSame($events, TicketEvent::count());
        $this->getJson($read->json('data.readback_url'))->assertOk()->assertJsonPath('data.sync_link.remote_id', 'REMOTE-42');
        $this->getJson(route('api.v1.tickets.relationship-links.index', $this->ticket))->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson($url, array_replace($payload, ['relationship_id' => $this->relationship()->id]))->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_unknown_outcome_is_durable_truthful_and_blocks_blind_resend_with_another_key(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('private-provider-diagnostic');
        });
        $relationship = $this->relationship();
        $payload = ['relationship_id' => $relationship->id, 'idempotency_key' => 'uncertain-once'];
        $url = route('api.v1.tickets.relationships.escalate', $this->ticket);
        $read = $this->postJson($url, $payload)->assertStatus(202)->assertJsonPath('data.operation_status', 'unresolved');
        $this->assertStringNotContainsString('private-provider-diagnostic', $read->getContent());
        $this->postJson($url, $payload)->assertStatus(202)->assertJsonPath('data.id', $read->json('data.id'));
        $this->assertSame(1, $attempts);
        $this->postJson($url, array_replace($payload, ['idempotency_key' => 'blind-new-key']))->assertStatus(409);
        $this->assertSame(1, $attempts);
        $this->getJson($read->json('data.readback_url'))->assertOk()->assertJsonPath('data.operation_status', 'unresolved');
    }

    public function test_missing_credentials_are_reported_as_failed_not_as_synced_without_external_calls(): void
    {
        $relationship = $this->relationship(['outbound_token_encrypted' => null]);
        $this->postJson(route('api.v1.tickets.relationships.escalate', $this->ticket), ['relationship_id' => $relationship->id, 'idempotency_key' => 'missing-credentials'])
            ->assertOk()->assertJsonPath('data.operation_status', 'failed_pre_send')->assertJsonPath('data.sync_link.sync_status', 'failed');
        Http::assertNothingSent();
    }

    public function test_permissions_destination_client_and_nested_receipt_ownership_fail_before_sending(): void
    {
        $relationship = $this->relationship();
        $payload = ['relationship_id' => $relationship->id, 'idempotency_key' => 'scope-check'];
        Sanctum::actingAs($this->actor, ['tickets.relationships.read']);
        $this->postJson(route('api.v1.tickets.relationships.escalate', $this->ticket), $payload)->assertForbidden();
        Sanctum::actingAs($this->actor, ['tickets.relationships.read', 'tickets.relationships.escalate']);
        $foreign = $this->relationship(['client_id' => Client::factory()->create()->id]);
        $this->postJson(route('api.v1.tickets.relationships.escalate', $this->ticket), array_replace($payload, ['relationship_id' => $foreign->id]))->assertUnprocessable();
        $this->ticket->forceFill(['portal_visible_at' => null])->save();
        $this->postJson(route('api.v1.tickets.relationships.escalate', $this->ticket), $payload)->assertUnprocessable();
        $foreignTicket = app(StoreTicket::class)->handle(['subject' => 'Foreign receipt owner'], $this->actor);
        $receipt = TicketEvent::create(['ticket_id' => $foreignTicket->id, 'type' => 'api_relationship_escalation', 'metadata' => ['operation_status' => 'reserved']]);
        $this->getJson(route('api.v1.tickets.relationship-escalations.show', [$this->ticket, $receipt->id]))->assertNotFound();
        Http::assertNothingSent();
    }

    private function relationship(array $overrides = []): NexumRelationship
    {
        return NexumRelationship::create(array_merge(['name' => 'Synthetic peer', 'direction' => RelationshipDirection::WE_ARE_PROVIDER, 'relationship_type' => RelationshipType::CUSTOMER_PROVIDER,
            'client_id' => $this->ticket->client_id, 'remote_base_url' => 'https://peer.example.test', 'remote_organization_name' => 'Synthetic peer', 'remote_organization_identifier' => 'synthetic-peer',
            'status' => RelationshipStatus::ACTIVE, 'capabilities' => [RelationshipCapability::TICKET_SYNC => true], 'outbound_token_encrypted' => 'synthetic-token', 'webhook_secret_encrypted' => 'synthetic-webhook'], $overrides));
    }
}
