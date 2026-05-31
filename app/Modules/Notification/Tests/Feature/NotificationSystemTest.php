<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Notification\Livewire\NotificationBell;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\TicketAssigned;
use App\Modules\Notification\Notifications\TicketStatusChanged;
use App\Modules\Notification\Notifications\TicketCommentAdded;
use App\Modules\Notification\Notifications\AssetAlertTriggered;
use App\Modules\Notification\Notifications\TicketSlaWarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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
