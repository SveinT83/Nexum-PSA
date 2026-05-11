<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Controllers\Admin\AccountsController;
use App\Modules\Email\Controllers\Admin\Templates\EmailTemplateController;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Controllers\Tech\InboxController;
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
}
