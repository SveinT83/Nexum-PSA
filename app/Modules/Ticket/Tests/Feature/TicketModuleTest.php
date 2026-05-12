<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Controllers\Admin\TicketSettingsController;
use App\Modules\Ticket\Controllers\Tech\TicketController;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Jobs\SendTicketReplyEmail;
use App\Modules\Taxonomy\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);
        Role::create(['name' => 'Admin']);

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
    }

    #[Test]
    public function ticket_routes_are_owned_by_ticket_module(): void
    {
        $this->assertSame(TicketController::class . '@index', Route::getRoutes()->getByName('tech.tickets.index')->getActionName());
        $this->assertSame(TicketController::class . '@show', Route::getRoutes()->getByName('tech.tickets.show')->getActionName());
        $this->assertSame(TicketController::class . '@edit', Route::getRoutes()->getByName('tech.tickets.edit')->getActionName());
        $this->assertSame(TicketController::class . '@update', Route::getRoutes()->getByName('tech.tickets.update')->getActionName());
        $this->assertSame(TicketController::class . '@close', Route::getRoutes()->getByName('tech.tickets.close')->getActionName());
        $this->assertSame(TicketController::class . '@markRead', Route::getRoutes()->getByName('tech.tickets.read')->getActionName());
        $this->assertSame(TicketSettingsController::class . '@index', Route::getRoutes()->getByName('tech.admin.settings.tickets')->getActionName());
    }

    #[Test]
    public function tech_user_can_open_ticket_index_and_defaults_are_created(): void
    {
        $this->actingAs($this->tech)
            ->get(route('tech.tickets.index'))
            ->assertOk()
            ->assertViewIs('ticket::Tech.Tickets.index')
            ->assertViewHas('tickets')
            ->assertViewHas('queues')
            ->assertViewHas('statuses');

        $this->assertDatabaseHas('ticket_queues', ['slug' => 'support', 'is_default' => true]);
        $this->assertDatabaseHas('ticket_statuses', ['slug' => 'new', 'is_default' => true]);
        $this->assertDatabaseHas('ticket_statuses', ['slug' => 'in-progress', 'is_closed' => false]);
        $this->assertDatabaseHas('ticket_statuses', ['slug' => 'waiting-customer', 'is_closed' => false]);
        $this->assertDatabaseHas('ticket_statuses', ['slug' => 'resolved', 'state' => 'resolved']);
        $this->assertDatabaseHas('ticket_statuses', ['slug' => 'closed', 'is_closed' => true]);
        $this->assertDatabaseHas('ticket_priorities', ['slug' => 'normal', 'is_default' => true]);
    }

    #[Test]
    public function tech_user_can_create_ticket_and_add_internal_note(): void
    {
        $category = Category::create([
            'name' => 'Printing',
            'slug' => 'printing',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.create'))
            ->assertOk()
            ->assertViewIs('ticket::Tech.Tickets.create')
            ->assertSee('Printing');

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.store'), [
                'subject' => 'Printer cannot scan',
                'description' => 'Scan to mail fails with authentication error.',
                'category_id' => $category->id,
                'channel' => 'manual',
            ])
            ->assertRedirect();

        $ticket = Ticket::firstOrFail();

        $this->assertStringStartsWith('TD-' . now()->format('Y') . '-', $ticket->ticket_key);
        $this->assertSame('Printer cannot scan', $ticket->subject);
        $this->assertSame($category->id, $ticket->category_id);
        $this->assertSame($this->tech->id, $ticket->owner_id);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame(1, $ticket->events()->where('type', 'created')->count());

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.messages.store', $ticket), [
                'type' => 'internal_note',
                'visibility' => 'internal',
                'body' => 'Restarted SMTP connector.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertSame(2, TicketMessage::count());
        $this->assertSame(1, $ticket->events()->where('type', 'message_added')->count());
    }

    #[Test]
    public function ticket_forms_only_use_active_ticket_categories(): void
    {
        $ticketCategory = Category::create([
            'name' => 'Access',
            'slug' => 'ticket-access',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);
        $documentationCategory = Category::create([
            'name' => 'Documentation Only',
            'slug' => 'documentation-only',
            'type' => 'documentation',
            'is_active' => true,
        ]);
        Category::create([
            'name' => 'Inactive Ticket Category',
            'slug' => 'inactive-ticket-category',
            'type' => Category::TYPE_TICKET,
            'is_active' => false,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.create'))
            ->assertOk()
            ->assertSee('Access')
            ->assertDontSee('Documentation Only')
            ->assertDontSee('Inactive Ticket Category');

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.store'), [
                'subject' => 'Wrong category type',
                'category_id' => $documentationCategory->id,
            ])
            ->assertSessionHasErrors('category_id');

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.store'), [
                'subject' => 'Right category type',
                'category_id' => $ticketCategory->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tickets', [
            'subject' => 'Right category type',
            'category_id' => $ticketCategory->id,
        ]);
    }

    #[Test]
    public function create_ticket_form_scopes_contacts_and_open_tickets_to_selected_client(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $client = Client::factory()->create(['name' => 'Acme Support']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Ada Contact',
        ]);
        $otherContact = ClientUser::factory()->create(['name' => 'Other Contact']);

        Ticket::create([
            'ticket_key' => 'TD-2026-999001',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'client_id' => $client->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Existing open issue',
            'is_unread' => false,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.create', ['client_id' => $client->id]))
            ->assertOk()
            ->assertSee('Ada Contact')
            ->assertDontSee('Other Contact')
            ->assertSee('Existing open issue');

        $this->actingAs($this->tech)
            ->from(route('tech.tickets.create', ['client_id' => $client->id]))
            ->post(route('tech.tickets.store'), [
                'subject' => 'New issue',
                'client_id' => $client->id,
                'contact_id' => $otherContact->id,
            ])
            ->assertSessionHasErrors('contact_id');

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.store'), [
                'subject' => 'New issue',
                'client_id' => $client->id,
                'contact_id' => $contact->id,
                'owner_id' => $this->tech->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tickets', [
            'subject' => 'New issue',
            'client_id' => $client->id,
            'contact_id' => $contact->id,
            'owner_id' => $this->tech->id,
            'channel' => 'manual',
        ]);
    }

    #[Test]
    public function create_ticket_form_prioritizes_contact_assets_before_site_assets(): void
    {
        $client = Client::factory()->create(['name' => 'Asset Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Asset Contact',
        ]);
        $contactAsset = Asset::create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $contact->id,
            'name' => 'Contact Laptop',
            'type' => Asset::TYPE_LAPTOP,
            'hostname' => 'contact-laptop',
        ]);
        Asset::create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'name' => 'Site Printer',
            'type' => Asset::TYPE_OTHER,
            'hostname' => 'site-printer',
        ]);
        $otherClient = Client::factory()->create();
        Asset::create([
            'client_id' => $otherClient->id,
            'name' => 'Other Client Server',
            'type' => Asset::TYPE_SERVER,
        ]);

        $response = $this->actingAs($this->tech)
            ->get(route('tech.tickets.create', [
                'client_id' => $client->id,
                'contact_id' => $contact->id,
            ]))
            ->assertOk()
            ->assertSee('Contact Laptop')
            ->assertSee('Site Printer')
            ->assertDontSee('Other Client Server');

        $response->assertSeeInOrder(['Contact assets', 'Contact Laptop', 'Site assets', 'Site Printer']);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.store'), [
                'subject' => 'Asset linked issue',
                'client_id' => $client->id,
                'contact_id' => $contact->id,
                'asset_id' => $contactAsset->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tickets', [
            'subject' => 'Asset linked issue',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'contact_id' => $contact->id,
            'asset_id' => $contactAsset->id,
        ]);
    }

    #[Test]
    public function create_ticket_form_scopes_assets_to_selected_site_without_contact(): void
    {
        $client = Client::factory()->create(['name' => 'Site Asset Client']);
        $site = ClientSite::factory()->create([
            'client_id' => $client->id,
            'name' => 'Main Office',
        ]);
        $otherSite = ClientSite::factory()->create([
            'client_id' => $client->id,
            'name' => 'Branch Office',
        ]);
        $siteAsset = Asset::create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'name' => 'Main Office Firewall',
            'type' => Asset::TYPE_FIREWALL,
        ]);
        Asset::create([
            'client_id' => $client->id,
            'site_id' => $otherSite->id,
            'name' => 'Branch Switch',
            'type' => Asset::TYPE_SWITCH,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.create', [
                'client_id' => $client->id,
                'site_id' => $site->id,
            ]))
            ->assertOk()
            ->assertSee('Main Office')
            ->assertSee('Main Office Firewall')
            ->assertDontSee('Branch Switch');

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.store'), [
                'subject' => 'Site scoped asset issue',
                'client_id' => $client->id,
                'site_id' => $site->id,
                'asset_id' => $siteAsset->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tickets', [
            'subject' => 'Site scoped asset issue',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'asset_id' => $siteAsset->id,
        ]);
    }

    #[Test]
    public function create_ticket_form_does_not_require_a_tech_role_to_exist(): void
    {
        $admin = User::factory()->create([
            'name' => 'Fallback Technician',
            'status' => User::STATUS_ACTIVE,
        ]);
        $admin->assignRole('Admin');
        Role::where('name', 'Tech')->delete();

        $this->actingAs($admin)
            ->get(route('tech.tickets.create'))
            ->assertOk()
            ->assertSee('Fallback Technician');
    }

    #[Test]
    public function customer_reply_requires_ticket_contact_with_email(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();

        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-999002',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'No contact ticket',
            'is_unread' => false,
        ]);

        $this->actingAs($this->tech)
            ->from(route('tech.tickets.show', $ticket))
            ->post(route('tech.tickets.messages.store', $ticket), [
                'type' => 'customer_reply',
                'visibility' => 'public',
                'body' => 'Customer-facing message.',
            ])
            ->assertSessionHasErrors('type');

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.messages.store', $ticket), [
                'type' => 'internal_note',
                'visibility' => 'public',
                'body' => 'Internal note.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'type' => 'internal_note',
            'visibility' => 'internal',
            'body' => 'Internal note.',
        ]);
    }

    #[Test]
    public function customer_reply_is_queued_for_outbound_email(): void
    {
        Queue::fake();

        $defaults = app(EnsureTicketDefaults::class)->handle();
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'email' => 'contact@example.com',
        ]);

        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-999003',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'client_id' => $client->id,
            'contact_id' => $contact->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Reply queue ticket',
            'is_unread' => false,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.messages.store', $ticket), [
                'type' => 'customer_reply',
                'visibility' => 'public',
                'body' => 'Reply body.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        Queue::assertPushed(SendTicketReplyEmail::class);
        $this->assertFalse($ticket->fresh()->is_unread);
    }

    #[Test]
    public function tech_user_can_mark_ticket_as_read(): void
    {
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999109',
            'is_unread' => true,
        ]);
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'author_type' => 'contact',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Customer replied.',
            'read_at' => null,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Mark as read');

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.read', $ticket))
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertFalse($ticket->fresh()->is_unread);
        $this->assertNotNull($message->fresh()->read_at);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'actor_id' => $this->tech->id,
            'type' => 'marked_read',
            'message' => 'Ticket marked as read.',
        ]);
    }

    #[Test]
    public function tech_user_can_update_ticket_lifecycle_fields(): void
    {
        $ticket = $this->createTicket(null, ['ticket_key' => 'TD-2026-999110']);
        $resolved = TicketStatus::where('slug', 'resolved')->firstOrFail();
        $high = TicketPriority::where('slug', 'high')->firstOrFail();
        $category = Category::create([
            'name' => 'Access',
            'slug' => 'access',
            'is_active' => true,
            'type' => Category::TYPE_TICKET,
        ]);
        $newOwner = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $newOwner->assignRole('Tech');

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Edit ticket');

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.edit', $ticket))
            ->assertOk()
            ->assertViewIs('ticket::Tech.Tickets.edit')
            ->assertSee('Ticket text')
            ->assertSee('Lifecycle')
            ->assertSee('Access');

        $this->actingAs($this->tech)
            ->patch(route('tech.tickets.update', $ticket), [
                'subject' => 'Updated lifecycle subject',
                'description' => 'Updated description text.',
                'queue_id' => $ticket->queue_id,
                'status_id' => $resolved->id,
                'priority_id' => $high->id,
                'category_id' => $category->id,
                'owner_id' => $newOwner->id,
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $ticket->refresh();

        $this->assertSame('Updated lifecycle subject', $ticket->subject);
        $this->assertSame('Updated description text.', $ticket->description);
        $this->assertSame($resolved->id, $ticket->status_id);
        $this->assertSame($high->id, $ticket->priority_id);
        $this->assertSame($category->id, $ticket->category_id);
        $this->assertSame($newOwner->id, $ticket->owner_id);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertNull($ticket->closed_at);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'actor_id' => $this->tech->id,
            'type' => 'fields_updated',
            'message' => 'Ticket fields updated.',
        ]);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'actor_id' => $this->tech->id,
            'type' => 'status_changed',
            'message' => 'Ticket status changed to Resolved.',
        ]);
    }

    #[Test]
    public function tech_user_can_close_ticket_with_lifecycle_timestamps(): void
    {
        $ticket = $this->createTicket(null, ['ticket_key' => 'TD-2026-999111']);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Close');

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.close', $ticket))
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $ticket->refresh();

        $this->assertTrue($ticket->status->is_closed);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertNotNull($ticket->closed_at);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'actor_id' => $this->tech->id,
            'type' => 'status_changed',
            'message' => 'Ticket status changed to Closed.',
        ]);
    }

    #[Test]
    public function ticket_show_displays_latest_outbound_email_status_per_customer_reply(): void
    {
        $contact = ClientUser::factory()->create([
            'name' => 'Ada Contact',
            'email' => 'ada@example.com',
        ]);
        $ticket = $this->createTicket($contact, ['ticket_key' => 'TD-2026-999104']);
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Visible reply.',
        ]);

        EmailLog::create([
            'direction' => 'outbound',
            'scope' => 'tickets',
            'level' => 'info',
            'code' => 'TICKET_EMAIL_SENT',
            'message' => 'Ticket reply email sent.',
            'context_json' => ['ticket_message_id' => $message->id],
            'rfc_message_id' => '<show-status@example.com>',
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Email sent')
            ->assertSee('Ticket reply email sent.')
            ->assertSee('&lt;show-status@example.com&gt;', false);
    }

    #[Test]
    public function send_ticket_reply_email_job_renders_template_and_logs_success(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Ada Contact',
            'email' => 'ada@example.com',
        ]);
        $account = EmailAccount::create($this->emailAccountData([
            'address' => 'support@example.com',
            'defaults_for' => ['tickets'],
        ]));
        EmailTemplate::create([
            'scope' => 'tickets',
            'key' => 'ticket_reply',
            'name' => 'Ticket reply',
            'subject' => '[{{ ticket_key }}] {{ ticket_subject }}',
            'body_html' => '<p>{{ message_body }}</p>',
            'body_text' => '{{ message_body }}',
            'variables' => ['ticket_key', 'ticket_subject', 'contact_name', 'message_body', 'technician_name'],
            'is_default' => true,
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-999004',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'client_id' => $client->id,
            'contact_id' => $contact->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'SMTP job ticket',
            'is_unread' => false,
        ]);
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Rendered reply.',
        ]);

        $this->mock(SmtpAccountMailer::class, function ($mock) use ($account) {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(fn ($resolvedAccount, $toEmail, $toName, $subject, $html, $text) =>
                    $resolvedAccount->is($account)
                    && $toEmail === 'ada@example.com'
                    && $toName === 'Ada Contact'
                    && $subject === '[TD-2026-999004] SMTP job ticket'
                    && $html === '<p>Rendered reply.</p>'
                    && $text === 'Rendered reply.'
                )
                ->andReturn('<message-id@example.com>');
        });

        app(SendTicketReplyEmail::class, ['ticketMessageId' => $message->id])->handle(
            app(\App\Modules\Email\Services\DefaultEmailAccountResolver::class),
            app(\App\Modules\Email\Services\EmailTemplateRenderer::class),
            app(SmtpAccountMailer::class)
        );

        $this->assertDatabaseHas('email_logs', [
            'direction' => 'outbound',
            'account_id' => $account->id,
            'scope' => 'tickets',
            'level' => 'info',
            'code' => 'TICKET_EMAIL_SENT',
            'rfc_message_id' => '<message-id@example.com>',
        ]);
    }

    #[Test]
    public function send_ticket_reply_email_job_logs_missing_contact_email(): void
    {
        $ticket = $this->createTicket(null, ['ticket_key' => 'TD-2026-999105']);
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Cannot send without contact.',
        ]);

        app(SendTicketReplyEmail::class, ['ticketMessageId' => $message->id])->handle(
            app(\App\Modules\Email\Services\DefaultEmailAccountResolver::class),
            app(\App\Modules\Email\Services\EmailTemplateRenderer::class),
            app(SmtpAccountMailer::class)
        );

        $this->assertDatabaseHas('email_logs', [
            'direction' => 'outbound',
            'scope' => 'tickets',
            'level' => 'error',
            'code' => 'TICKET_EMAIL_NO_CONTACT',
            'message' => 'Ticket reply has no contact email.',
        ]);
    }

    #[Test]
    public function send_ticket_reply_email_job_logs_missing_default_account(): void
    {
        $contact = ClientUser::factory()->create(['email' => 'ada@example.com']);
        $ticket = $this->createTicket($contact, ['ticket_key' => 'TD-2026-999106']);
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'No account reply.',
        ]);

        EmailTemplate::create($this->ticketReplyTemplateData());

        app(SendTicketReplyEmail::class, ['ticketMessageId' => $message->id])->handle(
            app(\App\Modules\Email\Services\DefaultEmailAccountResolver::class),
            app(\App\Modules\Email\Services\EmailTemplateRenderer::class),
            app(SmtpAccountMailer::class)
        );

        $this->assertDatabaseHas('email_logs', [
            'direction' => 'outbound',
            'scope' => 'tickets',
            'level' => 'error',
            'code' => 'TICKET_EMAIL_NO_ACCOUNT',
            'message' => 'No active ticket outbound email account is configured.',
        ]);
    }

    #[Test]
    public function send_ticket_reply_email_job_logs_missing_template(): void
    {
        $contact = ClientUser::factory()->create(['email' => 'ada@example.com']);
        $ticket = $this->createTicket($contact, ['ticket_key' => 'TD-2026-999107']);
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'No template reply.',
        ]);
        $account = EmailAccount::create($this->emailAccountData([
            'address' => 'support@example.com',
            'defaults_for' => ['tickets'],
        ]));

        app(SendTicketReplyEmail::class, ['ticketMessageId' => $message->id])->handle(
            app(\App\Modules\Email\Services\DefaultEmailAccountResolver::class),
            app(\App\Modules\Email\Services\EmailTemplateRenderer::class),
            app(SmtpAccountMailer::class)
        );

        $this->assertDatabaseHas('email_logs', [
            'direction' => 'outbound',
            'account_id' => $account->id,
            'scope' => 'tickets',
            'level' => 'error',
            'code' => 'TICKET_EMAIL_NO_TEMPLATE',
            'message' => 'No active ticket_reply email template exists.',
        ]);
    }

    #[Test]
    public function send_ticket_reply_email_job_logs_smtp_failure_and_reraises(): void
    {
        $contact = ClientUser::factory()->create([
            'name' => 'Ada Contact',
            'email' => 'ada@example.com',
        ]);
        $ticket = $this->createTicket($contact, ['ticket_key' => 'TD-2026-999108']);
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Failure reply.',
        ]);
        $account = EmailAccount::create($this->emailAccountData([
            'address' => 'support@example.com',
            'defaults_for' => ['tickets'],
        ]));
        EmailTemplate::create($this->ticketReplyTemplateData());

        $this->mock(SmtpAccountMailer::class, function ($mock) {
            $mock->shouldReceive('send')
                ->once()
                ->andThrow(new \RuntimeException('SMTP refused the message.'));
        });

        try {
            app(SendTicketReplyEmail::class, ['ticketMessageId' => $message->id])->handle(
                app(\App\Modules\Email\Services\DefaultEmailAccountResolver::class),
                app(\App\Modules\Email\Services\EmailTemplateRenderer::class),
                app(SmtpAccountMailer::class)
            );

            $this->fail('Expected SMTP exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SMTP refused the message.', $e->getMessage());
        }

        $this->assertDatabaseHas('email_logs', [
            'direction' => 'outbound',
            'account_id' => $account->id,
            'scope' => 'tickets',
            'level' => 'error',
            'code' => 'TICKET_EMAIL_SEND_FAILED',
            'message' => 'SMTP refused the message.',
        ]);
        $this->assertSame('SMTP_SEND', $account->fresh()->last_error_code);
    }

    #[Test]
    public function admin_user_can_open_ticket_settings_from_module(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.tickets'))
            ->assertOk()
            ->assertViewIs('ticket::Admin.Settings.index');
    }

    #[Test]
    public function admin_can_update_default_ticket_email_account(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');

        $oldDefault = EmailAccount::create($this->emailAccountData([
            'address' => 'old@example.com',
            'defaults_for' => ['tickets', 'sales'],
        ]));
        $newDefault = EmailAccount::create($this->emailAccountData([
            'address' => 'new@example.com',
            'defaults_for' => ['alerts'],
        ]));

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.tickets.default-email-account.update'), [
                'email_account_id' => $newDefault->id,
            ])
            ->assertRedirect();

        $this->assertSame(['sales'], $oldDefault->fresh()->defaults_for);
        $this->assertEqualsCanonicalizing(['alerts', 'tickets'], $newDefault->fresh()->defaults_for);
    }

    #[Test]
    public function admin_can_manage_ticket_types_and_queues(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.tickets.types.store'), [
                'name' => 'Sales Lead',
                'slug' => 'sales-lead',
                'description' => 'Inbound sales conversations.',
                'is_active' => '1',
                'is_deletable' => '1',
                'sort_order' => 30,
            ])
            ->assertRedirect();

        $type = TicketType::where('slug', 'sales-lead')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('tech.admin.settings.tickets.types.update', $type), [
                'name' => 'Lead',
                'slug' => 'lead-custom',
                'description' => 'Custom lead type.',
                'is_active' => '1',
                'is_deletable' => '1',
                'sort_order' => 35,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ticket_types', [
            'id' => $type->id,
            'name' => 'Lead',
            'slug' => 'lead-custom',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.tickets.queues.store'), [
                'name' => 'Sales',
                'slug' => 'sales',
                'description' => 'Sales queue.',
                'email_address' => 'sales@example.com',
                'is_active' => '1',
                'is_default' => '1',
                'sort_order' => 20,
            ])
            ->assertRedirect();

        $queue = TicketQueue::where('slug', 'sales')->firstOrFail();

        $this->assertTrue($queue->is_default);
        $this->assertDatabaseHas('ticket_queues', [
            'id' => $queue->id,
            'email_address' => 'sales@example.com',
        ]);
    }

    #[Test]
    public function protected_or_used_ticket_types_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');
        $defaults = app(EnsureTicketDefaults::class)->handle();

        $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999112',
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
        ]);

        $this->actingAs($admin)
            ->delete(route('tech.admin.settings.tickets.types.destroy', $defaults['type']))
            ->assertSessionHasErrors('type');

        $this->assertDatabaseHas('ticket_types', [
            'id' => $defaults['type']->id,
        ]);
    }

    #[Test]
    public function admin_can_create_ticket_rule(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');
        $defaults = app(EnsureTicketDefaults::class)->handle();

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.tickets.rules.store'), [
                'name' => 'Inbound email to support',
                'description' => 'Route email-created tickets.',
                'weight' => 10,
                'is_active' => '1',
                'stop_processing' => '1',
                'conditions' => [
                    ['field' => 'channel', 'operator' => 'equals', 'value' => 'email'],
                ],
                'actions' => [
                    ['type' => 'set_ticket_type', 'value' => (string) $defaults['type']->id],
                    ['type' => 'set_queue', 'value' => (string) $defaults['queue']->id],
                ],
            ])
            ->assertRedirect(route('tech.admin.settings.tickets.rules'));

        $this->assertDatabaseHas('ticket_rules', [
            'name' => 'Inbound email to support',
            'trigger' => 'on_create',
            'is_active' => true,
            'stop_processing' => true,
        ]);
    }

    #[Test]
    public function ticket_create_rules_can_set_type_queue_and_priority(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $leadType = TicketType::create([
            'name' => 'Lead Test',
            'slug' => 'lead-test',
            'is_active' => true,
            'is_deletable' => true,
            'sort_order' => 90,
        ]);
        $salesQueue = TicketQueue::create([
            'name' => 'Sales Test',
            'slug' => 'sales-test',
            'is_active' => true,
            'sort_order' => 90,
        ]);
        $high = TicketPriority::where('slug', 'high')->firstOrFail();

        TicketRule::create([
            'name' => 'No contract becomes lead',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => [
                ['field' => 'client_has_active_contract', 'operator' => 'equals', 'value' => '0'],
            ],
            'actions_json' => [
                ['type' => 'set_ticket_type', 'value' => (string) $leadType->id],
                ['type' => 'set_queue', 'value' => (string) $salesQueue->id],
                ['type' => 'set_priority', 'value' => (string) $high->id],
            ],
        ]);

        $ticket = app(\App\Modules\Ticket\Actions\StoreTicket::class)->handle([
            'subject' => 'Inbound sales question',
            'channel' => 'email',
            'client_has_active_contract' => '0',
        ], $this->tech);

        $this->assertSame($leadType->id, $ticket->ticket_type_id);
        $this->assertSame('lead-test', $ticket->type);
        $this->assertSame($salesQueue->id, $ticket->queue_id);
        $this->assertSame($high->id, $ticket->priority_id);
        $this->assertNotSame($defaults['queue']->id, $ticket->queue_id);
    }

    #[Test]
    public function ticket_types_referenced_by_ticket_rules_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');
        $type = TicketType::create([
            'name' => 'Rule Lead',
            'slug' => 'rule-lead',
            'is_active' => true,
            'is_deletable' => true,
        ]);

        TicketRule::create([
            'name' => 'Rule references type',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => 1,
            'is_active' => true,
            'conditions_json' => [
                ['field' => 'channel', 'operator' => 'equals', 'value' => 'email'],
            ],
            'actions_json' => [
                ['type' => 'set_ticket_type', 'value' => (string) $type->id],
            ],
        ]);

        $this->actingAs($admin)
            ->delete(route('tech.admin.settings.tickets.types.destroy', $type))
            ->assertSessionHasErrors('type');

        $this->assertDatabaseHas('ticket_types', ['id' => $type->id]);
    }

    private function emailAccountData(array $overrides = []): array
    {
        return array_merge([
            'address' => 'ticket@example.com',
            'description' => 'Ticket account',
            'from_name' => 'Support',
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'ticket@example.com',
            'imap_secret' => encrypt('secret'),
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => 'ticket@example.com',
            'smtp_secret' => encrypt('secret'),
            'smtp_auth_type' => 'password',
        ], $overrides);
    }

    private function createTicket(?ClientUser $contact = null, array $overrides = []): Ticket
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $contact?->loadMissing('site');

        return Ticket::create(array_merge([
            'ticket_key' => 'TD-2026-999100',
            'queue_id' => $defaults['queue']->id,
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'client_id' => $contact?->site?->client_id,
            'contact_id' => $contact?->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Ticket helper subject',
            'is_unread' => false,
        ], $overrides));
    }

    private function ticketReplyTemplateData(array $overrides = []): array
    {
        return array_merge([
            'scope' => 'tickets',
            'key' => 'ticket_reply',
            'name' => 'Ticket reply',
            'subject' => '[{{ ticket_key }}] {{ ticket_subject }}',
            'body_html' => '<p>{{ message_body }}</p>',
            'body_text' => '{{ message_body }}',
            'variables' => ['ticket_key', 'ticket_subject', 'contact_name', 'message_body', 'technician_name'],
            'is_default' => true,
            'is_active' => true,
        ], $overrides);
    }
}
