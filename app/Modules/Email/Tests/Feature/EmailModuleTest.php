<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Controllers\Admin\AccountsController;
use App\Modules\Email\Controllers\Admin\Templates\EmailTemplateController;
use App\Modules\Email\Jobs\EmailAccountHealthCheckJob;
use App\Modules\Email\Jobs\FetchImapAccount;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Jobs\StoreInboundMessage;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailHealthCheck;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailTestResult;
use App\Modules\Email\Services\EmailTestService;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\HtmlSanitizer;
use App\Modules\Email\Controllers\Tech\InboxController;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
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
        $this->assertSame(InboxController::class . '@markSpam', Route::getRoutes()->getByName('tech.inbox.spam')->getActionName());
        $account = EmailAccount::create([
            'address' => 'support@example.test',
            'from_name' => 'Support',
            'is_active' => true,
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
        EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 63,
            'message_id' => '<inbox-list@example.test>',
            'subject' => 'Inbox list item',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Inbox list body.',
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.inbox.index'))
            ->assertOk()
            ->assertViewIs('email::Tech.index')
            ->assertViewHas('messages')
            ->assertSee('inbox-index-row')
            ->assertSee('Mark as spam');
    }

    #[Test]
    public function tech_user_can_mark_inbox_email_as_spam_and_create_rule(): void
    {
        $account = EmailAccount::create([
            'address' => 'support@example.test',
            'from_name' => 'Support',
            'is_active' => true,
            'is_global_default' => false,
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
            'imap_uid' => 9001,
            'message_id' => '<spam-inbox@example.test>',
            'subject' => 'Unwanted promo',
            'from_name' => 'Promo Sender',
            'from_email' => 'promo@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Buy this now.',
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.inbox.spam', $email))
            ->assertRedirect(route('tech.inbox.index'))
            ->assertSessionHas('status', 'Email marked as spam and rule "Spam: promo@example.test" updated.');

        $email->refresh();

        $this->assertSame('archived', $email->state);
        $this->assertTrue($email->tags()->where('tags.slug', 'spam')->exists());

        $rule = EmailRule::query()->where('name', 'Spam: promo@example.test')->first();

        $this->assertNotNull($rule);
        $this->assertTrue($rule->is_active);
        $this->assertTrue($rule->stop_processing);
        $this->assertSame([
            ['field' => 'from', 'operator' => 'equals', 'value' => 'promo@example.test'],
        ], $rule->conditions_json);
        $this->assertSame([
            ['type' => 'tag', 'value' => 'spam'],
            ['type' => 'archive', 'value' => ''],
        ], $rule->actions_json);
    }

    #[Test]
    public function authenticated_api_user_can_search_show_mark_spam_and_poll_inbox(): void
    {
        Queue::fake();

        $account = EmailAccount::create([
            'address' => 'support@example.test',
            'from_name' => 'Support',
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@example.test',
            'imap_secret' => 'secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'support@example.test',
            'smtp_secret' => 'secret',
            'smtp_auth_type' => 'password',
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9101,
            'message_id' => '<api-inbox@example.test>',
            'subject' => 'API inbox lookup',
            'from_name' => 'API Sender',
            'from_email' => 'api-sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'This message should be searchable.',
        ]);
        EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9102,
            'message_id' => '<linked@example.test>',
            'subject' => 'Linked ticket message',
            'from_email' => 'linked@example.test',
            'received_at' => now(),
            'state' => 'linked',
            'ticket_id' => 123,
        ]);

        Sanctum::actingAs($this->tech, ['email.read', 'email.update']);

        $this->getJson(route('api.v1.email.inbox.messages.index', ['q' => 'lookup']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $message->id)
            ->assertJsonPath('data.0.account.address', 'support@example.test');

        $this->getJson(route('api.v1.email.inbox.messages.show', $message))
            ->assertOk()
            ->assertJsonPath('data.subject', 'API inbox lookup');

        $this->postJson(route('api.v1.email.inbox.messages.spam', $message))
            ->assertOk()
            ->assertJsonPath('data.message.state', 'archived')
            ->assertJsonPath('data.rule.name', 'Spam: api-sender@example.test');

        $this->postJson(route('api.v1.email.inbox.poll'))
            ->assertAccepted()
            ->assertJsonPath('data.queued_accounts', 1);

        Queue::assertPushed(FetchImapAccount::class, 1);
    }

    #[Test]
    public function email_read_api_token_cannot_mark_spam_or_poll(): void
    {
        $account = EmailAccount::create([
            'address' => 'readonly@example.test',
            'from_name' => 'Read Only',
            'is_active' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'readonly@example.test',
            'imap_secret' => 'secret',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'readonly@example.test',
            'smtp_secret' => 'secret',
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9103,
            'message_id' => '<readonly@example.test>',
            'subject' => 'Read only',
            'from_email' => 'readonly@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);

        Sanctum::actingAs($this->tech, ['email.read']);

        $this->getJson(route('api.v1.email.inbox.messages.show', $message))
            ->assertOk();

        $this->postJson(route('api.v1.email.inbox.messages.spam', $message))
            ->assertForbidden();

        $this->postJson(route('api.v1.email.inbox.poll'))
            ->assertForbidden();
    }

    #[Test]
    public function check_now_queues_email_polling_jobs_without_running_imap_in_request(): void
    {
        Queue::fake();

        EmailAccount::create([
            'address' => 'support@example.test',
            'from_name' => 'Support',
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@example.test',
            'imap_secret' => 'secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'support@example.test',
            'smtp_secret' => 'secret',
            'smtp_auth_type' => 'password',
        ]);

        EmailAccount::create([
            'address' => 'disabled@example.test',
            'from_name' => 'Disabled',
            'is_active' => false,
            'is_global_default' => false,
            'defaults_for' => [],
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'disabled@example.test',
            'imap_secret' => 'secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'disabled@example.test',
            'smtp_secret' => 'secret',
            'smtp_auth_type' => 'password',
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.inbox.poll'))
            ->assertRedirect(route('tech.inbox.index'))
            ->assertSessionHas('status', 'Inbox check queued for 1 account.');

        Queue::assertPushed(FetchImapAccount::class, 1);
        Queue::assertPushed(FetchImapAccount::class, fn (FetchImapAccount $job) => $job->batchSize === 20 && $job->syncStore === false);
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
    public function store_inbound_message_skips_soft_deleted_duplicate_uid_without_failing_worker(): void
    {
        Queue::fake();

        $account = EmailAccount::create([
            'address' => 'duplicate@example.test',
            'from_name' => 'Duplicate Inbox',
            'is_active' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'duplicate@example.test',
            'imap_secret' => 'secret',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'duplicate@example.test',
            'smtp_secret' => 'secret',
        ]);

        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 804,
            'message_id' => '<existing-duplicate@example.test>',
            'subject' => 'Existing hidden message',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Already imported once.',
        ]);
        $message->delete();

        app()->call([new StoreInboundMessage([
            'account_id' => $account->id,
            'imap_uid' => 804,
            'message_id' => '<new-duplicate@example.test>',
            'subject' => 'Duplicate hidden message',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'is_oversize' => true,
        ]), 'handle']);

        $this->assertSame(1, EmailMessage::withTrashed()
            ->where('account_id', $account->id)
            ->where('mailbox', 'INBOX')
            ->where('imap_uid', 804)
            ->count());
        $this->assertSame(0, EmailMessage::query()
            ->where('account_id', $account->id)
            ->where('mailbox', 'INBOX')
            ->where('imap_uid', 804)
            ->count());

        Queue::assertNotPushed(ProcessInboundRules::class);
    }

    #[Test]
    public function inbound_email_html_sanitizer_removes_active_content(): void
    {
        $html = <<<'HTML'
            <p onclick="alert(1)">Hello <strong>support</strong></p>
            <img src="javascript:alert(1)" onerror="alert(1)" alt="bad">
            <a href="javascript:alert(1)">unsafe link</a>
            <a href="https://example.test/help">safe link</a>
            <script>alert(1)</script>
            <iframe src="https://example.test"></iframe>
        HTML;

        $clean = HtmlSanitizer::sanitize($html);

        $this->assertStringContainsString('<strong>support</strong>', $clean);
        $this->assertStringContainsString('https://example.test/help', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('<iframe', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
    }

    #[Test]
    public function legacy_stored_email_html_is_sanitized_when_read_by_ui_and_api(): void
    {
        $account = EmailAccount::create([
            'address' => 'legacy-html@example.test',
            'from_name' => 'Legacy HTML',
            'is_active' => true,
            'is_global_default' => false,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'legacy-html@example.test',
            'imap_secret' => 'secret',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'legacy-html@example.test',
            'smtp_secret' => 'secret',
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9301,
            'message_id' => '<legacy-html@example.test>',
            'subject' => 'Legacy unsafe HTML',
            'from_name' => 'Legacy Sender',
            'from_email' => 'legacy@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_html_sanitized' => '<p onclick="alert(1)">Safe text</p><a href="javascript:alert(1)">bad</a><a href="https://example.test">good</a><script>alert(1)</script>',
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.inbox.show', $message))
            ->assertOk()
            ->assertSee('Safe text')
            ->assertSee('https://example.test', false)
            ->assertDontSee('alert(1)', false)
            ->assertDontSee('javascript:', false);

        Sanctum::actingAs($this->tech, ['email.read']);

        $this->getJson(route('api.v1.email.inbox.messages.show', [$message, 'include_html' => true]))
            ->assertOk()
            ->assertJsonPath('data.body_html_sanitized', fn (?string $html) => $html !== null
                && str_contains($html, 'Safe text')
                && str_contains($html, 'https://example.test')
                && ! str_contains($html, 'onclick')
                && ! str_contains($html, 'javascript:')
                && ! str_contains($html, '<script'));
    }

    #[Test]
    public function email_account_health_check_job_persists_real_test_result(): void
    {
        $account = EmailAccount::create([
            'address' => 'health@example.test',
            'from_name' => 'Health',
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'health@example.test',
            'imap_secret' => 'secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'health@example.test',
            'smtp_secret' => 'secret',
            'smtp_auth_type' => 'password',
        ]);
        $result = new EmailTestResult();
        $result->imap_ok = true;
        $result->smtp_ok = false;
        $result->imap_ms = 12.4;
        $result->smtp_ms = 25.8;
        $result->smtp_error_code = 'SMTP_AUTH';
        $result->smtp_error_message = 'Authentication failed';

        $this->mock(EmailTestService::class)
            ->shouldReceive('run')
            ->once()
            ->withArgs(fn (EmailAccount $testedAccount) => $testedAccount->is($account))
            ->andReturn($result);

        app()->call([new EmailAccountHealthCheckJob($account->id), 'handle']);

        $check = EmailHealthCheck::query()->where('account_id', $account->id)->firstOrFail();

        $this->assertSame('OK', $check->imap_status);
        $this->assertSame('Error', $check->smtp_status);
        $this->assertSame('SMTP_AUTH', $check->error_code);
        $this->assertSame('SMTP: Authentication failed', $check->error_message);
        $this->assertSame(12.4, $check->durations_json['imap_ms']);
        $this->assertSame(25.8, $check->durations_json['smtp_ms']);
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
    public function email_accounts_can_be_marked_as_marketing_default_sender(): void
    {
        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.accounts.store'), $this->emailAccountPayload([
                'address' => 'marketing@example.test',
                'defaults_for' => ['marketing'],
            ]))
            ->assertRedirect(route('tech.admin.settings.email.accounts'));

        $account = EmailAccount::query()->where('address', 'marketing@example.test')->firstOrFail();

        $this->assertSame(['marketing'], $account->defaults_for);
        $this->assertTrue(app(DefaultEmailAccountResolver::class)->forScope('marketing')->is($account));

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.accounts'))
            ->assertOk()
            ->assertSee('Default (Marketing)');
    }

    #[Test]
    public function template_management_creates_missing_default_sales_templates_without_overwriting_custom_templates(): void
    {
        EmailTemplate::create([
            'scope' => 'tickets',
            'key' => 'ticket_reply',
            'name' => 'Custom ticket reply',
            'subject' => 'Custom subject',
            'body_html' => '<p>Custom</p>',
            'body_text' => 'Custom',
            'variables' => ['custom'],
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.templatesManagement.email.index', ['scope' => 'sales']))
            ->assertOk()
            ->assertSee('Sales activity email')
            ->assertSee('Sales quote send');

        $this->assertDatabaseHas('email_templates', [
            'scope' => 'sales',
            'key' => 'sales_quote_send',
            'is_active' => true,
        ]);
        $this->assertSame('Custom subject', EmailTemplate::where('scope', 'tickets')->where('key', 'ticket_reply')->value('subject'));
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

        $this->assertDatabaseHas('email_templates', [
            'scope' => 'system',
            'key' => 'user_invite',
            'is_default' => true,
            'is_active' => true,
        ]);

        foreach (['sales_activity_email', 'sales_internal_note', 'sales_quote_send'] as $key) {
            $this->assertDatabaseHas('email_templates', [
                'scope' => 'sales',
                'key' => $key,
                'is_default' => true,
                'is_active' => true,
            ]);
        }

        $this->assertDatabaseHas('email_templates', [
            'scope' => 'marketing',
            'key' => 'marketing_campaign_default',
            'is_default' => true,
            'is_active' => true,
        ]);

        $marketingTemplate = EmailTemplate::query()
            ->where('scope', 'marketing')
            ->where('key', 'marketing_campaign_default')
            ->firstOrFail();

        $this->assertSame('Marketing update from {{ company_name }}', $marketingTemplate->subject);
        $this->assertSame(['contact_name', 'company_name', 'unsubscribe_url'], $marketingTemplate->variables);
        $this->assertStringNotContainsString('campaign_heading', $marketingTemplate->body_html);
        $this->assertStringNotContainsString('primary_cta_url', $marketingTemplate->body_html);
    }

    #[Test]
    public function marketing_email_template_renders_with_branding_and_preview(): void
    {
        CommonSetting::query()->create([
            'type' => 'company_profile',
            'name' => 'branding',
            'description' => 'Test branding',
            'value' => 'Tronder Data',
            'json' => json_encode([
                'company_name' => 'Tronder Data',
                'support_email' => 'support@tronderdata.no',
                'website' => 'https://tronderdata.no',
                'primary_color' => '#0055AA',
                'secondary_color' => '#003366',
                'accent_color' => '#AA5500',
            ]),
        ]);

        $this->seed(EmailTemplateSeeder::class);
        $template = EmailTemplate::query()
            ->where('scope', 'marketing')
            ->where('key', 'marketing_campaign_default')
            ->firstOrFail();
        $renderer = app(EmailTemplateRenderer::class);
        $rendered = $renderer->render($template, $renderer->sampleVariables($template));

        $this->assertSame(['contact_name', 'company_name', 'unsubscribe_url'], $template->variables);
        $this->assertStringNotContainsString('campaign_heading', $template->body_html);
        $this->assertStringNotContainsString('primary_cta_url', $template->body_html);
        $this->assertStringContainsString('Tronder Data', $rendered['html']);
        $this->assertStringContainsString('#003366', $rendered['html']);
        $this->assertStringContainsString('Unsubscribe', $rendered['html']);
        $this->assertStringContainsString('support@tronderdata.no', $rendered['html']);
        $this->assertStringContainsString('Unsubscribe:', $rendered['text']);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.templatesManagement.email.edit', $template))
            ->assertOk()
            ->assertSee('Rendered Preview')
            ->assertSee('marketing', false)
            ->assertSee('unsubscribe_url');
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
    public function inbound_customer_reply_resumes_ticket_waiting_for_customer(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $waiting = TicketStatus::where('slug', 'waiting-customer')->firstOrFail();
        $inProgress = TicketStatus::where('slug', 'in-progress')->firstOrFail();
        $workflow = TicketWorkflow::where('is_default', true)->firstOrFail();
        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-000044',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $waiting->id,
            'workflow_id' => $workflow->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Waiting customer reply',
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
            'imap_uid' => 144,
            'message_id' => '<waiting-reply@example.com>',
            'subject' => 'Re: "[TD-2026-000044] Waiting customer reply"',
            'from_name' => 'Customer Name',
            'from_email' => 'customer@example.com',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Here is the information you asked for.',
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $this->assertSame($inProgress->id, $ticket->fresh()->status_id);
        $this->assertTrue($ticket->fresh()->is_unread);
    }

    #[Test]
    public function inbound_hard_bounce_creates_signal_suppresses_contact_and_skips_ticket_routing(): void
    {
        $account = EmailAccount::create([
            'address' => 'marketing@example.com',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'marketing@example.com',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'marketing@example.com',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Bounced Contact',
            'do_not_email' => false,
            'marketing_consent' => true,
        ]);
        ContactEmail::query()->create([
            'contact_id' => $contact->id,
            'label' => 'work',
            'email' => 'bounced@example.test',
            'is_primary' => true,
        ]);
        SignalRule::query()->create([
            'name' => 'Suppress hard bounces',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [
                'source_domain' => ['email'],
                'signal_type' => ['hard_bounce'],
            ],
            'actions' => [
                ['type' => 'marketing_suppress_contact_email'],
                ['type' => 'tag_contact', 'tag' => 'Hard bounce'],
            ],
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 501,
            'message_id' => '<bounce@example.com>',
            'subject' => 'Undeliverable: Campaign',
            'from_email' => 'mailer-daemon@example.net',
            'headers_json' => ['Content-Type' => 'multipart/report; report-type=delivery-status'],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => "Delivery has failed.\nFinal-Recipient: rfc822; bounced@example.test\nDiagnostic-Code: smtp; 550 5.1.1 user unknown",
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);
        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $signal = Signal::query()->where('source_type', EmailMessage::class)->where('source_id', $email->id)->firstOrFail();

        $this->assertSame('hard_bounce', $signal->signal_type);
        $this->assertSame($contact->id, $signal->contact_id);
        $this->assertSame(1, $signal->executions()->count());
        $this->assertSame('archived', $email->fresh()->state);
        $this->assertNull($email->fresh()->ticket_id);
        $this->assertTrue($contact->fresh()->do_not_email);
        $this->assertFalse($contact->fresh()->marketing_consent);
        $this->assertSame(1, $contact->fresh()->tags()->where('slug', 'hard-bounce')->count());
        $this->assertSame(1, Signal::query()->where('source_id', $email->id)->count());
    }

    #[Test]
    public function inbound_out_of_office_creates_signal_and_skips_ticket_routing(): void
    {
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
            'imap_uid' => 502,
            'message_id' => '<ooo@example.com>',
            'subject' => 'Automatic reply: Out of office',
            'from_email' => 'customer@example.test',
            'headers_json' => ['Auto-Submitted' => 'auto-replied'],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'I am out of office this week.',
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $this->assertDatabaseHas('signals', [
            'source_type' => EmailMessage::class,
            'source_id' => $email->id,
            'source_domain' => 'email',
            'signal_type' => 'out_of_office',
        ]);
        $this->assertSame('archived', $email->fresh()->state);
        $this->assertNull($email->fresh()->ticket_id);
    }

    #[Test]
    public function inbound_qnap_vendor_notification_creates_signal_and_rule_ticket(): void
    {
        SignalRule::query()->create([
            'name' => 'QNAP firmware notice',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [
                'source_domain' => ['email'],
                'signal_type' => ['vendor_notification'],
                'payload_equals' => ['vendor' => 'qnap'],
                'payload_contains' => ['title' => 'firmware'],
            ],
            'actions' => [
                ['type' => 'ticket_follow_up', 'subject' => 'Review QNAP firmware notice'],
            ],
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
            'imap_uid' => 503,
            'message_id' => '<qnap-firmware@example.com>',
            'subject' => 'QNAP Firmware Update Available',
            'from_email' => 'newsletter@qnap.com',
            'headers_json' => [],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'QNAP firmware update available for NAS devices. You can unsubscribe from notifications.',
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);
        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $signal = Signal::query()->where('source_id', $email->id)->firstOrFail();

        $this->assertSame('vendor_notification', $signal->signal_type);
        $this->assertSame('qnap', $signal->payload['vendor']);
        $this->assertSame('archived', $email->fresh()->state);
        $this->assertNull($email->fresh()->ticket_id);
        $this->assertSame(1, Ticket::query()->where('subject', 'Review QNAP firmware notice')->count());
    }

    #[Test]
    public function inbound_email_reply_headers_link_to_ticket_without_subject_token(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-000014',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Header linked ticket',
            'is_unread' => false,
        ]);
        $outboundMessage = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Previous reply.',
        ]);
        EmailLog::create([
            'direction' => 'outbound',
            'scope' => 'tickets',
            'level' => 'info',
            'code' => 'TICKET_EMAIL_SENT',
            'message' => 'Ticket reply email sent.',
            'context_json' => ['ticket_message_id' => $outboundMessage->id],
            'rfc_message_id' => '<outbound-ticket-reply@example.com>',
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
            'imap_uid' => 144,
            'message_id' => '<customer-header-reply@example.com>',
            'in_reply_to' => 'outbound-ticket-reply@example.com',
            'references' => 'older@example.com outbound-ticket-reply@example.com',
            'subject' => 'Changed subject from customer',
            'from_name' => 'Customer Name',
            'from_email' => 'customer@gmail.com',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Header based reply.',
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertSame('linked', $email->fresh()->state);
        $this->assertTrue($ticket->fresh()->is_unread);
        $this->assertSame(2, TicketMessage::where('ticket_id', $ticket->id)->count());

        $ticketMessage = TicketMessage::where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $this->assertSame('Header based reply.', $ticketMessage->body);
        $this->assertSame($email->id, $ticketMessage->metadata['email_message_id']);
    }

    #[Test]
    public function inbound_ticket_reply_strips_quoted_email_history(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-000015',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Quoted history ticket',
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
            'imap_uid' => 145,
            'message_id' => '<quoted-customer-reply@example.com>',
            'subject' => 'Re: [TD-2026-000015] Quoted history ticket',
            'from_name' => 'Customer Name',
            'from_email' => 'customer@gmail.com',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => "Det går bare bra. Jeg har ingen oppdateringer enda.\n\n"
                . "tor. 14. mai 2026 kl. 13:51 skrev Svein Tore <post@example.com>:\n\n"
                . "> Hello Svein Tore,\n>\n> Hei hvordan går det med deg?",
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $ticketMessage = TicketMessage::where('ticket_id', $ticket->id)->firstOrFail();

        $this->assertSame('Det går bare bra. Jeg har ingen oppdateringer enda.', $ticketMessage->body);
    }

    #[Test]
    public function inbound_ticket_reply_strips_content_below_reply_boundary(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-000016',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Reply boundary ticket',
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
            'imap_uid' => 146,
            'message_id' => '<boundary-customer-reply@example.com>',
            'subject' => 'Re: [TD-2026-000016] Reply boundary ticket',
            'from_name' => 'Customer Name',
            'from_email' => 'customer@gmail.com',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => "This is the only new text.\n\n"
                . "--- Please reply above this line ---\n\n"
                . "Hello Customer,\n\nOld technician message.",
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $ticketMessage = TicketMessage::where('ticket_id', $ticket->id)->firstOrFail();

        $this->assertSame('This is the only new text.', $ticketMessage->body);
    }

    #[Test]
    public function inbound_email_rule_can_create_new_ticket_from_unmatched_email(): void
    {
        Storage::fake('local');

        app(EnsureTicketDefaults::class)->handle();
        $client = Client::factory()->create(['name' => 'Inbound Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Inbound Contact',
            'email' => 'sender@client.test',
        ]);
        $queue = TicketQueue::create([
            'name' => 'Inbound Support',
            'slug' => 'inbound-support',
            'email_address' => 'support-inbound@example.com',
            'is_active' => true,
            'sort_order' => 20,
        ]);
        $account = EmailAccount::create([
            'address' => 'support-inbound@example.com',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support-inbound@example.com',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'support-inbound@example.com',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 46,
            'message_id' => '<new-ticket@example.com>',
            'subject' => 'VPN access is broken',
            'from_name' => $contact->name,
            'from_email' => $contact->email,
            'to_json' => [['name' => 'Support', 'email' => 'support-inbound@example.com']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'I cannot connect to VPN this morning.',
        ]);
        Storage::disk('local')->put('email/attachments/test/vpn.txt', 'vpn logs');
        $emailAttachment = EmailAttachment::create([
            'message_id' => $email->id,
            'filename' => 'vpn.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 8,
            'disk' => 'local',
            'path' => 'email/attachments/test/vpn.txt',
            'checksum_sha1' => sha1('vpn logs'),
        ]);
        EmailRule::create([
            'name' => 'Create support ticket from known client',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => [
                ['field' => 'from_domain', 'operator' => 'equals', 'value' => 'client.test'],
            ],
            'actions_json' => [
                ['type' => 'create_ticket', 'value' => $queue->slug],
            ],
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);
        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $ticket = Ticket::where('subject', 'VPN access is broken')->firstOrFail();

        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertSame('linked', $email->fresh()->state);
        $this->assertSame($queue->id, $ticket->queue_id);
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame($site->id, $ticket->site_id);
        $this->assertSame($contact->id, $ticket->contact_id);
        $this->assertSame('email', $ticket->channel);
        $this->assertSame('I cannot connect to VPN this morning.', $ticket->description);
        $this->assertTrue($ticket->is_unread);
        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count());

        $message = TicketMessage::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('contact', $message->author_type);
        $this->assertSame('customer_reply', $message->type);
        $this->assertSame('public', $message->visibility);
        $this->assertSame('I cannot connect to VPN this morning.', $message->body);
        $this->assertSame($email->id, $message->metadata['email_message_id']);

        $ticketAttachment = TicketAttachment::firstOrFail();
        $this->assertSame($ticket->id, $ticketAttachment->ticket_id);
        $this->assertSame($message->id, $ticketAttachment->ticket_message_id);
        $this->assertSame($emailAttachment->id, $ticketAttachment->email_attachment_id);
        $this->assertSame('email', $ticketAttachment->source);
        Storage::disk('local')->assertExists($ticketAttachment->path);

        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'created',
        ]);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'inbound_email_linked',
        ]);
        $this->assertDatabaseHas('email_rule_logs', [
            'email_rule_id' => EmailRule::firstOrFail()->id,
            'email_message_id' => $email->id,
            'status' => 'matched',
        ]);
    }

    #[Test]
    public function create_ticket_rule_links_existing_ticket_reply_by_subject_key(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-000008',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Ny sak',
            'is_unread' => false,
        ]);
        $queue = TicketQueue::create([
            'name' => 'Post mailbox',
            'slug' => 'post-mailbox',
            'email_address' => 'post@example.com',
            'is_active' => true,
            'sort_order' => 30,
        ]);
        $account = EmailAccount::create([
            'address' => 'post@example.com',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'post@example.com',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'post@example.com',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 147,
            'message_id' => '<reply-with-ticket-key@example.com>',
            'subject' => 'Re: [TD-2026-000008] Ny sak',
            'from_name' => 'Customer Name',
            'from_email' => 'customer@example.com',
            'to_json' => [['name' => 'Post', 'email' => 'post@example.com']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Reply belongs on the original ticket.',
        ]);
        EmailRule::create([
            'name' => 'Create ticket from post mailbox',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => [
                ['field' => 'to', 'operator' => 'contains', 'value' => 'post@example.com'],
            ],
            'actions_json' => [
                ['type' => 'create_ticket', 'value' => $queue->slug],
            ],
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertSame('linked', $email->fresh()->state);
        $this->assertTrue($ticket->fresh()->is_unread);
        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertSame(1, Ticket::count());

        $message = TicketMessage::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('Reply belongs on the original ticket.', $message->body);
        $this->assertSame($email->id, $message->metadata['email_message_id']);
    }

    #[Test]
    public function unmatched_inbound_email_from_known_client_contact_creates_ticket_by_default(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $client = Client::factory()->create(['name' => 'Default Ticket Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Known Contact',
            'email' => 'known-contact@example.test',
        ]);
        $account = EmailAccount::create([
            'address' => 'post@tronderdata.no',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'post@tronderdata.no',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'post@tronderdata.no',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 47,
            'message_id' => '<default-ticket@example.test>',
            'subject' => 'Default ticket routing',
            'from_name' => $contact->name,
            'from_email' => $contact->email,
            'to_json' => [['name' => 'Support', 'email' => 'post@tronderdata.no']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Please help with the printer.',
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $ticket = Ticket::where('subject', 'Default ticket routing')->firstOrFail();

        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertSame('linked', $email->fresh()->state);
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame($site->id, $ticket->site_id);
        $this->assertSame($contact->id, $ticket->contact_id);
        $this->assertSame('email', $ticket->channel);
        $this->assertTrue($ticket->is_unread);
        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count());
    }

    #[Test]
    public function exact_duplicate_inbound_email_links_to_existing_ticket_when_auto_merge_is_enabled(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        CommonSetting::create([
            'type' => 'ticket_merge',
            'name' => 'auto_merge_enabled',
            'value' => '1',
        ]);
        $client = Client::factory()->create(['name' => 'Merge Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Merge Contact',
            'email' => 'merge-contact@example.test',
        ]);
        $existing = Ticket::create([
            'ticket_key' => 'TD-2026-999501',
            'queue_id' => TicketQueue::where('is_default', true)->firstOrFail()->id,
            'ticket_type_id' => TicketType::where('slug', 'support')->firstOrFail()->id,
            'type' => 'support',
            'status_id' => TicketStatus::where('slug', 'new')->firstOrFail()->id,
            'priority_id' => \App\Modules\Ticket\Models\TicketPriority::where('slug', 'normal')->firstOrFail()->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'contact_id' => $contact->id,
            'channel' => 'email',
            'subject' => 'Duplicate printer problem',
            'description' => 'Printer is offline.',
            'is_unread' => false,
        ]);
        $account = EmailAccount::create([
            'address' => 'post@tronderdata.no',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'post@tronderdata.no',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'post@tronderdata.no',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 147,
            'message_id' => '<duplicate-ticket@example.test>',
            'subject' => 'Duplicate printer problem',
            'from_name' => $contact->name,
            'from_email' => $contact->email,
            'to_json' => [['name' => 'Support', 'email' => 'post@tronderdata.no']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Printer is offline.',
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $this->assertSame($existing->id, $email->fresh()->ticket_id);
        $this->assertSame(1, Ticket::where('subject', 'Duplicate printer problem')->count());
        $this->assertSame(1, TicketMessage::where('ticket_id', $existing->id)->count());
    }

    #[Test]
    public function unmatched_inbound_email_from_unknown_sender_creates_lead_ticket_by_default(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $account = EmailAccount::create([
            'address' => 'post@tronderdata.no',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'post@tronderdata.no',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'post@tronderdata.no',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 49,
            'message_id' => '<unknown-lead@example.test>',
            'subject' => 'Need pricing',
            'from_name' => 'Unknown Sender',
            'from_email' => 'unknown@example.test',
            'to_json' => [['name' => 'Post', 'email' => 'post@tronderdata.no']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Can you contact me about a new project?',
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $ticket = Ticket::where('subject', 'Need pricing')->firstOrFail();
        $leadType = TicketType::where('slug', 'lead')->firstOrFail();

        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertSame('linked', $email->fresh()->state);
        $this->assertSame($leadType->id, $ticket->ticket_type_id);
        $this->assertSame('lead', $ticket->type);
        $this->assertNull($ticket->client_id);
        $this->assertNull($ticket->contact_id);
    }

    #[Test]
    public function spam_tagged_unknown_inbound_email_does_not_create_default_lead_ticket(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $account = EmailAccount::create([
            'address' => 'post@tronderdata.no',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'post@tronderdata.no',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'post@tronderdata.no',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 50,
            'message_id' => '<unknown-spam@example.test>',
            'subject' => 'Cheap nonsense',
            'from_name' => 'Spam Sender',
            'from_email' => 'spammy@example.test',
            'to_json' => [['name' => 'Post', 'email' => 'post@tronderdata.no']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Buy this now.',
        ]);
        EmailRule::create([
            'name' => 'Tag spam without archiving',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'Cheap'],
            ],
            'actions_json' => [
                ['type' => 'tag', 'value' => 'spam'],
            ],
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $this->assertNull($email->fresh()->ticket_id);
        $this->assertSame('untriaged', $email->fresh()->state);
        $this->assertTrue($email->fresh()->tags()->where('tags.slug', 'spam')->exists());
        $this->assertSame(0, Ticket::where('subject', 'Cheap nonsense')->count());
    }

    #[Test]
    public function not_ticket_tagged_inbound_email_stays_in_inbox_without_creating_ticket(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $account = EmailAccount::create([
            'address' => 'post@tronderdata.no',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'post@tronderdata.no',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'post@tronderdata.no',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 51,
            'message_id' => '<unknown-not-ticket@example.test>',
            'subject' => 'Newsletter status',
            'from_name' => 'Newsletter Sender',
            'from_email' => 'newsletter@example.test',
            'to_json' => [['name' => 'Post', 'email' => 'post@tronderdata.no']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'This should not become a support ticket.',
        ]);
        EmailRule::create([
            'name' => 'Keep newsletter in inbox',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [
                ['field' => 'from', 'operator' => 'equals', 'value' => 'newsletter@example.test'],
            ],
            'actions_json' => [
                ['type' => 'tag', 'value' => 'not-ticket'],
            ],
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $this->assertNull($email->fresh()->ticket_id);
        $this->assertSame('untriaged', $email->fresh()->state);
        $this->assertTrue($email->fresh()->tags()->where('tags.slug', 'not-ticket')->exists());
        $this->assertSame(0, Ticket::where('subject', 'Newsletter status')->count());
    }

    #[Test]
    public function inbound_email_tags_are_inherited_by_default_ticket_and_can_drive_ticket_rules(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $client = Client::factory()->create(['name' => 'Tagged Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Tagged Contact',
            'email' => 'tagged-contact@example.test',
        ]);
        $category = Category::create([
            'name' => 'Security',
            'slug' => 'security',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);
        $ticketTag = Tag::create([
            'name' => 'Escalated',
            'slug' => 'escalated',
            'active' => true,
        ]);
        $account = EmailAccount::create([
            'address' => 'post@tronderdata.no',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'post@tronderdata.no',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'post@tronderdata.no',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 48,
            'message_id' => '<tagged-ticket@example.test>',
            'subject' => 'Tagged ticket routing',
            'from_name' => $contact->name,
            'from_email' => $contact->email,
            'to_json' => [['name' => 'Support', 'email' => 'post@tronderdata.no']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'This should inherit email tags.',
        ]);

        EmailRule::create([
            'name' => 'Tag security email',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'Tagged'],
            ],
            'actions_json' => [
                ['type' => 'tag', 'value' => 'security'],
            ],
        ]);

        TicketRule::create([
            'name' => 'Security email category',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [
                ['field' => 'email_tags', 'operator' => 'contains', 'value' => 'security'],
            ],
            'actions_json' => [
                ['type' => 'set_category', 'value' => (string) $category->id],
                ['type' => 'add_tag', 'value' => (string) $ticketTag->id],
            ],
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $ticket = Ticket::where('subject', 'Tagged ticket routing')->firstOrFail();

        $this->assertSame($category->id, $ticket->category_id);
        $this->assertTrue($ticket->tags()->where('tags.name', 'security')->exists());
        $this->assertTrue($ticket->tags()->whereKey($ticketTag->id)->exists());
    }

    #[Test]
    public function admin_can_create_inbound_email_rule(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.rules'))
            ->assertOk()
            ->assertSee('System rules')
            ->assertSee('Link inbound reply to ticket by subject token')
            ->assertSee('Create ticket from routed inbound email');

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

        $queue = TicketQueue::create([
            'name' => 'Inbound Sales',
            'slug' => 'inbound-sales',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.rules.store'), [
                'name' => 'Create inbound sales ticket',
                'description' => 'Route known sales mailbox into Ticket.',
                'weight' => 20,
                'is_active' => '1',
                'stop_processing' => '1',
                'conditions' => [
                    ['field' => 'to', 'operator' => 'contains', 'value' => 'sales@example.com'],
                ],
                'actions' => [
                    ['type' => 'create_ticket', 'value' => $queue->slug],
                ],
            ])
            ->assertRedirect(route('tech.admin.settings.email.rules'));

        $this->assertDatabaseHas('email_rules', [
            'name' => 'Create inbound sales ticket',
            'weight' => 20,
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

    private function emailAccountPayload(array $overrides = []): array
    {
        return array_merge([
            'address' => 'account@example.test',
            'description' => 'Test account',
            'from_name' => 'Test Account',
            'is_active' => '1',
            'is_global_default' => '0',
            'defaults_for' => [],
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'account@example.test',
            'imap_secret' => 'secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'account@example.test',
            'smtp_secret' => 'secret',
            'smtp_auth_type' => 'password',
        ], $overrides);
    }
}
