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
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Jobs\SendTicketReplyEmail;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalTicketApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        app(EnsureTicketDefaults::class)->handle();
    }

    public function test_customer_identity_and_exact_membership_are_required_without_internal_role_grants(): void
    {
        [$client, $site, $contact, $user] = $this->portalFixture('api-portal-context@example.test');
        Sanctum::actingAs($user, ['portal.tickets.read']);
        $membership = CustomerPortalMembership::where('customer_portal_account_id', CustomerPortalAccount::where('user_id', $user->id)->value('id'))->firstOrFail();
        $this->getJson(route('api.v1.portal.tickets.context'))->assertOk()->assertJsonPath('data.membership_id', $membership->id);
        $this->assertSame(0, $user->roles()->count());
        $this->assertSame(0, $user->permissions()->count());
        $this->getJson(route('api.v1.tickets.index'))->assertForbidden();
        $this->getJson(route('api.v1.portal.tickets.index', ['membership_id' => $membership->id + 1000]))->assertForbidden();
        $ordinaryUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($ordinaryUser, ['*']);
        $this->getJson(route('api.v1.portal.tickets.index'))->assertForbidden();
        $user->forceFill(['status' => 'pending'])->save();
        Sanctum::actingAs($user, ['*']);
        $this->getJson(route('api.v1.portal.tickets.index'))->assertForbidden();
    }

    public function test_site_scoped_list_detail_and_public_messages_never_expose_internal_ticket_fields(): void
    {
        [$client, $site, $contact, $user] = $this->portalFixture('api-portal-safe@example.test');
        $visible = $this->ticketFor($client, $site, $contact, ['portal_visible_at' => now(), 'description' => 'Never expose internal description', 'metadata' => ['secret' => 'never-export-api']]);
        $hidden = $this->ticketFor($client, $site, $contact, ['portal_visible_at' => null]);
        $otherSite = ClientSite::factory()->create(['client_id' => $client->id]);
        $other = $this->ticketFor($client, $otherSite, $contact, ['portal_visible_at' => now()]);
        $public = TicketMessage::create(['ticket_id' => $visible->id, 'type' => 'customer_reply', 'visibility' => 'public', 'body' => 'Public update']);
        $internal = TicketMessage::create(['ticket_id' => $visible->id, 'type' => 'internal_note', 'visibility' => 'internal', 'body' => 'Never expose internal note']);
        Sanctum::actingAs($user, ['portal.tickets.read']);
        $this->getJson(route('api.v1.portal.tickets.index'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $visible->id);
        $read = $this->getJson(route('api.v1.portal.tickets.show', $visible))->assertOk()->assertJsonMissingPath('data.owner_id')->assertJsonMissingPath('data.metadata')->assertJsonMissingPath('data.workflow_state_key');
        $this->assertStringNotContainsString('Never expose internal description', $read->getContent());
        $this->getJson(route('api.v1.portal.tickets.messages.index', $visible))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $public->id);
        $this->getJson(route('api.v1.portal.tickets.messages.show', [$visible, $internal]))->assertNotFound();
        $this->getJson(route('api.v1.portal.tickets.show', $hidden))->assertNotFound();
        $this->getJson(route('api.v1.portal.tickets.show', $other))->assertNotFound();
        $this->getJson(route('api.v1.portal.tickets.index', ['per_page' => 101]))->assertUnprocessable();
        $this->getJson(route('api.v1.portal.tickets.sites'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $site->id);
    }

    public function test_create_and_reply_are_customer_authored_atomic_and_retry_safe_without_echo_email(): void
    {
        [$client, $site, $contact, $user] = $this->portalFixture('api-portal-create@example.test');
        Sanctum::actingAs($user, ['portal.tickets.read', 'portal.tickets.create', 'portal.tickets.reply']);
        $payload = ['subject' => 'Portal API Ticket', 'description' => 'Initial customer request', 'idempotency_key' => 'create-once'];
        $created = $this->postJson(route('api.v1.portal.tickets.store'), $payload)->assertCreated();
        $id = $created->json('data.id');
        $ticket = Ticket::findOrFail($id);
        $this->postJson(route('api.v1.portal.tickets.store'), $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame($site->id, $ticket->site_id);
        $this->assertNotNull($ticket->portal_visible_at);
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, TicketMessage::where('ticket_id', $id)->where('visibility', 'public')->count());
        $reply = ['body' => 'Customer API follow-up', 'idempotency_key' => 'reply-once'];
        $url = route('api.v1.portal.tickets.messages.store', $ticket);
        $message = $this->postJson($url, $reply)->assertCreated()->json('data.id');
        $events = \App\Modules\CustomerPortal\Models\CustomerPortalAuditEvent::count();
        $this->postJson($url, $reply)->assertOk()->assertJsonPath('data.id', $message);
        $this->assertSame($events, \App\Modules\CustomerPortal\Models\CustomerPortalAuditEvent::count());
        $this->assertDatabaseHas('ticket_messages', ['id' => $message, 'author_id' => $user->id, 'author_type' => 'portal_user', 'visibility' => 'public']);
        $this->postJson($url, array_replace($reply, ['body' => 'Conflicting follow-up']))->assertStatus(409);
        $this->postJson(route('api.v1.portal.tickets.store'), array_replace($payload, ['description' => 'Conflicting initial text']))->assertStatus(409);
        $this->getJson(route('api.v1.portal.tickets.messages.show', [$ticket, $message]))->assertOk()->assertJsonPath('data.body', 'Customer API follow-up');
        Queue::assertNotPushed(SendTicketReplyEmail::class);
    }

    public function test_read_only_scope_forbidden_ownership_and_revoked_membership_fail_without_effects(): void
    {
        [$client, $site, $contact, $user] = $this->portalFixture('api-portal-grants@example.test');
        Sanctum::actingAs($user, ['portal.tickets.read']);
        $payload = ['subject' => 'Denied', 'description' => 'Denied', 'idempotency_key' => 'deny'];
        $this->postJson(route('api.v1.portal.tickets.store'), $payload)->assertForbidden();
        Sanctum::actingAs($user, ['portal.tickets.create']);
        $this->postJson(route('api.v1.portal.tickets.store'), $payload + ['client_id' => $client->id])->assertUnprocessable();
        $otherSite = ClientSite::factory()->create(['client_id' => $client->id]);
        $this->postJson(route('api.v1.portal.tickets.store'), $payload + ['site_id' => $otherSite->id])->assertUnprocessable();
        CustomerPortalMembership::query()->update(['status' => 'inactive']);
        $this->postJson(route('api.v1.portal.tickets.store'), $payload)->assertForbidden();
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketMessage::count());
    }

    public function test_public_attachment_metadata_and_binary_download_require_exact_public_parent_and_download_scope(): void
    {
        Storage::fake('local');
        [$client, $site, $contact, $user] = $this->portalFixture('api-portal-files@example.test');
        $ticket = $this->ticketFor($client, $site, $contact, ['portal_visible_at' => now()]);
        $message = TicketMessage::create(['ticket_id' => $ticket->id, 'type' => 'customer_reply', 'visibility' => 'public', 'body' => 'Public file']);
        $internal = TicketMessage::create(['ticket_id' => $ticket->id, 'type' => 'internal_note', 'visibility' => 'internal', 'body' => 'Internal file']);
        Storage::disk('local')->put('portal-api/public.txt', 'public-content');
        $file = TicketAttachment::create(['ticket_id' => $ticket->id, 'ticket_message_id' => $message->id, 'source' => 'upload', 'filename' => 'rapport-ø.txt', 'content_type' => 'text/plain', 'size_bytes' => 14, 'disk' => 'local', 'path' => 'portal-api/public.txt']);
        $private = TicketAttachment::create(['ticket_id' => $ticket->id, 'ticket_message_id' => $internal->id, 'source' => 'upload', 'filename' => 'internal.txt', 'disk' => 'local', 'path' => 'portal-api/public.txt']);
        Sanctum::actingAs($user, ['portal.tickets.read']);
        $read = $this->getJson(route('api.v1.portal.tickets.attachments.show', [$ticket, $file]))->assertOk()->assertJsonPath('data.size_bytes', 14)->assertJsonPath('data.content_type', 'text/plain')->assertJsonMissingPath('data.path');
        $this->getJson(route('api.v1.portal.tickets.attachments.index', $ticket))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(route('api.v1.portal.tickets.attachments.download', [$ticket, $file]))->assertForbidden();
        Sanctum::actingAs($user, ['portal.tickets.attachments.download']);
        $download = $this->get($read->json('data.download_url'))->assertOk()->assertDownload();
        $this->assertStringContainsString('rapport-ø.txt', rawurldecode($download->headers->get('Content-Disposition')));
        $this->getJson(route('api.v1.portal.tickets.attachments.download', [$ticket, $private]))->assertNotFound();
        $foreign = $this->ticketFor($client, $site, $contact, ['portal_visible_at' => now()]);
        $message->forceFill(['ticket_id' => $foreign->id])->save();
        $this->getJson(route('api.v1.portal.tickets.attachments.download', [$ticket, $file]))->assertNotFound();
    }

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
        $clientUser = ClientUser::query()->where('client_site_id', $site->id)->where('email', $contact->emails()->value('email'))->first();
        if (! $clientUser) {
            $clientUser = ClientUser::factory()->create([
                'client_site_id' => $site->id,
                'contact_id' => $contact->id,
                'email' => $contact->emails()->value('email'),
            ]);
        }

        return Ticket::factory()->create(array_merge([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'contact_id' => $clientUser->id,
            'subject' => 'Portal visible ticket',
        ], $attributes));
    }
}
