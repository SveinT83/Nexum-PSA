<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Notification\Notifications\CustomerPortalNotification;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Jobs\SendTicketReplyEmail;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Support\TicketPortalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PortalTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Tech']);
        app(EnsureTicketDefaults::class)->handle();
    }

    #[Test]
    public function existing_ticket_is_hidden_until_technician_publishes_it_to_portal(): void
    {
        [$client, $site, $contact, $portalUser] = $this->portalFixture('hidden-ticket@example.test');
        $ticket = $this->ticketFor($client, $site, $contact, [
            'subject' => 'Hidden internal ticket',
            'portal_visible_at' => null,
        ]);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.tickets.index'))
            ->assertOk()
            ->assertDontSee('Hidden internal ticket');

        $this->actingAs($portalUser)
            ->get(route('customer-portal.tickets.show', $ticket))
            ->assertNotFound();

        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');

        $this->actingAs($tech)
            ->post(route('tech.tickets.portal-visibility.update', $ticket), ['portal_visible' => '1'])
            ->assertRedirect(route('tech.tickets.show', $ticket))
            ->assertSessionHas('success');

        $ticket->refresh();
        $this->assertNotNull($ticket->portal_visible_at);
        $this->assertSame($tech->id, $ticket->portal_visible_by);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.tickets.index'))
            ->assertOk()
            ->assertSee('Hidden internal ticket');

        $this->actingAs($tech)
            ->from(route('tech.tickets.show', $ticket))
            ->post(route('tech.tickets.portal-visibility.update', $ticket), ['portal_visible' => '0'])
            ->assertSessionHasErrors('portal_visible');

        $this->assertNotNull($ticket->fresh()->portal_visible_at);
    }

    #[Test]
    public function admin_can_update_default_ticket_customer_portal_visibility(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.tickets'))
            ->assertOk()
            ->assertSee('Customer Portal')
            ->assertSee('Default visibility for new client tickets');

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.tickets.portal-policy.update'), [
                'default_customer_visibility' => TicketPortalPolicy::VISIBILITY_PUBLISHED,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Ticket customer portal policy updated.');

        $this->assertSame(
            TicketPortalPolicy::VISIBILITY_PUBLISHED,
            app(TicketPortalPolicy::class)->defaultCustomerVisibility()
        );
    }

    #[Test]
    public function manual_client_ticket_defaults_to_unpublished_and_blocks_customer_reply(): void
    {
        Queue::fake();

        [$client, $site, $contact, $portalUser] = $this->portalFixture('manual-unpublished@example.test');
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'contact_id' => $contact->id,
            'email' => 'manual-unpublished@example.test',
        ]);
        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');

        $this->actingAs($tech)
            ->post(route('tech.tickets.store'), [
                'subject' => 'Manual silent ticket',
                'description' => 'Prepare customer-facing response.',
                'client_id' => $client->id,
                'site_id' => $site->id,
                'contact_id' => $clientUser->id,
            ])
            ->assertRedirect();

        $ticket = Ticket::query()->where('subject', 'Manual silent ticket')->firstOrFail();
        $this->assertNull($ticket->portal_visible_at);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.tickets.index'))
            ->assertOk()
            ->assertDontSee('Manual silent ticket');

        $this->actingAs($tech)
            ->from(route('tech.tickets.show', $ticket))
            ->post(route('tech.tickets.messages.store', $ticket), [
                'type' => 'customer_reply',
                'visibility' => 'public',
                'reply_contact_id' => $clientUser->id,
                'body' => 'This should stay silent.',
            ])
            ->assertSessionHasErrors('type');

        Queue::assertNotPushed(SendTicketReplyEmail::class);

        $this->actingAs($tech)
            ->post(route('tech.tickets.messages.store', $ticket), [
                'type' => 'internal_note',
                'visibility' => 'internal',
                'body' => 'Internal preparation note.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'type' => 'internal_note',
            'visibility' => 'internal',
            'body' => 'Internal preparation note.',
        ]);
    }

    #[Test]
    public function manual_client_ticket_can_be_created_as_published(): void
    {
        Notification::fake();

        [$client, $site, $contact, $portalUser] = $this->portalFixture('manual-published@example.test');
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'contact_id' => $contact->id,
            'email' => 'manual-published@example.test',
        ]);
        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');

        $this->actingAs($tech)
            ->post(route('tech.tickets.store'), [
                'subject' => 'Manual published ticket',
                'description' => 'Visible from create.',
                'client_id' => $client->id,
                'site_id' => $site->id,
                'contact_id' => $clientUser->id,
                'customer_portal_visibility' => TicketPortalPolicy::VISIBILITY_PUBLISHED,
            ])
            ->assertRedirect();

        $ticket = Ticket::query()->where('subject', 'Manual published ticket')->firstOrFail();
        $this->assertNotNull($ticket->portal_visible_at);
        $this->assertSame($tech->id, $ticket->portal_visible_by);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.tickets.index'))
            ->assertOk()
            ->assertSee('Manual published ticket');

        Notification::assertSentTo($portalUser, CustomerPortalNotification::class);
    }

    #[Test]
    public function portal_user_can_create_visible_ticket_without_customer_reply_email(): void
    {
        Queue::fake();

        [$client, $site, $contact, $portalUser] = $this->portalFixture('create-ticket@example.test');

        $this->actingAs($portalUser)
            ->post(route('customer-portal.tickets.store'), [
                'subject' => 'Printer is offline',
                'description' => 'The printer in reception is offline.',
            ])
            ->assertRedirect();

        $ticket = Ticket::query()->where('subject', 'Printer is offline')->firstOrFail();
        $this->assertSame('customer_portal', $ticket->channel);
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame($site->id, $ticket->site_id);
        $this->assertNotNull($ticket->portal_visible_at);
        $this->assertTrue($ticket->is_unread);

        $clientUser = ClientUser::query()->where('contact_id', $contact->id)->where('client_site_id', $site->id)->firstOrFail();
        $this->assertSame($clientUser->id, $ticket->contact_id);

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'author_id' => $portalUser->id,
            'author_type' => 'portal_user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'The printer in reception is offline.',
        ]);
        $this->assertDatabaseHas('customer_portal_audit_events', [
            'event' => 'portal_ticket_created',
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);

        Queue::assertNotPushed(SendTicketReplyEmail::class);
    }

    #[Test]
    public function portal_detail_shows_public_messages_and_hides_internal_notes(): void
    {
        Queue::fake();

        [$client, $site, $contact, $portalUser] = $this->portalFixture('reply-ticket@example.test');
        $ticket = $this->ticketFor($client, $site, $contact, [
            'subject' => 'Visible ticket',
            'portal_visible_at' => now(),
        ]);

        TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Public technician reply.',
        ]);
        TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'author_type' => 'user',
            'type' => 'internal_note',
            'visibility' => 'internal',
            'body' => 'Internal diagnosis.',
        ]);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Public technician reply.')
            ->assertDontSee('Internal diagnosis.');

        $this->actingAs($portalUser)
            ->post(route('customer-portal.tickets.messages.store', $ticket), [
                'body' => 'Customer follow-up from portal.',
            ])
            ->assertRedirect(route('customer-portal.tickets.show', $ticket));

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'author_id' => $portalUser->id,
            'author_type' => 'portal_user',
            'visibility' => 'public',
            'body' => 'Customer follow-up from portal.',
        ]);
        $this->assertDatabaseHas('customer_portal_audit_events', [
            'event' => 'portal_ticket_reply_created',
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);

        $portalMessage = TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('author_type', 'portal_user')
            ->where('body', 'Customer follow-up from portal.')
            ->firstOrFail();
        $this->assertTrue($ticket->fresh()->is_unread);

        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');

        $this->actingAs($tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Customer follow-up from portal.')
            ->assertSee(route('tech.tickets.messages.read', [$ticket, $portalMessage]), false);

        $this->actingAs($tech)
            ->post(route('tech.tickets.messages.read', [$ticket, $portalMessage]))
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertNotNull($portalMessage->fresh()->read_at);
        $this->assertFalse($ticket->fresh()->is_unread);

        Queue::assertNotPushed(SendTicketReplyEmail::class);
    }

    #[Test]
    public function site_membership_cannot_see_other_site_ticket_but_client_membership_can(): void
    {
        [$client, $site, $contact, $sitePortalUser] = $this->portalFixture('site-scope@example.test');
        $otherSite = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Warehouse']);
        $otherTicket = $this->ticketFor($client, $otherSite, $contact, [
            'subject' => 'Other site ticket',
            'portal_visible_at' => now(),
        ]);

        $this->actingAs($sitePortalUser)
            ->get(route('customer-portal.tickets.index'))
            ->assertOk()
            ->assertDontSee('Other site ticket');

        $this->actingAs($sitePortalUser)
            ->get(route('customer-portal.tickets.show', $otherTicket))
            ->assertNotFound();

        $clientWideContact = $this->contactFor($client, null, 'client-wide@example.test');
        $clientWideUser = $this->portalUser($clientWideContact, $client, null, 'client-wide@example.test');

        $this->actingAs($clientWideUser)
            ->get(route('customer-portal.tickets.index'))
            ->assertOk()
            ->assertSee('Other site ticket');
    }

    /**
     * @return array{0: Client, 1: ClientSite, 2: Contact, 3: User}
     */
    private function portalFixture(string $email): array
    {
        $client = Client::factory()->create(['name' => 'Portal Ticket Client AS']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main Office']);
        $contact = $this->contactFor($client, $site, $email);
        $user = $this->portalUser($contact, $client, $site, $email);

        return [$client, $site, $contact, $user];
    }

    private function contactFor(Client $client, ?ClientSite $site, string $email): Contact
    {
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Ticket Portal Contact',
        ]);
        ContactEmail::query()->create([
            'contact_id' => $contact->id,
            'label' => 'work',
            'email' => $email,
            'is_primary' => true,
            'is_verified' => true,
        ]);
        $this->relateContact($contact, $client, $site);

        return $contact;
    }

    private function relateContact(Contact $contact, Client $client, ?ClientSite $site): void
    {
        foreach (array_filter([$client, $site]) as $related) {
            ContactRelation::query()->create([
                'contact_id' => $contact->id,
                'related_type' => $related->getMorphClass(),
                'related_id' => $related->id,
                'relation_type' => 'contact',
                'is_primary' => true,
            ]);
        }
    }

    private function portalUser(Contact $contact, Client $client, ?ClientSite $site, string $email): User
    {
        $user = User::factory()->create([
            'contact_id' => $contact->id,
            'name' => $contact->display_name,
            'email' => $email,
            'status' => User::STATUS_ACTIVE,
        ]);
        $account = CustomerPortalAccount::query()->create([
            'user_id' => $user->id,
            'contact_id' => $contact->id,
            'status' => CustomerPortalAccount::STATUS_ACTIVE,
        ]);
        CustomerPortalMembership::query()->create([
            'customer_portal_account_id' => $account->id,
            'client_id' => $client->id,
            'site_id' => $site?->id,
            'role' => CustomerPortalMembership::ROLE_VIEWER,
            'status' => CustomerPortalMembership::STATUS_ACTIVE,
        ]);

        return $user;
    }

    private function ticketFor(Client $client, ClientSite $site, Contact $contact, array $attributes = []): Ticket
    {
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'contact_id' => $contact->id,
            'email' => $contact->emails()->value('email'),
        ]);

        return Ticket::factory()->create(array_merge([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'contact_id' => $clientUser->id,
            'subject' => 'Portal visible ticket',
        ], $attributes));
    }
}
