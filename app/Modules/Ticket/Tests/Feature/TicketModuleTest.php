<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Models\Knowledge\Article;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Storage\Models\Item as StorageItem;
use App\Modules\Storage\Models\Reservation as StorageReservation;
use App\Modules\Storage\Models\Warehouse as StorageWarehouse;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\AddTicketMessage;
use App\Modules\Ticket\Controllers\Admin\TicketSettingsController;
use App\Modules\Ticket\Controllers\Tech\TicketController;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketAssignmentSetting;
use App\Modules\Ticket\Models\TicketAssignmentRule;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketMergeSuggestionDismissal;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowTransition;
use App\Modules\Ticket\Jobs\SendTicketInternalNotificationEmail;
use App\Modules\Ticket\Jobs\SendTicketReplyEmail;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
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
        $this->assertSame(TicketController::class . '@storeTimeEntry', Route::getRoutes()->getByName('tech.tickets.time-entries.store')->getActionName());
        $this->assertSame(TicketController::class . '@draftTimeEntryInvoiceText', Route::getRoutes()->getByName('tech.tickets.time-entries.draft')->getActionName());
        $this->assertSame(TicketController::class . '@updateTimeEntry', Route::getRoutes()->getByName('tech.tickets.time-entries.update')->getActionName());
        $this->assertSame(TicketController::class . '@storeCostEntry', Route::getRoutes()->getByName('tech.tickets.cost-entries.store')->getActionName());
        $this->assertSame(TicketController::class . '@updateCostEntry', Route::getRoutes()->getByName('tech.tickets.cost-entries.update')->getActionName());
        $this->assertSame(TicketController::class . '@markMessageRead', Route::getRoutes()->getByName('tech.tickets.messages.read')->getActionName());
        $this->assertSame(TicketController::class . '@markMessageSolution', Route::getRoutes()->getByName('tech.tickets.messages.solution')->getActionName());
        $this->assertSame(TicketController::class . '@assign', Route::getRoutes()->getByName('tech.tickets.assign')->getActionName());
        $this->assertSame(TicketController::class . '@mergeSelected', Route::getRoutes()->getByName('tech.tickets.merge')->getActionName());
        $this->assertSame(TicketController::class . '@dismissMergeSuggestion', Route::getRoutes()->getByName('tech.tickets.merge-suggestions.dismiss')->getActionName());
        $this->assertSame(TicketController::class . '@markNotTicket', Route::getRoutes()->getByName('tech.tickets.not-ticket')->getActionName());
        $this->assertSame(TicketController::class . '@destroy', Route::getRoutes()->getByName('tech.tickets.destroy')->getActionName());
        $this->assertSame(TicketController::class . '@downloadAttachment', Route::getRoutes()->getByName('tech.tickets.attachments.download')->getActionName());
        $this->assertSame(TicketSettingsController::class . '@index', Route::getRoutes()->getByName('tech.admin.settings.tickets')->getActionName());
        $this->assertSame(TicketSettingsController::class . '@updateMergeSettings', Route::getRoutes()->getByName('tech.admin.settings.tickets.merge-settings.update')->getActionName());
        $this->assertSame(TicketSettingsController::class . '@storeStatus', Route::getRoutes()->getByName('tech.admin.settings.tickets.statuses.store')->getActionName());
        $this->assertSame(TicketSettingsController::class . '@storePriority', Route::getRoutes()->getByName('tech.admin.settings.tickets.priorities.store')->getActionName());
        $this->assertSame(\App\Modules\Ticket\Controllers\Tech\TechnicianProfileController::class . '@edit', Route::getRoutes()->getByName('tech.tickets.profile.edit')->getActionName());
        $this->assertSame(\App\Modules\Ticket\Controllers\Admin\TechnicianProfileAdminController::class . '@index', Route::getRoutes()->getByName('tech.admin.settings.tickets.technicians')->getActionName());
        $this->assertSame(\App\Modules\Ticket\Controllers\Admin\AssignmentRuleAdminController::class . '@index', Route::getRoutes()->getByName('tech.admin.settings.tickets.assignment-rules')->getActionName());
        $this->assertSame(TicketSettingsController::class . '@workflows', Route::getRoutes()->getByName('tech.admin.settings.tickets.workflows')->getActionName());
        $this->assertSame(TicketSettingsController::class . '@createWorkflow', Route::getRoutes()->getByName('tech.admin.settings.tickets.workflows.create')->getActionName());
        $this->assertSame(TicketSettingsController::class . '@storeWorkflow', Route::getRoutes()->getByName('tech.admin.settings.tickets.workflows.store')->getActionName());
        $this->assertSame(TicketSettingsController::class . '@editWorkflow', Route::getRoutes()->getByName('tech.admin.settings.tickets.workflows.edit')->getActionName());
        $this->assertSame(TicketSettingsController::class . '@updateWorkflow', Route::getRoutes()->getByName('tech.admin.settings.tickets.workflows.update')->getActionName());
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
            ->assertViewHas('statuses')
            ->assertSee('<h1 class="mb-0">Tickets</h1>', false)
            ->assertSee('bi bi-arrow-left', false)
            ->assertSee('Ticket Search')
            ->assertSee('ticket_index_search')
            ->assertSee('ticketIndexFiltersCollapse')
            ->assertSee('ticket_index_status_id')
            ->assertSee('New ticket');

        $this->assertDatabaseHas('ticket_queues', ['slug' => 'support', 'is_default' => true]);
        $this->assertDatabaseHas('ticket_statuses', ['slug' => 'new', 'is_default' => true]);
        $this->assertDatabaseHas('ticket_statuses', ['slug' => 'in-progress', 'is_closed' => false]);
        $this->assertDatabaseHas('ticket_statuses', ['slug' => 'waiting-customer', 'is_closed' => false]);
        $this->assertDatabaseHas('ticket_statuses', ['slug' => 'resolved', 'state' => 'resolved']);
        $this->assertDatabaseHas('ticket_statuses', ['slug' => 'closed', 'is_closed' => true]);
        $this->assertDatabaseHas('ticket_priorities', ['slug' => 'normal', 'is_default' => true]);
    }

    #[Test]
    public function ticket_index_shows_merge_suggestions_when_enabled(): void
    {
        CommonSetting::updateOrCreate(
            ['type' => 'ticket_merge', 'name' => 'ai_merge_enabled'],
            ['description' => 'Ticket merge automation setting.', 'value' => '1']
        );
        CommonSetting::updateOrCreate(
            ['type' => 'ticket_merge', 'name' => 'ai_similarity_threshold'],
            ['description' => 'Ticket merge automation setting.', 'value' => '80']
        );

        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'email' => 'merge-suggestion@example.com',
        ]);

        $target = $this->createTicket($contact, [
            'ticket_key' => 'TD-2026-999201',
            'subject' => 'Laptop battery fails after login',
            'description' => 'The laptop battery drains fast and the device shuts down shortly after login.',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $source = $this->createTicket($contact, [
            'ticket_key' => 'TD-2026-999202',
            'subject' => 'Laptop battery fails after login',
            'description' => 'The laptop battery drains fast and the device shuts down shortly after login.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.index'))
            ->assertOk()
            ->assertSee('Merge suggestions')
            ->assertSee($source->ticket_key)
            ->assertSee($target->ticket_key)
            ->assertSee('Merge suggestion')
            ->assertSee('100%');
    }

    #[Test]
    public function ticket_index_suggests_same_client_tickets_with_same_external_reference(): void
    {
        CommonSetting::updateOrCreate(
            ['type' => 'ticket_merge', 'name' => 'ai_merge_enabled'],
            ['description' => 'Ticket merge automation setting.', 'value' => '1']
        );
        CommonSetting::updateOrCreate(
            ['type' => 'ticket_merge', 'name' => 'ai_similarity_threshold'],
            ['description' => 'Ticket merge automation setting.', 'value' => '90']
        );

        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $firstContact = ClientUser::factory()->create(['client_site_id' => $site->id]);
        $secondContact = ClientUser::factory()->create(['client_site_id' => $site->id]);

        $target = $this->createTicket($firstContact, [
            'ticket_key' => 'TD-2026-999203',
            'subject' => 'MSP-558812 Backup warning from server',
            'description' => 'Backup job failed on the accounting server.',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $source = $this->createTicket($secondContact, [
            'ticket_key' => 'TD-2026-999204',
            'subject' => 'MSP-558812 Backup warning from server',
            'description' => 'Customer called about the same backup warning.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.index'))
            ->assertOk()
            ->assertSee('Merge suggestions')
            ->assertSee($source->ticket_key)
            ->assertSee($target->ticket_key)
            ->assertSee('100%');
    }

    #[Test]
    public function ticket_index_suggests_reply_subjects_after_internal_ticket_reference_is_removed(): void
    {
        CommonSetting::updateOrCreate(
            ['type' => 'ticket_merge', 'name' => 'ai_merge_enabled'],
            ['description' => 'Ticket merge automation setting.', 'value' => '1']
        );
        CommonSetting::updateOrCreate(
            ['type' => 'ticket_merge', 'name' => 'ai_similarity_threshold'],
            ['description' => 'Ticket merge automation setting.', 'value' => '90']
        );

        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create(['client_site_id' => $site->id]);

        $target = $this->createTicket($contact, [
            'ticket_key' => 'TD-2026-999205',
            'subject' => 'Hjelp med Windows',
            'description' => 'Original Windows support request.',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $source = $this->createTicket($contact, [
            'ticket_key' => 'TD-2026-999206',
            'subject' => 'Re: [TD-2026-000009] Hjelp med Windows',
            'description' => 'Reply created as a separate ticket.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.index'))
            ->assertOk()
            ->assertSee('Merge suggestions')
            ->assertSee($source->ticket_key)
            ->assertSee($target->ticket_key)
            ->assertSee('Subject matches after removing reply prefixes and internal ticket references.')
            ->assertSee('100%');
    }

    #[Test]
    public function tech_user_can_dismiss_merge_suggestion(): void
    {
        CommonSetting::updateOrCreate(
            ['type' => 'ticket_merge', 'name' => 'ai_merge_enabled'],
            ['description' => 'Ticket merge automation setting.', 'value' => '1']
        );
        CommonSetting::updateOrCreate(
            ['type' => 'ticket_merge', 'name' => 'ai_similarity_threshold'],
            ['description' => 'Ticket merge automation setting.', 'value' => '90']
        );

        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create(['client_site_id' => $site->id]);
        $first = $this->createTicket($contact, [
            'ticket_key' => 'TD-2026-999207',
            'subject' => 'Duplicate printer case',
        ]);
        $second = $this->createTicket($contact, [
            'ticket_key' => 'TD-2026-999208',
            'subject' => 'Duplicate printer case',
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.merge-suggestions.dismiss'), [
                'ticket_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Merge suggestion dismissed.');

        $this->assertDatabaseHas('ticket_merge_suggestion_dismissals', array_merge(
            TicketMergeSuggestionDismissal::pairIds($first, $second),
            ['dismissed_by' => $this->tech->id]
        ));

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.index'))
            ->assertOk()
            ->assertDontSee('Merge suggestions');
    }

    #[Test]
    public function ticket_index_groups_multiple_related_tickets_into_one_merge_suggestion(): void
    {
        CommonSetting::updateOrCreate(
            ['type' => 'ticket_merge', 'name' => 'ai_merge_enabled'],
            ['description' => 'Ticket merge automation setting.', 'value' => '1']
        );
        CommonSetting::updateOrCreate(
            ['type' => 'ticket_merge', 'name' => 'ai_similarity_threshold'],
            ['description' => 'Ticket merge automation setting.', 'value' => '80']
        );

        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create(['client_site_id' => $site->id]);

        $target = $this->createTicket($contact, [
            'ticket_key' => 'TD-2026-999209',
            'subject' => '[Info] [App Center] Notification from your device: NAS99D3C6',
            'description' => 'Notification from NAS99D3C6.',
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
        $firstSource = $this->createTicket($contact, [
            'ticket_key' => 'TD-2026-999210',
            'subject' => '[Info] [Power] Notification from your device: NAS99D3C6',
            'description' => 'Power notification from NAS99D3C6.',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $secondSource = $this->createTicket($contact, [
            'ticket_key' => 'TD-2026-999211',
            'subject' => '[Warning] [Storage] Notification from your device: NAS99D3C6',
            'description' => 'Storage warning from NAS99D3C6.',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.index'))
            ->assertOk()
            ->assertSee('Merge suggestions')
            ->assertSee($target->ticket_key)
            ->assertSee($firstSource->ticket_key)
            ->assertSee($secondSource->ticket_key)
            ->assertSee('3 tickets appear related.')
            ->assertSee('NAS99D3C6');
    }

    #[Test]
    public function ticket_index_can_sort_by_clickable_table_headers(): void
    {
        $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999041',
            'subject' => 'Zulu ticket subject',
            'owner_id' => $this->tech->id,
        ]);
        $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999040',
            'subject' => 'Alpha ticket subject',
            'owner_id' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.index', [
                'sort' => 'subject',
                'direction' => 'asc',
            ]))
            ->assertOk()
            ->assertSee('sort=ticket', false)
            ->assertSee('sort=subject', false)
            ->assertSee('sort=client', false)
            ->assertSee('sort=technician', false)
            ->assertSee('sort=queue', false)
            ->assertSee('sort=priority', false)
            ->assertSee('sort=sla', false)
            ->assertSee('sort=status', false)
            ->assertSee('sort=updated', false)
            ->assertSeeInOrder(['Alpha ticket subject', 'Zulu ticket subject']);
    }

    #[Test]
    public function ticket_index_can_filter_by_priority_category_unread_assignment_and_lifecycle(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $category = Category::create([
            'name' => 'Network',
            'slug' => 'network',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);
        $closed = TicketStatus::where('slug', 'closed')->firstOrFail();
        $high = TicketPriority::where('slug', 'high')->firstOrFail();

        $matching = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999010',
            'subject' => 'Filtered network issue',
            'category_id' => $category->id,
            'priority_id' => $high->id,
            'owner_id' => null,
            'is_unread' => true,
        ]);
        $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999011',
            'subject' => 'Closed normal issue',
            'status_id' => $closed->id,
            'priority_id' => $defaults['priority']->id,
            'category_id' => null,
            'owner_id' => $this->tech->id,
            'is_unread' => false,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.index', [
                'ownership' => 'all',
                'priority_id' => $high->id,
                'category_id' => $category->id,
                'lifecycle' => 'open',
                'unread' => '1',
                'unassigned' => '1',
            ]))
            ->assertOk()
            ->assertSee($matching->ticket_key)
            ->assertDontSee('Closed normal issue');
    }

    #[Test]
    public function ticket_index_defaults_to_open_mine_and_unassigned_tickets(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $otherTech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherTech->assignRole('Tech');
        $closed = TicketStatus::where('slug', 'closed')->firstOrFail();

        $mine = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999020',
            'subject' => 'Open mine default visible',
            'owner_id' => $this->tech->id,
        ]);
        $unassigned = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999021',
            'subject' => 'Open unassigned default visible',
            'owner_id' => null,
        ]);
        $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999022',
            'subject' => 'Open other owner hidden',
            'owner_id' => $otherTech->id,
        ]);
        $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999023',
            'subject' => 'Closed mine hidden',
            'status_id' => $closed->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.index'))
            ->assertOk()
            ->assertSee($mine->ticket_key)
            ->assertSee($unassigned->ticket_key)
            ->assertDontSee('Open other owner hidden')
            ->assertDontSee('Closed mine hidden');
    }

    #[Test]
    public function tech_user_can_mark_ticket_as_not_ticket_from_ticket_list(): void
    {
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999026',
            'subject' => 'Not ticket action target',
            'owner_id' => $this->tech->id,
        ]);
        $account = EmailAccount::create([
            'address' => 'support@example.test',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@example.test',
            'imap_secret' => 'secret',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'support@example.test',
            'smtp_secret' => 'secret',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 990026,
            'message_id' => '<not-ticket@example.test>',
            'subject' => 'Newsletter status',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'linked',
            'body_text' => 'This is not a support request.',
            'ticket_id' => $ticket->id,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.not-ticket', $ticket))
            ->assertRedirect(route('tech.tickets.index'))
            ->assertSessionHas('success', 'Ticket '.$ticket->ticket_key.' returned to Inbox. 1 email(s) tagged as not-ticket.');

        $email->refresh();

        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertNull($email->ticket_id);
        $this->assertSame('untriaged', $email->state);
        $this->assertTrue($email->tags()->where('tags.slug', 'not-ticket')->exists());
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'marked_not_ticket',
        ]);

        $rule = EmailRule::query()->where('name', 'like', 'Not ticket:%')->first();

        $this->assertNotNull($rule);
        $this->assertTrue($rule->is_active);
        $this->assertTrue($rule->stop_processing);
        $this->assertSame([
            ['field' => 'from', 'operator' => 'equals', 'value' => 'sender@example.test'],
            ['field' => 'subject', 'operator' => 'equals', 'value' => 'Newsletter status'],
        ], $rule->conditions_json);
        $this->assertSame([
            ['type' => 'tag', 'value' => 'not-ticket'],
        ], $rule->actions_json);
    }

    #[Test]
    public function tech_user_can_soft_delete_ticket_from_ticket_list(): void
    {
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999027',
            'subject' => 'Delete action target',
            'owner_id' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->delete(route('tech.tickets.destroy', $ticket))
            ->assertRedirect(route('tech.tickets.index'))
            ->assertSessionHas('success', 'Ticket '.$ticket->ticket_key.' deleted.');

        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'deleted',
        ]);
    }

    #[Test]
    public function ticket_index_exposes_bulk_merge_controls(): void
    {
        $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999028',
            'subject' => 'Merge candidate one',
            'owner_id' => $this->tech->id,
        ]);
        $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999029',
            'subject' => 'Merge candidate two',
            'owner_id' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.index'))
            ->assertOk()
            ->assertSee('ticket_bulk_select_all')
            ->assertSee('ticketBulkMergeButton')
            ->assertSee('Merge selected');
    }

    #[Test]
    public function tech_user_can_bulk_merge_selected_tickets_into_a_primary_ticket(): void
    {
        $target = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999030',
            'subject' => 'Primary outage ticket',
            'owner_id' => $this->tech->id,
        ]);
        $source = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999031',
            'subject' => 'Duplicate outage ticket',
            'owner_id' => $this->tech->id,
            'is_unread' => true,
        ]);
        $message = TicketMessage::create([
            'ticket_id' => $source->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'internal_note',
            'visibility' => 'internal',
            'body' => 'Duplicate ticket context.',
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.merge'), [
                'ticket_ids' => [$target->id, $source->id],
                'target_ticket_id' => $target->id,
                'reason' => 'Same customer issue.',
            ])
            ->assertRedirect(route('tech.tickets.show', $target))
            ->assertSessionHas('success', 'Merged 1 ticket(s) into '.$target->ticket_key.'.');

        $source->refresh();
        $target->refresh();

        $this->assertSoftDeleted('tickets', ['id' => $source->id]);
        $this->assertSame($target->id, $source->merged_into_ticket_id);
        $this->assertTrue($target->is_unread);
        $this->assertSame($target->id, $message->fresh()->ticket_id);
        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $target->id,
            'subject' => 'Ticket merged',
        ]);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $target->id,
            'type' => 'merged_ticket',
        ]);
    }

    #[Test]
    public function merged_ticket_show_redirects_to_target_ticket(): void
    {
        $target = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999037',
            'owner_id' => $this->tech->id,
        ]);
        $source = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999038',
            'owner_id' => $this->tech->id,
            'merged_into_ticket_id' => $target->id,
            'merged_by' => $this->tech->id,
            'merged_at' => now(),
        ]);
        $source->delete();

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $source))
            ->assertRedirect(route('tech.tickets.show', $target))
            ->assertSessionHas('warning');
    }

    #[Test]
    public function ticket_index_displays_sla_risk_badges_and_can_sort_by_sla(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-15 10:00:00'));
        $sla = $this->createSla('Index SLA', true, 8, 4, 1);

        $responseOverdue = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999030',
            'subject' => 'Response overdue ticket',
            'sla_id' => $sla->id,
            'first_response_due_at' => now()->subHour(),
            'resolve_due_at' => now()->addHours(5),
            'first_responded_at' => null,
            'updated_at' => now()->subMinutes(20),
        ]);

        $resolveOverdue = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999031',
            'subject' => 'Resolve overdue ticket',
            'sla_id' => $sla->id,
            'first_response_due_at' => now()->subHours(2),
            'resolve_due_at' => now()->subMinutes(30),
            'first_responded_at' => now()->subHour(),
            'resolved_at' => null,
            'updated_at' => now()->subMinutes(10),
        ]);

        $futureSla = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999032',
            'subject' => 'Future SLA ticket',
            'sla_id' => $sla->id,
            'first_response_due_at' => now()->addHours(2),
            'resolve_due_at' => now()->addHours(8),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.index', ['sort' => 'sla']))
            ->assertOk()
            ->assertSee('Response overdue')
            ->assertSee('Resolve overdue')
            ->assertSee('Index SLA')
            ->assertSeeInOrder([
                $responseOverdue->ticket_key,
                $resolveOverdue->ticket_key,
                $futureSla->ticket_key,
            ]);

        Carbon::setTestNow();
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
    public function tech_user_can_register_ticket_time_with_without_contract_rate(): void
    {
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999140',
            'owner_id' => $this->tech->id,
        ]);
        $rate = TimeRate::create([
            'name' => 'Time without contract',
            'slug' => 'time-without-contract-test',
            'code' => 'TIME_WITHOUT_CONTRACT_TEST',
            'rate_type' => 'labor',
            'unit' => 'hour',
            'amount_ex_vat' => 1200,
            'currency' => 'NOK',
            'applies_without_contract' => true,
            'applies_with_contract' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.time-entries.store', $ticket), [
                '_time_entry_form' => '1',
                'work_date' => '2026-05-19',
                'minutes' => 45,
                'rate_key' => 'global:' . $rate->id,
                'invoice_text' => 'Bluetooth troubleshooting and driver follow-up.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertDatabaseHas('ticket_time_entries', [
            'ticket_id' => $ticket->id,
            'user_id' => $this->tech->id,
            'minutes' => 45,
            'billing_basis' => 'without_contract',
            'billing_status' => 'pending',
            'timebank_status' => 'pending',
            'time_rate_id' => $rate->id,
            'rate_name' => 'Time without contract',
            'rate_amount_ex_vat' => 1200,
            'invoice_text' => 'Bluetooth troubleshooting and driver follow-up.',
        ]);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'time_entry_added',
        ]);
    }

    #[Test]
    public function tech_user_can_update_registered_ticket_time(): void
    {
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999143',
            'owner_id' => $this->tech->id,
        ]);
        $originalRate = TimeRate::create([
            'name' => 'Time without contract',
            'slug' => 'time-without-contract-update-test',
            'code' => 'TIME_WITHOUT_CONTRACT_UPDATE_TEST',
            'rate_type' => 'labor',
            'unit' => 'hour',
            'amount_ex_vat' => 1200,
            'currency' => 'NOK',
            'applies_without_contract' => true,
            'applies_with_contract' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $updatedRate = TimeRate::create([
            'name' => 'Driving',
            'slug' => 'driving-update-test',
            'code' => 'DRIVING_UPDATE_TEST',
            'rate_type' => 'driving',
            'unit' => 'hour',
            'amount_ex_vat' => 520,
            'currency' => 'NOK',
            'applies_without_contract' => true,
            'applies_with_contract' => true,
            'is_active' => true,
            'sort_order' => 20,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.time-entries.store', $ticket), [
                '_time_entry_form' => '1',
                'work_date' => '2026-05-19',
                'minutes' => 45,
                'rate_key' => 'global:' . $originalRate->id,
                'invoice_text' => 'Initial technical work.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $entry = $ticket->timeEntries()->firstOrFail();

        $this->actingAs($this->tech)
            ->patch(route('tech.tickets.time-entries.update', [$ticket, $entry]), [
                'work_date' => '2026-05-20',
                'minutes' => 30,
                'rate_key' => 'global:' . $updatedRate->id,
                'invoice_text' => 'Kjoring til kunde.',
                'note' => 'Adjusted from timer.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertDatabaseHas('ticket_time_entries', [
            'id' => $entry->id,
            'work_date' => '2026-05-20 00:00:00',
            'minutes' => 30,
            'time_rate_id' => $updatedRate->id,
            'rate_name' => 'Driving',
            'rate_amount_ex_vat' => 520,
            'invoice_text' => 'Kjoring til kunde.',
            'note' => 'Adjusted from timer.',
            'billing_status' => 'pending',
            'timebank_status' => 'pending',
        ]);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'time_entry_updated',
        ]);
    }

    #[Test]
    public function ticket_time_registration_can_use_accepted_contract_item_rates(): void
    {
        $client = Client::factory()->create(['name' => 'Contract Time Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create(['client_site_id' => $site->id]);
        $ticket = $this->createTicket($contact, [
            'ticket_key' => 'TD-2026-999141',
            'owner_id' => $this->tech->id,
        ]);
        $contract = Contracts::create([
            'client_id' => $client->id,
            'description' => 'Accepted test contract',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'approval_status' => 'won',
            'created_by' => $this->tech->id,
            'accepted_at' => now(),
        ]);
        $item = ContractItem::create([
            'contract_id' => $contract->id,
            'name' => 'Managed support',
            'unit_price' => 0,
            'quantity' => 1,
            'unit' => 'month',
            'billing_interval' => 'monthly',
        ]);
        $rate = $item->timeRates()->create([
            'name' => 'Contract support time',
            'code' => 'CONTRACT_SUPPORT_TIME',
            'rate_type' => 'labor',
            'unit' => 'hour',
            'amount_ex_vat' => 650,
            'currency' => 'NOK',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.time-entries.store', $ticket), [
                '_time_entry_form' => '1',
                'work_date' => '2026-05-19',
                'minutes' => 30,
                'rate_key' => 'contract:' . $rate->id,
                'invoice_text' => 'Remote support according to active agreement.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertDatabaseHas('ticket_time_entries', [
            'ticket_id' => $ticket->id,
            'contract_id' => $contract->id,
            'contract_item_id' => $item->id,
            'contract_item_time_rate_id' => $rate->id,
            'minutes' => 30,
            'billing_basis' => 'contract',
            'rate_name' => 'Contract support time',
            'rate_amount_ex_vat' => 650,
            'invoice_text' => 'Remote support according to active agreement.',
        ]);
    }

    #[Test]
    public function tech_user_can_reserve_storage_item_on_ticket_and_update_quantity(): void
    {
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999142',
            'owner_id' => $this->tech->id,
        ]);
        $warehouse = StorageWarehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
        ]);
        $item = StorageItem::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'USB-CABLE',
            'name' => 'USB Cable',
            'short_description' => 'USB cable for customer equipment.',
            'sale_price' => 149,
            'qty_on_hand' => 5,
            'qty_reserved' => 0,
            'status' => 'active',
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.cost-entries.store', $ticket), [
                'storage_item_id' => $item->id,
                'quantity' => 2,
                'note' => 'Reserved from main shelf.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $reservation = StorageReservation::firstOrFail();
        $this->assertSame(2, $item->refresh()->qty_reserved);
        $this->assertDatabaseHas('ticket_cost_entries', [
            'ticket_id' => $ticket->id,
            'storage_item_id' => $item->id,
            'storage_reservation_id' => $reservation->id,
            'quantity' => 2,
            'item_name' => 'USB Cable',
            'item_sku' => 'USB-CABLE',
            'status' => 'reserved',
            'billing_status' => 'pending',
            'invoice_text' => 'USB cable for customer equipment.',
        ]);

        $entry = $ticket->costEntries()->firstOrFail();

        $this->actingAs($this->tech)
            ->patch(route('tech.tickets.cost-entries.update', [$ticket, $entry]), [
                'quantity' => 3,
                'invoice_text' => 'USB cable and adapter for customer equipment.',
                'note' => 'Adjusted after finding adapter.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertSame(3, $item->refresh()->qty_reserved);
        $this->assertSame(3, $reservation->refresh()->qty);
        $this->assertDatabaseHas('ticket_cost_entries', [
            'id' => $entry->id,
            'quantity' => 3,
            'invoice_text' => 'USB cable and adapter for customer equipment.',
        ]);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'storage_reservation_updated',
        ]);
    }

    #[Test]
    public function ticket_show_displays_top_three_relevant_knowledge_articles(): void
    {
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999130',
            'subject' => 'RMM alert SMTP authentication failure',
            'description' => 'RMM reports mail relay auth rejected when sending scan to mail.',
        ]);

        foreach ([
            'SMTP authentication runbook',
            'RMM mail relay troubleshooting',
            'Scan to mail auth rejected',
            'SMTP queue monitoring',
        ] as $title) {
            Article::create([
                'title' => $title,
                'slug' => \Illuminate\Support\Str::slug($title),
                'body_markdown' => 'Steps for SMTP auth rejected errors from RMM and scan to mail.',
                'body_html' => '<p>Steps for SMTP auth rejected errors from RMM and scan to mail.</p>',
                'visibility' => 'internal',
                'status' => 'published',
                'owner_id' => $this->tech->id,
                'created_by' => $this->tech->id,
            ]);
        }

        Article::create([
            'title' => 'Draft SMTP article',
            'slug' => 'draft-smtp-article',
            'body_markdown' => 'SMTP authentication notes.',
            'body_html' => '<p>SMTP authentication notes.</p>',
            'visibility' => 'internal',
            'status' => 'draft',
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
        ]);

        Article::create([
            'title' => 'Printer toner replacement',
            'slug' => 'printer-toner-replacement',
            'body_markdown' => 'Replace toner cartridge.',
            'body_html' => '<p>Replace toner cartridge.</p>',
            'visibility' => 'internal',
            'status' => 'published',
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Knowledge')
            ->assertSee('SMTP authentication runbook')
            ->assertSee('RMM mail relay troubleshooting')
            ->assertSee('Scan to mail auth rejected')
            ->assertDontSee('SMTP queue monitoring')
            ->assertDontSee('Draft SMTP article')
            ->assertDontSee('Printer toner replacement')
            ->assertViewHas('knowledgeSuggestions', fn ($suggestions) => $suggestions->count() === 3);
    }

    #[Test]
    public function ticket_show_displays_open_task_widget_and_quick_view_modal(): void
    {
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999021',
        ]);
        $task = app(StoreTask::class)->handle([
            'title' => 'Follow up supplier',
            'checklist' => [
                ['title' => 'Call warehouse'],
            ],
        ], $this->tech, $ticket);
        TimeRate::create([
            'name' => 'Task modal support',
            'slug' => 'task-modal-support-test',
            'code' => 'TASK_MODAL_SUPPORT_TEST',
            'rate_type' => 'labor',
            'unit' => 'hour',
            'amount_ex_vat' => 950,
            'currency' => 'NOK',
            'applies_without_contract' => true,
            'applies_with_contract' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $item = $task->checklistItems()->firstOrFail();

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('ticketTasksCollapse', false)
            ->assertSee('class="accordion-collapse collapse show"', false)
            ->assertSee('id="ticketTaskQuickCreateModalAiAssist"', false)
            ->assertSee('id="ticketTaskQuickCreateModalChecklistText"', false)
            ->assertSee('id="ticketTaskQuickCreateModalEstimatedMinutes"', false)
            ->assertSee('id="ticketTaskQuickCreateModalTicketRateKey"', false)
            ->assertSee('data-bs-target="#ticketTaskQuickViewModal'.$task->id.'"', false)
            ->assertSee(route('tech.tasks.checklist.toggle', [$task, $item]), false)
            ->assertSee('data-task-checklist-toggle-form', false)
            ->assertSee(route('tech.tasks.assign', $task), false)
            ->assertSee('name="rate_key"', false)
            ->assertSee('Task modal support')
            ->assertSee('View in Task workspace');
    }

    #[Test]
    public function tech_user_can_upload_and_download_ticket_message_attachment(): void
    {
        Storage::fake('local');

        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999020',
            'owner_id' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.messages.store', $ticket), [
                'type' => 'internal_note',
                'visibility' => 'internal',
                'body' => 'Attached diagnostics.',
                'attachments' => [
                    UploadedFile::fake()->createWithContent('diagnostics.txt', 'log lines'),
                ],
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $attachment = TicketAttachment::firstOrFail();

        $this->assertSame($ticket->id, $attachment->ticket_id);
        $this->assertSame('diagnostics.txt', $attachment->filename);
        Storage::disk('local')->assertExists($attachment->path);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('diagnostics.txt');

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.attachments.download', [$ticket, $attachment]))
            ->assertOk();
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
        $generalCategory = Category::create([
            'name' => 'General Operations',
            'slug' => 'general-operations',
            'type' => null,
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
            ->assertSee('General Operations')
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

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.store'), [
                'subject' => 'General category ticket',
                'category_id' => $generalCategory->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tickets', [
            'subject' => 'General category ticket',
            'category_id' => $generalCategory->id,
        ]);
    }

    #[Test]
    public function ticket_forms_use_tag_chip_input_and_create_new_tags(): void
    {
        Tag::create([
            'name' => 'Existing Tag',
            'slug' => 'existing-tag',
            'active' => true,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.create'))
            ->assertOk()
            ->assertSee('data-ticket-tag-input', false)
            ->assertSee('list="ticketTagSuggestions"', false)
            ->assertSee('<option value="Existing Tag"></option>', false)
            ->assertDontSee('name="tag_ids[]"', false);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.store'), [
                'subject' => 'Tagged ticket',
                'tag_names' => ['Existing Tag', 'New Ticket Tag'],
            ])
            ->assertRedirect();

        $ticket = Ticket::query()->where('subject', 'Tagged ticket')->firstOrFail();

        $this->assertSame(['Existing Tag', 'New Ticket Tag'], $ticket->tags()->orderBy('name')->pluck('name')->all());
        $this->assertDatabaseHas('tags', [
            'name' => 'New Ticket Tag',
            'slug' => 'new-ticket-tag',
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.edit', $ticket))
            ->assertOk()
            ->assertSee('data-ticket-tag-input', false)
            ->assertSee('Existing Tag')
            ->assertSee('New Ticket Tag');
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
        $ticket->refresh();
        $this->assertFalse($ticket->is_unread);
        $this->assertNotNull($ticket->first_responded_at);
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
            ->assertSee('ticketReplyShortcut')
            ->assertSee('Reply')
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
    public function tech_user_can_mark_single_customer_reply_as_read(): void
    {
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999115',
            'is_unread' => true,
        ]);
        $firstMessage = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'author_type' => 'contact',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'First unread reply.',
            'read_at' => null,
        ]);
        $secondMessage = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'author_type' => 'contact',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Second unread reply.',
            'read_at' => null,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee(route('tech.tickets.messages.read', [$ticket, $firstMessage]), false)
            ->assertSee(route('tech.tickets.messages.read', [$ticket, $secondMessage]), false);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.messages.read', [$ticket, $firstMessage]))
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertNotNull($firstMessage->fresh()->read_at);
        $this->assertNull($secondMessage->fresh()->read_at);
        $this->assertTrue($ticket->fresh()->is_unread);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.messages.read', [$ticket, $secondMessage]))
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertFalse($ticket->fresh()->is_unread);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'actor_id' => $this->tech->id,
            'type' => 'message_marked_read',
            'message' => 'Ticket reply marked as read.',
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
        $solution = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Use this answer to fix the issue.',
            'metadata' => ['is_solution' => true],
        ]);

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
        app(EnsureTicketDefaults::class)->handle();
        $resolved = TicketStatus::where('slug', 'resolved')->firstOrFail();
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999111',
            'status_id' => $resolved->id,
            'resolved_at' => now(),
        ]);

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
    public function admin_can_open_ticket_workflows_and_default_workflow_is_created(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.tickets.workflows'))
            ->assertOk()
            ->assertViewIs('ticket::Admin.Settings.workflows.index')
            ->assertSee('Default Ticket Workflow');

        $workflow = TicketWorkflow::where('is_default', true)->firstOrFail();

        $this->assertGreaterThan(0, $workflow->states()->count());
        $this->assertGreaterThan(0, $workflow->transitions()->count());
    }

    #[Test]
    public function ticket_show_displays_available_workflow_transitions(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999035',
            'status_id' => TicketStatus::where('slug', 'new')->firstOrFail()->id,
            'workflow_id' => TicketWorkflow::where('is_default', true)->firstOrFail()->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Workflow')
            ->assertSee('Start work');
    }

    #[Test]
    public function workflow_transition_route_changes_ticket_status_when_allowed(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $new = TicketStatus::where('slug', 'new')->firstOrFail();
        $inProgress = TicketStatus::where('slug', 'in-progress')->firstOrFail();
        $workflow = TicketWorkflow::where('is_default', true)->firstOrFail();
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999036',
            'status_id' => $new->id,
            'workflow_id' => $workflow->id,
        ]);
        $transition = TicketWorkflowTransition::where('ticket_workflow_id', $workflow->id)
            ->where('from_status_id', $new->id)
            ->where('to_status_id', $inProgress->id)
            ->firstOrFail();

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.workflow.transition', [$ticket, $transition]))
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertSame($inProgress->id, $ticket->fresh()->status_id);
        $this->assertSame(1, $ticket->events()->where('type', 'status_changed')->count());
    }

    #[Test]
    public function workflow_blocks_status_changes_without_transition(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $new = TicketStatus::where('slug', 'new')->firstOrFail();
        $waiting = TicketStatus::where('slug', 'waiting-customer')->firstOrFail();
        $workflow = TicketWorkflow::where('is_default', true)->firstOrFail();
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999037',
            'status_id' => $new->id,
            'workflow_id' => $workflow->id,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(\App\Modules\Ticket\Actions\ChangeTicketStatus::class)->handle($ticket, $waiting, $this->tech);
    }

    #[Test]
    public function workflow_blocks_solved_transition_until_response_is_marked_as_solution(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $new = TicketStatus::where('slug', 'new')->firstOrFail();
        $resolved = TicketStatus::where('slug', 'resolved')->firstOrFail();
        $workflow = TicketWorkflow::where('is_default', true)->firstOrFail();
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999038',
            'status_id' => $new->id,
            'workflow_id' => $workflow->id,
        ]);
        $transition = TicketWorkflowTransition::where('ticket_workflow_id', $workflow->id)
            ->where('from_status_id', $new->id)
            ->where('to_status_id', $resolved->id)
            ->firstOrFail();

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.workflow.transition', [$ticket, $transition]))
            ->assertSessionHasErrors('status_id');

        $response = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'This fixes the issue.',
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.workflow.transition', [$ticket, $transition]))
            ->assertSessionHasErrors('status_id');

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.messages.solution', [$ticket, $response]))
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertSame($resolved->id, $ticket->fresh()->status_id);
        $this->assertTrue((bool) $response->fresh()->metadata['is_solution']);
    }

    #[Test]
    public function workflow_auto_advance_after_solution_does_not_close_ticket(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $new = TicketStatus::where('slug', 'new')->firstOrFail();
        $resolved = TicketStatus::where('slug', 'resolved')->firstOrFail();
        $closed = TicketStatus::where('slug', 'closed')->firstOrFail();
        $workflow = TicketWorkflow::where('is_default', true)->firstOrFail();
        TicketWorkflowTransition::where('ticket_workflow_id', $workflow->id)
            ->where('from_status_id', $resolved->id)
            ->where('to_status_id', $closed->id)
            ->firstOrFail()
            ->forceFill(['requires_resolution' => true])
            ->save();
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999044',
            'status_id' => $new->id,
            'workflow_id' => $workflow->id,
        ]);
        $response = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'This fixes the issue.',
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.messages.solution', [$ticket, $response]))
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $ticket->refresh();

        $this->assertSame($resolved->id, $ticket->status_id);
        $this->assertNull($ticket->closed_at);
    }

    #[Test]
    public function workflow_does_not_allow_closing_before_solved_state(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $new = TicketStatus::where('slug', 'new')->firstOrFail();
        $closed = TicketStatus::where('slug', 'closed')->firstOrFail();
        $workflow = TicketWorkflow::where('is_default', true)->firstOrFail();
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999039',
            'status_id' => $new->id,
            'workflow_id' => $workflow->id,
        ]);

        $this->assertFalse(TicketWorkflowTransition::where('ticket_workflow_id', $workflow->id)
            ->where('from_status_id', $new->id)
            ->where('to_status_id', $closed->id)
            ->where('is_active', true)
            ->exists());

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.close', $ticket))
            ->assertSessionHasErrors('status_id');

        $this->assertSame($new->id, $ticket->fresh()->status_id);
    }

    #[Test]
    public function workflow_can_disable_manual_transition_and_allow_action_trigger(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $new = TicketStatus::where('slug', 'new')->firstOrFail();
        $inProgress = TicketStatus::where('slug', 'in-progress')->firstOrFail();
        $workflow = TicketWorkflow::where('is_default', true)->firstOrFail();
        $transition = TicketWorkflowTransition::where('ticket_workflow_id', $workflow->id)
            ->where('from_status_id', $new->id)
            ->where('to_status_id', $inProgress->id)
            ->firstOrFail();
        $transition->forceFill([
            'manual_enabled' => false,
            'trigger_actions' => [TicketAction::ADD_INTERNAL_NOTE],
        ])->save();
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999040',
            'status_id' => $new->id,
            'workflow_id' => $workflow->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertDontSee('Start work');

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.workflow.transition', [$ticket, $transition]))
            ->assertSessionHasErrors('status_id');

        app(AddTicketMessage::class)->handle($ticket->fresh(), [
            'type' => 'internal_note',
            'visibility' => 'internal',
            'body' => 'Started triage.',
        ], $this->tech);

        $this->assertSame($inProgress->id, $ticket->fresh()->status_id);
    }

    #[Test]
    public function customer_reply_intent_controls_workflow_action_trigger(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $inProgress = TicketStatus::where('slug', 'in-progress')->firstOrFail();
        $waiting = TicketStatus::where('slug', 'waiting-customer')->firstOrFail();
        $workflow = TicketWorkflow::where('is_default', true)->firstOrFail();
        TicketWorkflowTransition::where('ticket_workflow_id', $workflow->id)
            ->where('from_status_id', $inProgress->id)
            ->where('to_status_id', $waiting->id)
            ->firstOrFail()
            ->forceFill(['trigger_actions' => [TicketAction::REQUEST_CUSTOMER_INPUT]])
            ->save();
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999041',
            'status_id' => $inProgress->id,
            'workflow_id' => $workflow->id,
        ]);

        app(AddTicketMessage::class)->handle($ticket->fresh(), [
            'type' => 'customer_reply',
            'visibility' => 'public',
            'reply_intent' => TicketAction::CUSTOMER_UPDATE,
            'body' => 'We are still working on this.',
        ], $this->tech);

        $this->assertSame($inProgress->id, $ticket->fresh()->status_id);

        app(AddTicketMessage::class)->handle($ticket->fresh(), [
            'type' => 'customer_reply',
            'visibility' => 'public',
            'reply_intent' => TicketAction::REQUEST_CUSTOMER_INPUT,
            'body' => 'Can you send the screenshot?',
        ], $this->tech);

        $this->assertSame($waiting->id, $ticket->fresh()->status_id);
    }

    #[Test]
    public function admin_can_create_ticket_workflow_with_states_and_transitions(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');
        app(EnsureTicketDefaults::class)->handle();
        $new = TicketStatus::where('slug', 'new')->firstOrFail();
        $progress = TicketStatus::where('slug', 'in-progress')->firstOrFail();
        $closed = TicketStatus::where('slug', 'closed')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.tickets.workflows.create'))
            ->assertOk()
            ->assertViewIs('ticket::Admin.Settings.workflows.form')
            ->assertSee('Create Ticket Workflow');

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.tickets.workflows.store'), [
                'name' => 'Escalation Workflow',
                'slug' => 'escalation-workflow',
                'description' => 'Workflow for escalated tickets.',
                'is_active' => '1',
                'is_default' => '0',
                'sort_order' => 20,
                'states' => [
                    $new->id => ['enabled' => '1', 'name' => 'New', 'is_initial' => '1', 'sort_order' => 10],
                    $progress->id => ['enabled' => '1', 'name' => 'Working', 'sort_order' => 20],
                    $closed->id => ['enabled' => '1', 'name' => 'Closed', 'is_terminal' => '1', 'sort_order' => 30],
                ],
                'transitions' => [
                    ['enabled' => '1', 'from_status_id' => $new->id, 'to_status_id' => $progress->id, 'label' => 'Start escalation', 'sort_order' => 10],
                    ['enabled' => '1', 'from_status_id' => $progress->id, 'to_status_id' => $closed->id, 'label' => 'Close escalation', 'requires_resolution' => '1', 'sort_order' => 20],
                ],
            ])
            ->assertRedirect();

        $workflow = TicketWorkflow::where('slug', 'escalation-workflow')->firstOrFail();

        $this->assertSame(3, $workflow->states()->count());
        $this->assertSame(2, $workflow->transitions()->count());
        $this->assertDatabaseHas('ticket_workflow_transitions', [
            'ticket_workflow_id' => $workflow->id,
            'from_status_id' => $progress->id,
            'to_status_id' => $closed->id,
            'requires_resolution' => true,
        ]);
    }

    #[Test]
    public function admin_can_edit_ticket_workflow_and_replace_transitions(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');
        app(EnsureTicketDefaults::class)->handle();
        $new = TicketStatus::where('slug', 'new')->firstOrFail();
        $progress = TicketStatus::where('slug', 'in-progress')->firstOrFail();
        $waiting = TicketStatus::where('slug', 'waiting-customer')->firstOrFail();
        $workflow = TicketWorkflow::where('is_default', true)->firstOrFail();

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.tickets.workflows.edit', $workflow))
            ->assertOk()
            ->assertViewIs('ticket::Admin.Settings.workflows.form')
            ->assertSee('Edit Ticket Workflow');

        $this->actingAs($admin)
            ->put(route('tech.admin.settings.tickets.workflows.update', $workflow), [
                'name' => 'Updated Default Workflow',
                'slug' => $workflow->slug,
                'description' => 'Updated workflow text.',
                'is_active' => '1',
                'is_default' => '1',
                'sort_order' => 10,
                'states' => [
                    $new->id => ['enabled' => '1', 'name' => 'New', 'is_initial' => '1', 'sort_order' => 10],
                    $progress->id => ['enabled' => '1', 'name' => 'Working', 'sort_order' => 20],
                    $waiting->id => ['enabled' => '1', 'name' => 'Waiting', 'sort_order' => 30],
                ],
                'transitions' => [
                    ['enabled' => '1', 'from_status_id' => $new->id, 'to_status_id' => $progress->id, 'label' => 'Start work', 'sort_order' => 10],
                    ['enabled' => '1', 'from_status_id' => $progress->id, 'to_status_id' => $waiting->id, 'label' => 'Wait', 'requires_note' => '1', 'sort_order' => 20],
                ],
            ])
            ->assertRedirect(route('tech.admin.settings.tickets.workflows.edit', $workflow));

        $workflow->refresh();

        $this->assertSame('Updated Default Workflow', $workflow->name);
        $this->assertSame(3, $workflow->states()->count());
        $this->assertSame(2, $workflow->transitions()->count());
        $this->assertDatabaseHas('ticket_workflow_transitions', [
            'ticket_workflow_id' => $workflow->id,
            'from_status_id' => $progress->id,
            'to_status_id' => $waiting->id,
            'requires_note' => true,
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
            ->assertSee('Technician reply')
            ->assertSee($this->tech->name)
            ->assertSee('Visible reply.')
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
                ->withArgs(fn ($resolvedAccount, $toEmail, $toName, $subject, $html, $text, $attachments = []) =>
                    $resolvedAccount->is($account)
                    && $toEmail === 'ada@example.com'
                    && $toName === 'Ada Contact'
                    && $subject === '[TD-2026-999004] SMTP job ticket'
                    && str_contains($html, '<p>Rendered reply.</p>')
                    && str_contains($html, '--- Please reply above this line ---')
                    && str_contains($text, 'Rendered reply.')
                    && str_contains($text, '--- Please reply above this line ---')
                    && $attachments === []
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
    public function send_ticket_reply_email_job_sends_ticket_message_attachments(): void
    {
        Storage::fake('local');

        $client = Client::factory()->create(['name' => 'Attachment Mail Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Attachment Contact',
            'email' => 'attach@example.com',
        ]);
        $account = EmailAccount::create($this->emailAccountData([
            'address' => 'support-attachments@example.com',
            'defaults_for' => ['tickets'],
        ]));
        EmailTemplate::create($this->ticketReplyTemplateData());

        $ticket = $this->createTicket($contact, [
            'ticket_key' => 'TD-2026-999024',
            'client_id' => $client->id,
            'contact_id' => $contact->id,
            'subject' => 'Attachment reply ticket',
        ]);
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'See attachment.',
        ]);
        Storage::disk('local')->put('ticket/attachments/test/report.txt', 'report body');
        TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_message_id' => $message->id,
            'uploaded_by' => $this->tech->id,
            'source' => 'upload',
            'filename' => 'report.txt',
            'original_filename' => 'report.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 11,
            'disk' => 'local',
            'path' => 'ticket/attachments/test/report.txt',
            'checksum_sha1' => sha1('report body'),
        ]);

        $this->mock(SmtpAccountMailer::class, function ($mock) use ($account) {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(fn ($resolvedAccount, $toEmail, $toName, $subject, $html, $text, $attachments) =>
                    $resolvedAccount->is($account)
                    && $toEmail === 'attach@example.com'
                    && $toName === 'Attachment Contact'
                    && $subject === '[TD-2026-999024] Attachment reply ticket'
                    && count($attachments) === 1
                    && $attachments[0]['filename'] === 'report.txt'
                    && $attachments[0]['content_type'] === 'text/plain'
                    && is_file($attachments[0]['path'])
                )
                ->andReturn('<attachment-message@example.com>');
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
            'rfc_message_id' => '<attachment-message@example.com>',
        ]);
    }

    #[Test]
    public function send_ticket_reply_email_job_uses_selected_reply_contact_and_cc(): void
    {
        $client = Client::factory()->create(['name' => 'Reply Routing Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $ticketContact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Wrong Contact',
            'email' => 'wrong@example.com',
        ]);
        $replyContact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Correct Contact',
            'email' => 'correct@example.com',
        ]);
        $account = EmailAccount::create($this->emailAccountData([
            'address' => 'support-routing@example.com',
            'defaults_for' => ['tickets'],
        ]));
        EmailTemplate::create($this->ticketReplyTemplateData());
        $ticket = $this->createTicket($ticketContact, [
            'ticket_key' => 'TD-2026-999042',
            'client_id' => $client->id,
            'contact_id' => $ticketContact->id,
            'subject' => 'Reply routing ticket',
        ]);
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Routed reply.',
            'metadata' => [
                'reply_contact_id' => $replyContact->id,
                'cc' => ['thirdparty@example.com'],
            ],
        ]);

        $this->mock(SmtpAccountMailer::class, function ($mock) use ($account) {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(fn ($resolvedAccount, $toEmail, $toName, $subject, $html, $text, $attachments, $ccRecipients) =>
                    $resolvedAccount->is($account)
                    && $toEmail === 'correct@example.com'
                    && $toName === 'Correct Contact'
                    && $ccRecipients === [['email' => 'thirdparty@example.com', 'name' => '']]
                )
                ->andReturn('<routed-message@example.com>');
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
            'rfc_message_id' => '<routed-message@example.com>',
        ]);
    }

    #[Test]
    public function internal_note_can_notify_selected_technician_by_email(): void
    {
        $recipient = User::factory()->create([
            'name' => 'Notify Tech',
            'email' => 'notify-tech@example.com',
            'status' => User::STATUS_ACTIVE,
        ]);
        $account = EmailAccount::create($this->emailAccountData([
            'address' => 'support-internal@example.com',
            'defaults_for' => ['tickets'],
        ]));
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999043',
            'subject' => 'Internal notify ticket',
        ]);
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'internal_note',
            'visibility' => 'internal',
            'body' => 'Please review this note.',
            'metadata' => ['notify_user_id' => $recipient->id],
        ]);

        $this->mock(SmtpAccountMailer::class, function ($mock) use ($account) {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(fn ($resolvedAccount, $toEmail, $toName, $subject, $html, $text) =>
                    $resolvedAccount->is($account)
                    && $toEmail === 'notify-tech@example.com'
                    && $toName === 'Notify Tech'
                    && $subject === '[TD-2026-999043] Internal note notification'
                    && str_contains($text, 'Please review this note.')
                )
                ->andReturn('<internal-notify@example.com>');
        });

        app(SendTicketInternalNotificationEmail::class, ['ticketMessageId' => $message->id])->handle(
            app(\App\Modules\Email\Services\DefaultEmailAccountResolver::class),
            app(SmtpAccountMailer::class)
        );

        $this->assertDatabaseHas('email_logs', [
            'direction' => 'outbound',
            'account_id' => $account->id,
            'scope' => 'tickets',
            'level' => 'info',
            'code' => 'TICKET_INTERNAL_NOTIFY_SENT',
            'rfc_message_id' => '<internal-notify@example.com>',
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
            ->assertViewIs('ticket::Admin.Settings.index')
            ->assertSee('Ticket Merging')
            ->assertSee('Automatically merge exact duplicate inbound tickets')
            ->assertSee('Allow AI-assisted merge suggestions')
            ->assertSee('AI similarity threshold');
    }

    #[Test]
    public function admin_can_update_ticket_merge_settings(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.tickets.merge-settings.update'), [
                'auto_merge_enabled' => '1',
                'ai_merge_enabled' => '1',
                'ai_similarity_threshold' => '90',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Ticket merge settings updated.');

        $this->assertSame('1', CommonSetting::where('type', 'ticket_merge')->where('name', 'auto_merge_enabled')->value('value'));
        $this->assertSame('1', CommonSetting::where('type', 'ticket_merge')->where('name', 'ai_merge_enabled')->value('value'));
        $this->assertSame('90', CommonSetting::where('type', 'ticket_merge')->where('name', 'ai_similarity_threshold')->value('value'));
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
    public function technician_can_manage_own_ticket_profile_skills_and_hours(): void
    {
        $category = Category::create([
            'name' => 'Networking',
            'slug' => 'networking',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);
        $tag = Tag::create([
            'name' => 'Fortigate',
            'slug' => 'fortigate',
            'active' => true,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.profile.edit'))
            ->assertOk()
            ->assertViewIs('ticket::Tech.TechnicianProfile.edit')
            ->assertSee('Ticket Assignment Settings');

        $this->actingAs($this->tech)
            ->patch(route('tech.tickets.profile.update'), [
                'is_assignable' => '1',
                'max_open_tickets' => 7,
                'category_ids' => [$category->id],
                'tag_ids' => [$tag->id],
                'notes' => 'Prefers firewall tickets.',
            ])
            ->assertRedirect();

        $profile = TicketAssignmentSetting::where('user_id', $this->tech->id)->firstOrFail();

        $this->assertTrue($profile->is_assignable);
        $this->assertSame(7, $profile->max_open_tickets);
        $this->assertTrue($profile->categories()->whereKey($category->id)->exists());
        $this->assertTrue($profile->tags()->whereKey($tag->id)->exists());
    }

    #[Test]
    public function admin_can_create_and_update_ticket_technician_profile(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');
        $technician = User::factory()->create([
            'name' => 'Assignment Tech',
            'status' => User::STATUS_ACTIVE,
        ]);
        $category = Category::create([
            'name' => 'Email Support',
            'slug' => 'email-support',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.tickets.technicians'))
            ->assertOk()
            ->assertViewIs('ticket::Admin.TechnicianProfiles.index')
            ->assertSee('Assignment Tech');

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.tickets.technicians.store'), [
                'user_id' => $technician->id,
            ])
            ->assertRedirect();

        $profile = TicketAssignmentSetting::where('user_id', $technician->id)->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('tech.admin.settings.tickets.technicians.update', $profile), [
                'is_assignable' => '0',
                'max_open_tickets' => 4,
                'category_ids' => [$category->id],
            ])
            ->assertRedirect(route('tech.admin.settings.tickets.technicians'));

        $profile->refresh();

        $this->assertFalse($profile->is_assignable);
        $this->assertSame(4, $profile->max_open_tickets);
        $this->assertTrue($profile->categories()->whereKey($category->id)->exists());
    }

    #[Test]
    public function assignment_rule_can_assign_new_ticket_to_specific_technician(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');
        $assignee = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $client = Client::factory()->create();

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.tickets.assignment-rules.store'), [
                'name' => 'Client owner',
                'weight' => 5,
                'is_active' => '1',
                'stop_processing' => '1',
                'conditions' => [
                    ['field' => 'client_id', 'operator' => 'equals', 'value' => (string) $client->id],
                    ['field' => 'queue_id', 'operator' => 'equals', 'value' => ''],
                ],
                'action_value' => $assignee->id,
            ])
            ->assertRedirect();

        $ticket = app(\App\Modules\Ticket\Actions\StoreTicket::class)->handle([
            'subject' => 'Assignment rule subject',
            'client_id' => $client->id,
            'channel' => 'email',
        ]);

        $this->assertSame($assignee->id, $ticket->owner_id);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'assigned',
            'message' => 'Ticket assigned by assignment rule: Client owner',
        ]);
        $this->assertSame(1, TicketAssignmentRule::firstOrFail()->hit_count);
    }

    #[Test]
    public function assignment_engine_falls_back_to_profile_category_skill_and_capacity(): void
    {
        $category = Category::create([
            'name' => 'Firewall',
            'slug' => 'firewall',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);
        $skilled = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $generalist = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::create([
            'user_id' => $skilled->id,
            'timezone' => config('app.timezone'),
            'working_hours' => [
                strtolower(now()->format('l')) => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
            ],
        ]);
        UserProfile::create([
            'user_id' => $generalist->id,
            'timezone' => config('app.timezone'),
            'working_hours' => [
                strtolower(now()->format('l')) => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
            ],
        ]);
        $skilledProfile = TicketAssignmentSetting::create([
            'user_id' => $skilled->id,
            'is_assignable' => true,
            'max_open_tickets' => 10,
        ]);
        TicketAssignmentSetting::create([
            'user_id' => $generalist->id,
            'is_assignable' => true,
            'max_open_tickets' => 10,
        ]);
        $skilledProfile->categories()->attach($category->id);

        $ticket = app(\App\Modules\Ticket\Actions\StoreTicket::class)->handle([
            'subject' => 'Firewall assignment subject',
            'category_id' => $category->id,
            'channel' => 'email',
        ]);

        $this->assertSame($skilled->id, $ticket->owner_id);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'assigned',
            'message' => 'Ticket assigned by technician profile scoring.',
        ]);
    }

    #[Test]
    public function assignment_engine_scores_matching_ticket_tag_skills(): void
    {
        $tag = Tag::create([
            'name' => 'Fortigate',
            'slug' => 'fortigate',
            'active' => true,
        ]);
        $skilled = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $generalist = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::create([
            'user_id' => $skilled->id,
            'timezone' => config('app.timezone'),
            'working_hours' => [
                strtolower(now()->format('l')) => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
            ],
        ]);
        UserProfile::create([
            'user_id' => $generalist->id,
            'timezone' => config('app.timezone'),
            'working_hours' => [
                strtolower(now()->format('l')) => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
            ],
        ]);
        $skilledProfile = TicketAssignmentSetting::create([
            'user_id' => $skilled->id,
            'is_assignable' => true,
            'max_open_tickets' => 10,
        ]);
        TicketAssignmentSetting::create([
            'user_id' => $generalist->id,
            'is_assignable' => true,
            'max_open_tickets' => 10,
        ]);
        $skilledProfile->tags()->attach($tag->id);

        $ticket = app(\App\Modules\Ticket\Actions\StoreTicket::class)->handle([
            'subject' => 'Tagged assignment subject',
            'channel' => 'email',
            'tag_ids' => [$tag->id],
        ]);

        $this->assertSame($skilled->id, $ticket->owner_id);
    }

    #[Test]
    public function tech_can_rerun_assignment_from_ticket_show(): void
    {
        $assignee = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $client = Client::factory()->create();
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999114',
            'client_id' => $client->id,
            'owner_id' => $this->tech->id,
        ]);

        TicketAssignmentRule::create([
            'name' => 'Rerun client owner',
            'weight' => 1,
            'is_active' => true,
            'conditions_json' => [
                ['field' => 'client_id', 'operator' => 'equals', 'value' => (string) $client->id],
            ],
            'action_type' => 'assign_user',
            'action_value' => (string) $assignee->id,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.assign', $ticket))
            ->assertRedirect(route('tech.tickets.show', $ticket->refresh()));

        $this->assertSame($assignee->id, $ticket->fresh()->owner_id);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket->fresh()))
            ->assertOk()
            ->assertSee('Run assignment')
            ->assertSee('Ticket assigned by assignment rule: Rerun client owner');
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

        $billingQueue = TicketQueue::create([
            'name' => 'Billing',
            'slug' => 'billing',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 30,
        ]);

        $this->actingAs($admin)
            ->put(route('tech.admin.settings.tickets.queues.update', $billingQueue), [
                'name' => $billingQueue->name,
                'slug' => $billingQueue->slug,
                'description' => $billingQueue->description,
                'email_address' => $billingQueue->email_address,
                'is_active' => '1',
                'is_default' => '0',
                'sort_order' => $billingQueue->sort_order,
            ])
            ->assertRedirect();

        $this->assertTrue($queue->fresh()->is_default);

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.tickets.statuses.store'), [
                'name' => 'Pending Vendor',
                'slug' => 'pending-vendor',
                'state' => 'waiting',
                'is_active' => '1',
                'is_closed' => '0',
                'is_default' => '1',
                'sort_order' => 60,
            ])
            ->assertRedirect();

        $status = TicketStatus::where('slug', 'pending-vendor')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('tech.admin.settings.tickets.statuses.update', $status), [
                'name' => 'Waiting Vendor',
                'slug' => 'waiting-vendor',
                'state' => 'waiting',
                'is_active' => '1',
                'is_closed' => '0',
                'is_default' => '1',
                'sort_order' => 65,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ticket_statuses', [
            'id' => $status->id,
            'name' => 'Waiting Vendor',
            'slug' => 'waiting-vendor',
            'is_default' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.tickets.priorities.store'), [
                'name' => 'Escalated',
                'slug' => 'escalated',
                'level' => 2,
                'is_active' => '1',
                'is_default' => '1',
                'sort_order' => 15,
            ])
            ->assertRedirect();

        $priority = TicketPriority::where('slug', 'escalated')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('tech.admin.settings.tickets.priorities.update', $priority), [
                'name' => 'Urgent',
                'slug' => 'urgent-custom',
                'level' => 1,
                'is_active' => '1',
                'is_default' => '1',
                'sort_order' => 12,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ticket_priorities', [
            'id' => $priority->id,
            'name' => 'Urgent',
            'slug' => 'urgent-custom',
            'level' => 1,
            'is_default' => true,
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
    public function used_statuses_and_priorities_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Tech');
        $admin->assignRole('Admin');
        $defaults = app(EnsureTicketDefaults::class)->handle();

        $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999113',
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('tech.admin.settings.tickets.statuses.destroy', $defaults['status']))
            ->assertSessionHasErrors('status');

        $this->actingAs($admin)
            ->delete(route('tech.admin.settings.tickets.priorities.destroy', $defaults['priority']))
            ->assertSessionHasErrors('priority');

        $this->assertDatabaseHas('ticket_statuses', ['id' => $defaults['status']->id]);
        $this->assertDatabaseHas('ticket_priorities', ['id' => $defaults['priority']->id]);
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
    public function new_tickets_use_default_sla_when_no_rule_or_contract_overrides_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-15 10:00:00'));
        $defaultSla = $this->createSla('Default SLA', true, 8, 4, 1);

        $ticket = app(\App\Modules\Ticket\Actions\StoreTicket::class)->handle([
            'subject' => 'Default SLA support request',
            'channel' => 'manual',
        ], $this->tech);

        $this->assertSame($defaultSla->id, $ticket->sla_id);
        $this->assertSame('default', $ticket->sla_source);
        $this->assertSame($defaultSla->id, $ticket->sla_source_id);
        $this->assertSame('medium', $ticket->sla_snapshot['priority_band']);
        $this->assertSame('2026-05-15 14:00:00', $ticket->first_response_due_at->toDateTimeString());
        $this->assertSame('2026-05-16 02:00:00', $ticket->resolve_due_at->toDateTimeString());

        Carbon::setTestNow();
    }

    #[Test]
    public function ticket_rules_can_override_sla_policy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-15 10:00:00'));
        $this->createSla('Default SLA', true, 8, 4, 1);
        $priority = app(EnsureTicketDefaults::class)->handle()['priority'];
        $ruleSla = $this->createSla('VIP SLA', false, 2, 1, 1);

        TicketRule::create([
            'name' => 'VIP customer SLA',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'VIP'],
            ],
            'actions_json' => [
                ['type' => 'set_sla', 'value' => (string) $ruleSla->id],
            ],
        ]);

        $ticket = app(\App\Modules\Ticket\Actions\StoreTicket::class)->handle([
            'subject' => 'VIP printer is down',
            'priority_id' => $priority->id,
        ], $this->tech);

        $this->assertSame($ruleSla->id, $ticket->sla_id);
        $this->assertSame('ticket_rule', $ticket->sla_source);
        $this->assertSame('2026-05-15 11:00:00', $ticket->first_response_due_at->toDateTimeString());

        Carbon::setTestNow();
    }

    #[Test]
    public function active_contract_sla_is_used_before_default_sla(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-15 10:00:00'));
        $this->createSla('Default SLA', true, 8, 4, 1);
        $contractSla = $this->createSla('Contract SLA', false, 3, 2, 1);
        $client = Client::factory()->create();
        $contract = Contracts::create([
            'client_id' => $client->id,
            'sla_id' => $contractSla->id,
            'created_by' => $this->tech->id,
            'description' => 'Active support contract',
            'approval_status' => 'won',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'auto_renew' => false,
        ]);

        $ticket = app(\App\Modules\Ticket\Actions\StoreTicket::class)->handle([
            'subject' => 'Contract backed support request',
            'client_id' => $client->id,
        ], $this->tech);

        $this->assertSame($contractSla->id, $ticket->sla_id);
        $this->assertSame('contract', $ticket->sla_source);
        $this->assertSame($contract->id, $ticket->sla_source_id);
        $this->assertSame('2026-05-15 12:00:00', $ticket->first_response_due_at->toDateTimeString());

        Carbon::setTestNow();
    }

    #[Test]
    public function ticket_action_guard_blocks_customer_reply_on_closed_ticket(): void
    {
        Queue::fake();
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $closed = TicketStatus::where('slug', 'closed')->firstOrFail();
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'email' => 'closed-contact@example.com',
        ]);
        $ticket = $this->createTicket($contact, [
            'ticket_key' => 'TD-2026-999033',
            'status_id' => $closed->id,
            'priority_id' => $defaults['priority']->id,
            'closed_at' => now(),
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.messages.store', $ticket), [
                'type' => 'customer_reply',
                'visibility' => 'public',
                'body' => 'This should not send.',
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, $ticket->messages()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function apply_ticket_sla_action_updates_policy_due_dates_and_audit_event(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-15 10:00:00'));
        $sla = $this->createSla('Manual SLA', false, 8, 4, 1);
        $ticket = $this->createTicket(null, [
            'ticket_key' => 'TD-2026-999034',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(\App\Modules\Ticket\Actions\ApplyTicketSla::class)->handle($ticket, $sla, $this->tech);

        $ticket->refresh();
        $this->assertSame($sla->id, $ticket->sla_id);
        $this->assertSame('manual', $ticket->sla_source);
        $this->assertSame($sla->id, $ticket->sla_source_id);
        $this->assertSame('2026-05-15 14:00:00', $ticket->first_response_due_at->toDateTimeString());
        $this->assertSame(1, $ticket->events()->where('type', 'sla_applied')->count());

        Carbon::setTestNow();
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

    private function createSla(string $name, bool $isDefault, int $lowFirstResponse, int $mediumFirstResponse, int $highFirstResponse): Sla
    {
        return Sla::create([
            'name' => $name,
            'description' => $name.' policy',
            'is_default' => $isDefault,
            'low_firstResponse' => $lowFirstResponse,
            'low_firstResponse_type' => 'hours',
            'low_onsite' => $lowFirstResponse * 4,
            'low_onsite_type' => 'hours',
            'medium_firstResponse' => $mediumFirstResponse,
            'medium_firstResponse_type' => 'hours',
            'medium_onsite' => $mediumFirstResponse * 4,
            'medium_onsite_type' => 'hours',
            'high_firstResponse' => $highFirstResponse,
            'high_firstResponse_type' => 'hours',
            'high_onsite' => $highFirstResponse * 4,
            'high_onsite_type' => 'hours',
            'created_by_user_id' => $this->tech->id,
        ]);
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
