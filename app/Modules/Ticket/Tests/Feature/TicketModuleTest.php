<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Controllers\Admin\TicketSettingsController;
use App\Modules\Ticket\Controllers\Tech\TicketController;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertDatabaseHas('ticket_priorities', ['slug' => 'normal', 'is_default' => true]);
    }

    #[Test]
    public function tech_user_can_create_ticket_and_add_internal_note(): void
    {
        $this->actingAs($this->tech)
            ->get(route('tech.tickets.create'))
            ->assertOk()
            ->assertViewIs('ticket::Tech.Tickets.create');

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.store'), [
                'subject' => 'Printer cannot scan',
                'description' => 'Scan to mail fails with authentication error.',
                'channel' => 'manual',
            ])
            ->assertRedirect();

        $ticket = Ticket::firstOrFail();

        $this->assertStringStartsWith('TD-' . now()->format('Y') . '-', $ticket->ticket_key);
        $this->assertSame('Printer cannot scan', $ticket->subject);
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
}
