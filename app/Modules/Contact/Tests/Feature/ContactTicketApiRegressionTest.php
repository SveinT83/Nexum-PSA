<?php

namespace App\Modules\Contact\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContactTicketApiRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        $this->tech->givePermissionTo([
            'ticket.view',
            'ticket.update',
        ]);
    }

    #[Test]
    public function contact_api_accepts_explicit_client_site_and_relation_context(): void
    {
        $client = Client::factory()->create(['name' => 'API Contact Client']);
        $site = ClientSite::factory()->create([
            'client_id' => $client->id,
            'name' => 'API Contact Site',
        ]);

        Sanctum::actingAs($this->tech, ['contacts.create', 'contacts.update', 'contacts.read']);

        $response = $this->postJson(route('api.v1.contacts.store'), [
            'display_name' => 'API Reply Contact',
            'email' => 'api.reply@example.test',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'relation_type' => 'contact',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'API Reply Contact')
            ->assertJsonPath('data.primary_email', 'api.reply@example.test');

        $contact = Contact::query()
            ->whereHas('emails', fn ($query) => $query->where('email', 'api.reply@example.test'))
            ->firstOrFail();
        $bridge = ClientUser::query()
            ->where('contact_id', $contact->id)
            ->where('client_site_id', $site->id)
            ->firstOrFail();

        $this->getJson(route('api.v1.clients.contacts.index', $client->id))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $bridge->id,
                'contact_id' => $contact->id,
                'email' => 'api.reply@example.test',
            ]);
    }

    #[Test]
    public function ticket_api_updates_and_reads_back_a_client_user_contact(): void
    {
        $client = Client::factory()->create(['name' => 'Ticket Contact Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $oldContact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Old Ticket Contact',
            'email' => 'old.ticket@example.test',
            'active' => true,
        ]);
        $newContact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'New Ticket Contact',
            'email' => 'new.ticket@example.test',
            'active' => true,
        ]);
        $ticket = $this->ticket($client, $site, $oldContact);

        Sanctum::actingAs($this->tech, ['tickets.read', 'tickets.update']);

        $this->patchJson(route('api.v1.tickets.update', $ticket), [
            'contact_id' => $newContact->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.contact_id', $newContact->id);

        $this->getJson(route('api.v1.tickets.show', $ticket))
            ->assertOk()
            ->assertJsonPath('data.contact_id', $newContact->id);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'contact_id' => $newContact->id,
        ]);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'fields_updated',
        ]);
    }

    #[Test]
    public function ticket_api_rejects_a_contact_from_another_client(): void
    {
        $client = Client::factory()->create(['name' => 'Expected Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $currentContact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'active' => true,
        ]);
        $otherClient = Client::factory()->create(['name' => 'Other Client']);
        $otherSite = ClientSite::factory()->create(['client_id' => $otherClient->id]);
        $otherContact = ClientUser::factory()->create([
            'client_site_id' => $otherSite->id,
            'active' => true,
        ]);
        $ticket = $this->ticket($client, $site, $currentContact);

        Sanctum::actingAs($this->tech, ['tickets.update']);

        $this->patchJson(route('api.v1.tickets.update', $ticket), [
            'contact_id' => $otherContact->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contact_id');

        $this->assertSame($currentContact->id, $ticket->fresh()->contact_id);
    }

    private function ticket(Client $client, ClientSite $site, ClientUser $contact): Ticket
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();

        return Ticket::query()->create([
            'ticket_key' => 'TD-2026-994'.str_pad((string) $contact->id, 3, '0', STR_PAD_LEFT),
            'queue_id' => $defaults['queue']->id,
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'contact_id' => $contact->id,
            'work_context_id' => app(ResolveWorkContext::class)->client($client)->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'api',
            'subject' => 'Contact update regression',
            'is_unread' => false,
        ]);
    }
}
