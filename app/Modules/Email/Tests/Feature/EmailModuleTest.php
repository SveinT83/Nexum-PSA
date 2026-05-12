<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Controllers\Admin\AccountsController;
use App\Modules\Email\Controllers\Admin\Templates\EmailTemplateController;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Controllers\Tech\InboxController;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);
        Role::create(['name' => 'Admin']);

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function tech_user_can_open_inbox_from_email_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.inbox.index');

        $this->assertSame(InboxController::class . '@index', $route->getActionName());

        $this->actingAs($this->tech)
            ->get(route('tech.inbox.index'))
            ->assertOk()
            ->assertViewIs('email::Tech.index')
            ->assertViewHas('messages');
    }

    #[Test]
    public function admin_can_open_email_accounts_from_email_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.settings.email.accounts');

        $this->assertSame(AccountsController::class . '@index', $route->getActionName());

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.accounts'))
            ->assertOk()
            ->assertViewIs('email::Admin.Accounts.index')
            ->assertViewHas('accounts');
    }

    #[Test]
    public function legacy_email_job_namespaces_still_resolve_after_module_move(): void
    {
        $jobs = [
            'StoreInboundMessage',
            'FetchImapAccount',
            'PollActiveEmailAccounts',
            'ProcessInboundRules',
            'EmailAccountHealthCheckJob',
            'EmailRetentionPurgeJob',
        ];

        foreach ($jobs as $job) {
            $legacyClass = 'App\\Domain\\Email\\Jobs\\' . $job;
            $moduleClass = 'App\\Modules\\Email\\Jobs\\' . $job;

            $this->assertTrue(class_exists($legacyClass));
            $this->assertTrue(is_subclass_of($legacyClass, $moduleClass));
        }
    }

    #[Test]
    public function admin_can_open_email_templates_from_template_hub(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.system.templatesManagement.email.index');

        $this->assertSame(EmailTemplateController::class . '@index', $route->getActionName());

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.templatesManagement.index'))
            ->assertOk()
            ->assertSee('Email Templates');

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.accounts'))
            ->assertOk()
            ->assertSee(route('tech.admin.system.templatesManagement.email.index'));
    }

    #[Test]
    public function admin_can_create_and_update_email_template(): void
    {
        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.templatesManagement.email.store'), [
                'scope' => 'tickets',
                'key' => 'ticket_follow_up',
                'name' => 'Ticket follow up',
                'subject' => '[{{ ticket_key }}] Follow up',
                'body_html' => '<p>{{ message_body }}</p>',
                'body_text' => '{{ message_body }}',
                'variables' => "ticket_key\nmessage_body",
                'is_default' => '0',
                'is_active' => '1',
            ])
            ->assertRedirect(route('tech.admin.system.templatesManagement.email.index'));

        $template = EmailTemplate::where('key', 'ticket_follow_up')->firstOrFail();

        $this->assertSame(['ticket_key', 'message_body'], $template->variables);

        $this->actingAs($this->admin)
            ->put(route('tech.admin.system.templatesManagement.email.update', $template), [
                'scope' => 'tickets',
                'key' => 'ticket_follow_up',
                'name' => 'Ticket follow up updated',
                'subject' => '[{{ ticket_key }}] Updated',
                'body_html' => '<p>Updated</p>',
                'body_text' => 'Updated',
                'variables' => 'ticket_key,message_body',
                'is_default' => '1',
                'is_active' => '1',
            ])
            ->assertRedirect(route('tech.admin.system.templatesManagement.email.index'));

        $this->assertDatabaseHas('email_templates', [
            'id' => $template->id,
            'name' => 'Ticket follow up updated',
            'is_default' => true,
        ]);
    }

    #[Test]
    public function default_email_templates_are_seeded(): void
    {
        $this->seed(EmailTemplateSeeder::class);

        $this->assertDatabaseHas('email_templates', [
            'scope' => 'tickets',
            'key' => 'ticket_reply',
            'is_default' => true,
        ]);

        $this->assertDatabaseHas('email_templates', [
            'scope' => 'system',
            'key' => 'system_notification',
            'is_default' => true,
        ]);
    }

    #[Test]
    public function inbound_email_with_ticket_key_in_subject_is_linked_to_ticket(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-000004',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'test mail',
            'is_unread' => false,
        ]);
        $account = EmailAccount::create([
            'address' => 'support@example.com',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@example.com',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'support@example.com',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 44,
            'message_id' => '<gmail-reply@example.com>',
            'subject' => 'Re: "[TD-2026-000004] test mail"',
            'from_name' => 'Customer Name',
            'from_email' => 'customer@gmail.com',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'This is the customer reply from Gmail.',
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);
        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertSame('linked', $email->fresh()->state);
        $this->assertTrue($ticket->fresh()->is_unread);
        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count());

        $ticketMessage = TicketMessage::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('contact', $ticketMessage->author_type);
        $this->assertSame('customer_reply', $ticketMessage->type);
        $this->assertSame('public', $ticketMessage->visibility);
        $this->assertSame('This is the customer reply from Gmail.', $ticketMessage->body);
        $this->assertSame($email->id, $ticketMessage->metadata['email_message_id']);

        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'inbound_email_linked',
            'message' => 'Customer reply received by email.',
        ]);
    }

    #[Test]
    public function admin_can_create_inbound_email_rule(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.rules'))
            ->assertOk()
            ->assertSee('System rules')
            ->assertSee('Link inbound reply to ticket by subject token');

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.rules.store'), [
                'name' => 'Archive obvious spam',
                'description' => 'Hide sender domain before ticket routing.',
                'weight' => 1,
                'is_active' => '1',
                'stop_processing' => '1',
                'conditions' => [
                    ['field' => 'from_domain', 'operator' => 'contains', 'value' => 'spam.test'],
                ],
                'actions' => [
                    ['type' => 'archive', 'value' => ''],
                    ['type' => 'tag', 'value' => 'spam'],
                ],
            ])
            ->assertRedirect(route('tech.admin.settings.email.rules'));

        $this->assertDatabaseHas('email_rules', [
            'name' => 'Archive obvious spam',
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => true,
        ]);
        $this->assertDatabaseHas('tags', [
            'name' => 'spam',
            'active' => true,
        ]);
    }

    #[Test]
    public function custom_inbound_rule_can_archive_spam_before_ticket_linking(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-000004',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'test mail',
            'is_unread' => false,
        ]);
        $account = EmailAccount::create([
            'address' => 'support-rules@example.com',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support-rules@example.com',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'support-rules@example.com',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 45,
            'subject' => 'Re: [TD-2026-000004] Buy nonsense now',
            'from_email' => 'sender@spam.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Spam body.',
        ]);
        EmailRule::create([
            'name' => 'Archive spam domain',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => [
                ['field' => 'from_domain', 'operator' => 'equals', 'value' => 'spam.test'],
            ],
            'actions_json' => [
                ['type' => 'archive', 'value' => ''],
                ['type' => 'tag', 'value' => 'spam'],
            ],
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $email->refresh();

        $this->assertNull($email->ticket_id);
        $this->assertSame('archived', $email->state);
        $this->assertTrue($email->tags()->where('tags.name', 'spam')->exists());
        $this->assertFalse($ticket->fresh()->is_unread);
        $this->assertSame(0, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertDatabaseHas('email_rule_logs', [
            'email_rule_id' => EmailRule::firstOrFail()->id,
            'email_message_id' => $email->id,
            'status' => 'matched',
        ]);
    }
}
