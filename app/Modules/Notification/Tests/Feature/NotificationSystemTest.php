<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\TicketAssigned;
use App\Modules\Notification\Notifications\TicketStatusChanged;
use App\Modules\Notification\Notifications\TicketCommentAdded;
use App\Modules\Notification\Notifications\AssetAlertTriggered;
use App\Modules\Notification\Notifications\TicketSlaWarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
        NotificationChannel::create([
            'name' => 'nextcloud_talk',
            'label' => 'Nextcloud Talk',
            'driver' => 'nextcloud_talk',
            'is_enabled' => false,
            'config' => ['base_url' => '', 'default_webhook_url' => ''],
        ]);
    }

    /** @test */
    public function user_can_view_notification_settings_page()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $response = $this->actingAs($user)
            ->get(route('tech.profile.notifications'));

        $response->assertOk();
        $response->assertSee('Notification Preferences');
    }

    /** @test */
    public function user_can_update_notification_preferences()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

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

    /** @test */
    public function notification_setting_returns_defaults_for_new_type()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $setting = NotificationSetting::getForUser($user, 'ticket_assigned');

        $this->assertTrue($setting->mail_enabled);
        $this->assertTrue($setting->database_enabled);
        $this->assertFalse($setting->nextcloud_talk_enabled);
    }

    /** @test */
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

    /** @test */
    public function admin_can_view_notification_channels()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('superadmin');
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'superadmin']);

        $response = $this->actingAs($admin)
            ->get(route('tech.admin.notification-channels.index'));

        $response->assertOk();
        $response->assertSee('Notification Channels');
    }

    /** @test */
    public function admin_can_enable_nextcloud_talk_channel()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('superadmin');
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'superadmin']);

        $channel = NotificationChannel::where('driver', 'nextcloud_talk')->first();

        $response = $this->actingAs($admin)
            ->put(route('tech.admin.notification-channels.update', $channel), [
                'is_enabled' => '1',
                'config' => [
                    'base_url' => 'https://cloud.example.com',
                    'default_webhook_url' => 'https://cloud.example.com/apps/webhook/abc123',
                ],
            ]);

        $response->assertRedirect();

        $channel->refresh();
        $this->assertTrue($channel->is_enabled);
        $this->assertEquals('https://cloud.example.com', $channel->config['base_url']);
    }

    /** @test */
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

    /** @test */
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