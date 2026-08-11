<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Jobs\SendTicketReplyEmail;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketCustomerCompletionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Tech', 'guard_name' => 'web']);
        Permission::findOrCreate('ticket.update', 'web');
        Permission::findOrCreate('ticket.reply_customer', 'web');
        Permission::findOrCreate('ticket.close', 'web');

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        $this->tech->givePermissionTo(['ticket.update', 'ticket.reply_customer', 'ticket.close']);

        app(EnsureTicketDefaults::class)->handle();
        Queue::fake();
    }

    #[Test]
    public function api_portal_publication_is_one_way_and_idempotent(): void
    {
        [$ticket] = $this->ticketFixture();

        $notifications = $this->mock(SendCustomerPortalNotification::class);
        $notifications->shouldReceive('handle')->once();

        Sanctum::actingAs($this->tech, ['tickets.portal.publish']);

        $this->postJson(route('api.v1.tickets.portal-visibility.store', $ticket), [
            'portal_visible' => true,
        ])
            ->assertOk()
            ->assertJsonPath('published_now', true)
            ->assertJsonPath('data.portal_visible', true)
            ->assertJsonPath('data.portal_visible_by', $this->tech->id);

        $this->postJson(route('api.v1.tickets.portal-visibility.store', $ticket), [
            'portal_visible' => true,
        ])
            ->assertOk()
            ->assertJsonPath('published_now', false);

        $this->postJson(route('api.v1.tickets.portal-visibility.store', $ticket), [
            'portal_visible' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('portal_visible');

        $this->assertSame(1, TicketEvent::query()
            ->where('ticket_id', $ticket->id)
            ->where('type', 'portal_visibility_enabled')
            ->count());
    }

    #[Test]
    public function idempotent_solution_reply_can_continue_through_decisions_to_resolved_and_close(): void
    {
        [$ticket, , , $contact] = $this->ticketFixture();
        $ticket->forceFill([
            'portal_visible_at' => now(),
            'portal_visible_by' => $this->tech->id,
        ])->save();

        $notifications = $this->mock(SendCustomerPortalNotification::class);
        $notifications->shouldReceive('handle')->once();

        Sanctum::actingAs($this->tech, [
            'tickets.reply_customer',
            'tickets.workflow.read',
            'tickets.actions',
        ]);

        $payload = [
            'body' => 'The verified solution has been applied.',
            'idempotency_key' => 'solution-reply-194',
            'reply_intent' => TicketAction::SEND_SOLUTION,
            'reply_contact_id' => $contact->id,
            'cc' => 'copy@example.test',
        ];

        $first = $this->postJson(route('api.v1.tickets.messages.store', $ticket), $payload)
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('data.message.author_type', 'user')
            ->assertJsonPath('data.message.type', 'customer_reply')
            ->assertJsonPath('data.message.visibility', 'public')
            ->assertJsonPath('data.message.metadata.reply_intent', TicketAction::SEND_SOLUTION)
            ->assertJsonPath('data.message.metadata.is_solution', true);

        $messageId = $first->json('data.message.id');

        $this->postJson(route('api.v1.tickets.messages.store', $ticket), $payload)
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('data.message.id', $messageId);

        $this->assertSame(1, TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('idempotency_key', 'solution-reply-194')
            ->count());
        Queue::assertPushed(SendTicketReplyEmail::class, 1);
        $this->assertSame(1, TicketEvent::query()
            ->where('ticket_id', $ticket->id)
            ->where('type', 'message_added')
            ->count());

        $otherActor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherActor->assignRole('Tech');
        $otherActor->givePermissionTo('ticket.reply_customer');
        Sanctum::actingAs($otherActor, ['tickets.reply_customer']);

        $this->postJson(route('api.v1.tickets.messages.store', $ticket), $payload)
            ->assertStatus(409);

        Sanctum::actingAs($this->tech, [
            'tickets.reply_customer',
            'tickets.workflow.read',
            'tickets.actions',
        ]);

        $decisions = $this->getJson(route('api.v1.tickets.workflow-decisions.show', $ticket))
            ->assertOk();
        $resolvedTransition = collect($decisions->json('data.transitions'))
            ->first(fn (array $transition) => ($transition['to_state_key'] ?? null) === 'resolved');

        $this->assertNotNull($resolvedTransition);
        $this->assertTrue($resolvedTransition['allowed']);

        $this->postJson(route('api.v1.tickets.workflow-transitions.store', [
            $ticket,
            $resolvedTransition['transition_key'],
        ]), ['idempotency_key' => 'resolve-after-solution-194'])
            ->assertOk()
            ->assertJsonPath('data.workflow_state_key', 'resolved');

        $this->postJson(route('api.v1.tickets.close', $ticket), ['outcome' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status.is_closed', true)
            ->assertJsonPath('data.close_outcome', 'completed');

        $this->postJson(route('api.v1.tickets.messages.store', $ticket), array_merge($payload, [
            'body' => 'A different body must conflict.',
        ]))->assertStatus(409);
    }

    #[Test]
    public function api_rejects_missing_abilities_unpublished_tickets_and_foreign_contacts(): void
    {
        [$ticket] = $this->ticketFixture();

        Sanctum::actingAs($this->tech, ['tickets.read']);
        $this->postJson(route('api.v1.tickets.messages.store', $ticket), [
            'body' => 'Blocked by token scope.',
            'idempotency_key' => 'missing-ability',
        ])->assertForbidden();

        $unprivileged = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $unprivileged->assignRole('Tech');
        Sanctum::actingAs($unprivileged, ['tickets.reply_customer']);
        $this->postJson(route('api.v1.tickets.messages.store', $ticket), [
            'body' => 'Blocked by the domain permission.',
            'idempotency_key' => 'missing-domain-permission',
        ])->assertForbidden();

        $internal = Ticket::factory()->create(['client_id' => null, 'site_id' => null, 'contact_id' => null]);
        Sanctum::actingAs($unprivileged, ['tickets.portal.publish']);
        $this->postJson(route('api.v1.tickets.portal-visibility.store', $internal), [
            'portal_visible' => true,
        ])->assertForbidden();

        Sanctum::actingAs($this->tech, ['tickets.reply_customer']);
        $this->postJson(route('api.v1.tickets.messages.store', $ticket), [
            'body' => 'Blocked before publication.',
            'idempotency_key' => 'hidden-ticket',
        ])->assertUnprocessable()->assertJsonValidationErrors('type');

        $ticket->forceFill(['portal_visible_at' => now(), 'portal_visible_by' => $this->tech->id])->save();
        $otherClient = Client::factory()->create();
        $otherSite = ClientSite::factory()->create(['client_id' => $otherClient->id]);
        $otherContact = ClientUser::factory()->create([
            'client_site_id' => $otherSite->id,
            'active' => true,
            'email' => 'foreign@example.test',
        ]);

        $this->postJson(route('api.v1.tickets.messages.store', $ticket), [
            'body' => 'Blocked foreign contact.',
            'idempotency_key' => 'foreign-contact',
            'reply_contact_id' => $otherContact->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('reply_contact_id');

        $this->postJson(route('api.v1.tickets.messages.store', $ticket), [
            'type' => 'internal_note',
            'body' => 'The customer endpoint cannot create internal notes.',
            'idempotency_key' => 'wrong-type',
        ])->assertUnprocessable()->assertJsonValidationErrors('type');

        Sanctum::actingAs($this->tech, ['tickets.portal.publish']);
        $this->postJson(route('api.v1.tickets.portal-visibility.store', $internal), [
            'portal_visible' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('contact_id');
    }

    #[Test]
    public function deleted_idempotent_reply_keeps_its_key_reserved(): void
    {
        [$ticket] = $this->ticketFixture();
        $ticket->forceFill(['portal_visible_at' => now(), 'portal_visible_by' => $this->tech->id])->save();

        Sanctum::actingAs($this->tech, ['tickets.reply_customer']);
        $payload = [
            'body' => 'Reply that will later be deleted.',
            'idempotency_key' => 'deleted-reply-194',
        ];

        $response = $this->postJson(route('api.v1.tickets.messages.store', $ticket), $payload)
            ->assertCreated();
        TicketMessage::query()->findOrFail($response->json('data.message.id'))->delete();

        $this->postJson(route('api.v1.tickets.messages.store', $ticket), $payload)
            ->assertStatus(409);
        Queue::assertPushed(SendTicketReplyEmail::class, 1);
    }

    /** @return array{0: Ticket, 1: Client, 2: ClientSite, 3: ClientUser} */
    private function ticketFixture(): array
    {
        $client = Client::factory()->create(['name' => 'API Completion Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'active' => true,
            'email' => 'customer@example.test',
        ]);
        $ticket = app(StoreTicket::class)->handle([
            'subject' => 'API customer completion flow',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'contact_id' => $contact->id,
            'owner_id' => $this->tech->id,
            'channel' => 'api',
        ], $this->tech);

        return [$ticket, $client, $site, $contact];
    }
}
