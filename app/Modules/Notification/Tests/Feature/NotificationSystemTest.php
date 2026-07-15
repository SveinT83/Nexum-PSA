<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Notification\Actions\SendTransactionalSms;
use App\Modules\Notification\Livewire\NotificationBell;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Models\NotificationSmsMessage;
use App\Modules\Notification\Models\NotificationSmsTemplate;
use App\Modules\Notification\Notifications\TicketAssigned;
use App\Modules\Notification\Notifications\TicketStatusChanged;
use App\Modules\Notification\Notifications\TicketCommentAdded;
use App\Modules\Notification\Notifications\AssetAlertTriggered;
use App\Modules\Notification\Notifications\TicketSlaWarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create the notifications table
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_05_16_140000_create_notifications_system.php']);

        // Seed the default notification channel
        NotificationChannel::updateOrCreate(
            ['name' => 'nextcloud_talk'],
            [
                'label' => 'Nextcloud Talk',
                'driver' => 'nextcloud_talk',
                'is_enabled' => false,
                'config' => ['default_webhook_url' => ''],
            ]
        );

        NotificationChannel::updateOrCreate(
            ['name' => 'sms'],
            [
                'label' => 'SMS',
                'driver' => 'sms',
                'is_enabled' => false,
                'config' => [
                    'provider' => 'dry_run',
                    'sender_name' => 'Nexum',
                    'default_country_code' => '+47',
                ],
            ]
        );

        NotificationSmsTemplate::query()->firstOrCreate(
            ['key' => 'sms_test'],
            [
                'name' => 'SMS test message',
                'body' => 'Test SMS from {{ company_name }} to {{ contact_name }}.',
                'variables' => ['company_name', 'contact_name'],
                'is_active' => true,
            ]
        );
    }

    #[Test]
    public function user_can_view_notification_settings_page()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'Tech']);
        $user->assignRole('Tech');

        $response = $this->actingAs($user)
            ->get(route('tech.profile.notifications'));

        $response->assertOk();
        $response->assertSee('Notification Preferences');
    }

    #[Test]
    public function user_can_update_notification_preferences()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'Tech']);
        $user->assignRole('Tech');

        $settings = [];
        foreach (NotificationSetting::TYPES as $type => $label) {
            $settings[] = [
                'notification_type' => $type,
                'mail_enabled' => $type === 'ticket_assigned',
                'database_enabled' => true,
                'nextcloud_talk_enabled' => false,
            ];
        }

        $response = $this->actingAs($user)
            ->post(route('tech.profile.notifications.update'), [
                'settings' => $settings,
            ]);

        $response->assertRedirect(route('tech.profile.notifications'));

        // Verify settings were saved
        $this->assertDatabaseHas('notification_settings', [
            'user_id' => $user->id,
            'notification_type' => 'ticket_assigned',
            'mail_enabled' => true,
        ]);
    }

    #[Test]
    public function notification_setting_returns_defaults_for_new_type()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $setting = NotificationSetting::getForUser($user, 'ticket_assigned');

        $this->assertTrue($setting->mail_enabled);
        $this->assertTrue($setting->database_enabled);
        $this->assertFalse($setting->nextcloud_talk_enabled);
    }

    #[Test]
    public function authenticated_api_user_can_list_and_mark_notifications_read()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $notificationId = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => 'test.notification',
            'notifiable_type' => $user::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'API notification', 'url' => '/tech']),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'test.notification',
            'notifiable_type' => $other::class,
            'notifiable_id' => $other->id,
            'data' => json_encode(['title' => 'Other notification']),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user, ['notifications.read', 'notifications.update']);

        $this->getJson(route('api.v1.notifications.index', ['unread' => true]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $notificationId)
            ->assertJsonPath('data.0.data.title', 'API notification');

        $this->postJson(route('api.v1.notifications.read', $notificationId))
            ->assertOk()
            ->assertJsonPath('data.id', $notificationId);

        $this->assertNotNull(DB::table('notifications')->where('id', $notificationId)->value('read_at'));

        DB::table('notifications')->where('id', $notificationId)->update(['read_at' => null]);

        $this->postJson(route('api.v1.notifications.read-all'))
            ->assertOk()
            ->assertJsonPath('data.updated', 1);
    }

    #[Test]
    public function notification_read_api_token_cannot_mark_notifications_read()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $notificationId = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => 'test.notification',
            'notifiable_type' => $user::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Read only notification']),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user, ['notifications.read']);

        $this->getJson(route('api.v1.notifications.index'))
            ->assertOk();

        $this->postJson(route('api.v1.notifications.read', $notificationId))
            ->assertForbidden();
    }

    #[Test]
    public function ticket_assigned_notification_sends_via_mail_and_database()
    {
        Notification::fake();

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $ticket = \App\Modules\Ticket\Models\Ticket::factory()->create([
            'owner_id' => $user->id,
            'subject' => 'Test ticket',
        ]);

        $notification = new TicketAssigned($ticket, 'Admin');
        $user->notify($notification);

        Notification::assertSentTo($user, TicketAssigned::class);
    }

    #[Test]
    public function admin_can_view_notification_channels()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'Admin']);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin)
            ->get(route('tech.admin.notification-channels.index'));

        $response->assertOk();
        $response->assertSee('Notification Channels');
    }

    #[Test]
    public function admin_can_enable_nextcloud_talk_channel()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'Admin']);
        $admin->assignRole('Admin');

        $connection = NextcloudConnection::create([
            'name' => 'Internal Nextcloud',
            'scope' => NextcloudConnection::SCOPE_GLOBAL,
            'mode' => NextcloudConnection::MODE_SYNC,
            'is_default' => true,
            'is_active' => true,
            'base_url' => 'https://cloud.example.com',
        ]);

        $channel = NotificationChannel::where('driver', 'nextcloud_talk')->first();

        $response = $this->actingAs($admin)
            ->put(route('tech.admin.notification-channels.update', $channel), [
                'is_enabled' => '1',
                'config' => [
                    'nextcloud_connection_id' => $connection->id,
                    'default_webhook_url' => 'https://cloud.example.com/apps/webhook/abc123',
                ],
                'secrets' => [
                    'api_token' => 'unused-token',
                ],
            ]);

        $response->assertRedirect();

        $channel->refresh();
        $this->assertTrue($channel->is_enabled);
        $this->assertEquals($connection->id, $channel->config['nextcloud_connection_id']);
        $this->assertEquals('https://cloud.example.com/apps/webhook/abc123', $channel->config['default_webhook_url']);
        $this->assertArrayNotHasKey('base_url', $channel->config);
        $this->assertArrayNotHasKey('api_token', $channel->secrets ?? []);
    }

    #[Test]
    public function nextcloud_talk_channel_cannot_be_enabled_without_nextcloud_integration()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'Admin']);
        $admin->assignRole('Admin');

        $channel = NotificationChannel::where('driver', 'nextcloud_talk')->first();

        $response = $this->actingAs($admin)
            ->put(route('tech.admin.notification-channels.update', $channel), [
                'is_enabled' => '1',
                'config' => [
                    'default_webhook_url' => 'https://cloud.example.com/apps/webhook/abc123',
                ],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('warning');

        $channel->refresh();
        $this->assertFalse($channel->is_enabled);
        $this->assertEquals('https://cloud.example.com/apps/webhook/abc123', $channel->config['default_webhook_url']);
    }

    #[Test]
    public function nextcloud_talk_channel_defaults_to_global_default_connection()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'Admin']);
        $admin->assignRole('Admin');

        $defaultConnection = NextcloudConnection::create([
            'name' => 'Default Nextcloud',
            'scope' => NextcloudConnection::SCOPE_GLOBAL,
            'mode' => NextcloudConnection::MODE_SYNC,
            'is_default' => true,
            'is_active' => true,
            'base_url' => 'https://default-cloud.example.com',
        ]);

        NextcloudConnection::create([
            'name' => 'Other Nextcloud',
            'scope' => NextcloudConnection::SCOPE_GLOBAL,
            'mode' => NextcloudConnection::MODE_SYNC,
            'is_default' => false,
            'is_active' => true,
            'base_url' => 'https://other-cloud.example.com',
        ]);

        $channel = NotificationChannel::where('driver', 'nextcloud_talk')->first();

        $response = $this->actingAs($admin)
            ->put(route('tech.admin.notification-channels.update', $channel), [
                'is_enabled' => '1',
                'config' => [
                    'default_webhook_url' => 'https://cloud.example.com/apps/webhook/abc123',
                ],
            ]);

        $response->assertRedirect();

        $channel->refresh();
        $this->assertTrue($channel->is_enabled);
        $this->assertEquals($defaultConnection->id, $channel->config['nextcloud_connection_id']);
    }

    #[Test]
    public function nextcloud_talk_edit_form_uses_nextcloud_integration_and_only_requests_webhook_url()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'Admin']);
        $admin->assignRole('Admin');

        NextcloudConnection::create([
            'name' => 'Internal Nextcloud',
            'scope' => NextcloudConnection::SCOPE_GLOBAL,
            'mode' => NextcloudConnection::MODE_SYNC,
            'is_default' => true,
            'is_active' => true,
            'base_url' => 'https://cloud.example.com',
        ]);

        $channel = NotificationChannel::where('driver', 'nextcloud_talk')->first();

        $response = $this->actingAs($admin)
            ->get(route('tech.admin.notification-channels.edit', $channel));

        $response->assertOk();
        $response->assertSee('Nextcloud Integration');
        $response->assertSee('Default Webhook URL');
        $response->assertSee('https://cloud.example.com');
        $response->assertDontSee('Nextcloud Base URL');
        $response->assertDontSee('API Token');
    }

    #[Test]
    public function admin_can_configure_sms_dry_run_channel(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'Admin']);
        $admin->assignRole('Admin');

        $channel = NotificationChannel::where('driver', 'sms')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('tech.admin.notification-channels.update', $channel), [
                'is_enabled' => '1',
                'config' => [
                    'provider' => 'dry_run',
                    'sender_name' => 'Nexum SMS',
                    'default_country_code' => '+47',
                ],
            ])
            ->assertRedirect(route('tech.admin.notification-channels.edit', $channel))
            ->assertSessionHas('success');

        $channel->refresh();

        $this->assertTrue($channel->is_enabled);
        $this->assertSame('dry_run', $channel->config['provider']);
        $this->assertSame('Nexum SMS', $channel->config['sender_name']);
        $this->assertSame('+47', $channel->config['default_country_code']);
        $this->assertNull($channel->secrets);
    }

    #[Test]
    public function admin_can_log_sms_dry_run_test_message(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'Admin']);
        $admin->assignRole('Admin');

        $channel = NotificationChannel::where('driver', 'sms')->firstOrFail();
        $channel->forceFill([
            'is_enabled' => true,
            'config' => [
                'provider' => 'dry_run',
                'sender_name' => 'Nexum',
                'default_country_code' => '+47',
            ],
        ])->save();

        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'SMS Test Contact',
        ]);
        $contact->phones()->create([
            'label' => 'mobile',
            'phone' => '99 88 77 66',
            'is_primary' => true,
            'sms_allowed' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('tech.admin.notification-channels.test', $channel), [
                'test_contact_id' => $contact->id,
                'test_template_key' => 'sms_test',
            ])
            ->assertRedirect(route('tech.admin.notification-channels.edit', $channel))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('notification_sms_messages', [
            'contact_id' => $contact->id,
            'status' => NotificationSmsMessage::STATUS_DRY_RUN,
            'provider' => 'dry_run',
            'normalized_recipient_phone' => '+4799887766',
            'source_type' => 'notification_channel_test',
        ]);

        $this->assertSame('OK', $channel->fresh()->last_test_result);
    }

    #[Test]
    public function transactional_sms_blocks_when_channel_is_disabled(): void
    {
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Blocked SMS Contact',
        ]);
        $contact->phones()->create([
            'label' => 'mobile',
            'phone' => '+47 11 22 33 44',
            'is_primary' => true,
            'sms_allowed' => true,
        ]);

        $message = app(SendTransactionalSms::class)->handle($contact, 'sms_test');

        $this->assertSame(NotificationSmsMessage::STATUS_BLOCKED, $message->status);
        $this->assertSame('SMS channel is disabled.', $message->failure_reason);
    }

    #[Test]
    public function transactional_sms_blocks_without_sms_consent(): void
    {
        NotificationChannel::where('driver', 'sms')->firstOrFail()
            ->forceFill([
                'is_enabled' => true,
                'config' => [
                    'provider' => 'dry_run',
                    'sender_name' => 'Nexum',
                    'default_country_code' => '+47',
                ],
            ])
            ->save();

        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'No Consent SMS Contact',
        ]);
        $contact->phones()->create([
            'label' => 'mobile',
            'phone' => '+47 22 33 44 55',
            'is_primary' => true,
            'sms_allowed' => false,
        ]);

        $message = app(SendTransactionalSms::class)->handle($contact, 'sms_test');

        $this->assertSame(NotificationSmsMessage::STATUS_BLOCKED, $message->status);
        $this->assertSame('Transactional SMS is not allowed for this phone number.', $message->failure_reason);
    }

    #[Test]
    public function transactional_sms_renders_template_variables_in_dry_run_log(): void
    {
        NotificationChannel::where('driver', 'sms')->firstOrFail()
            ->forceFill([
                'is_enabled' => true,
                'config' => [
                    'provider' => 'dry_run',
                    'sender_name' => 'Nexum',
                    'default_country_code' => '+47',
                ],
            ])
            ->save();

        NotificationSmsTemplate::query()->create([
            'key' => 'sms_custom',
            'name' => 'Custom SMS',
            'body' => 'Hei {{ contact_name }}, kode {{ code }}.',
            'variables' => ['contact_name', 'code'],
            'is_active' => true,
        ]);

        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Template Contact',
        ]);
        $contact->phones()->create([
            'label' => 'mobile',
            'phone' => '+47 33 44 55 66',
            'is_primary' => true,
            'sms_allowed' => true,
        ]);

        $message = app(SendTransactionalSms::class)->handle($contact, 'sms_custom', ['code' => '1234']);

        $this->assertSame(NotificationSmsMessage::STATUS_DRY_RUN, $message->status);
        $this->assertSame('Hei Template Contact, kode 1234.', $message->body);
        $this->assertSame('dry_run-'.$message->id, $message->provider_message_id);
    }

    #[Test]
    public function notification_uses_per_user_preferences()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        // Disable mail for ticket_status_changed
        NotificationSetting::create([
            'user_id' => $user->id,
            'notification_type' => 'ticket_status_changed',
            'mail_enabled' => false,
            'database_enabled' => true,
            'nextcloud_talk_enabled' => false,
        ]);

        $notification = new TicketStatusChanged(
            ticket: \App\Modules\Ticket\Models\Ticket::factory()->create(['subject' => 'Test']),
            oldStatus: 'open',
            newStatus: 'in_progress',
            changedBy: 'Admin',
        );

        $via = $notification->via($user);

        $this->assertContains('database', $via);
        $this->assertNotContains('mail', $via);
    }

    #[Test]
    public function notification_bell_item_redirects_to_notification_url_when_opened()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'Tech']);
        $user->assignRole('Tech');
        $notificationId = (string) Str::uuid();
        $url = route('tech.tickets.index');

        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => 'database',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'type' => 'ticket_assigned',
                'ticket_subject' => 'Clickable notification',
                'url' => $url,
            ]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(NotificationBell::class)
            ->call('openNotification', $notificationId)
            ->assertRedirect($url);

        $this->assertNotNull(DB::table('notifications')->where('id', $notificationId)->value('read_at'));
    }

    #[Test]
    public function notification_bell_uses_bootstrap_dropdown_click_target()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Livewire::actingAs($user)
            ->test(NotificationBell::class)
            ->assertSeeHtml('class="dropdown notification-bell"')
            ->assertSeeHtml('data-bs-toggle="dropdown"')
            ->assertSeeHtml('aria-label="Open notifications"');
    }

    #[Test]
    public function notification_channel_secrets_are_encrypted()
    {
        $channel = NotificationChannel::where('driver', 'nextcloud_talk')->first();

        $channel->setSecret('api_token', 'super-secret-token-12345');
        $channel->save();

        // The stored value should be encrypted (not plaintext)
        $raw = \DB::table('notification_channels')->where('id', $channel->id)->first();
        $secrets = json_decode($raw->secrets, true);
        $this->assertNotEquals('super-secret-token-12345', $secrets['api_token']);

        // But getSecret should decrypt correctly
        $channel->refresh();
        $this->assertEquals('super-secret-token-12345', $channel->getSecret('api_token'));
    }
}
