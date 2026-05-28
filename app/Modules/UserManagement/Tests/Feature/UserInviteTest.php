<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\UserManagement\Jobs\SendUserInviteEmail;
use App\Modules\UserManagement\Models\InviteToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserInviteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();

        Role::firstOrCreate(['name' => 'Admin']);
    }

    #[Test]
    public function creating_a_pending_user_sends_an_invite()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Admin');

        $this->actingAs($admin);

        $response = $this->post(route('tech.admin.user_management.store'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'role' => '',
            'status' => User::STATUS_PENDING,
        ]);

        $response->assertRedirect(route('tech.admin.user_management.index'));

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals(User::STATUS_PENDING, $user->status);

        // An invite token should exist
        $this->assertEquals(1, $user->inviteTokens()->count());
        $this->assertNotNull($user->inviteTokens()->first()->token);

        // Notification should have been sent
        Notification::assertSentTo($user, \App\Modules\UserManagement\Notifications\UserInvited::class);
        Queue::assertPushed(SendUserInviteEmail::class, fn (SendUserInviteEmail $job) => $job->inviteTokenId === $user->inviteTokens()->first()->id);
    }

    #[Test]
    public function admin_can_resend_invite_to_pending_user()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Admin');

        $pendingUser = User::factory()->create(['status' => User::STATUS_PENDING]);

        $this->actingAs($admin);

        $response = $this->post(route('tech.admin.user_management.invite.send', $pendingUser));

        $response->assertRedirect(route('tech.admin.user_management.index'));

        Notification::assertSentTo($pendingUser, \App\Modules\UserManagement\Notifications\UserInvited::class);
        Queue::assertPushed(SendUserInviteEmail::class);
    }

    #[Test]
    public function admin_cannot_send_invite_to_active_user()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Admin');

        $activeUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($admin);

        $response = $this->post(route('tech.admin.user_management.invite.send', $activeUser));

        $response->assertRedirect(route('tech.admin.user_management.index'));
        $response->assertSessionHas('error');

        Notification::assertNotSentTo($activeUser, \App\Modules\UserManagement\Notifications\UserInvited::class);
        Queue::assertNotPushed(SendUserInviteEmail::class);
    }

    #[Test]
    public function queued_invite_email_uses_system_template()
    {
        $pendingUser = User::factory()->create([
            'name' => 'Jane Template',
            'email' => 'jane-template@example.com',
            'status' => User::STATUS_PENDING,
        ]);
        $inviteToken = InviteToken::generateFor($pendingUser);

        EmailTemplate::create([
            'scope' => 'system',
            'key' => 'user_invite',
            'name' => 'User invitation',
            'subject' => 'Invite to {{ app_name }}',
            'body_html' => '<p>Hello {{ user_name }}</p><p>{{ invite_url }}</p>',
            'body_text' => "Hello {{ user_name }}\n{{ invite_url }}",
            'variables' => ['app_name', 'user_name', 'invite_url'],
            'is_default' => true,
            'is_active' => true,
        ]);

        EmailAccount::create([
            'address' => 'system@example.test',
            'from_name' => 'System',
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => ['system'],
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'system@example.test',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'system@example.test',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);

        app()->instance(SmtpAccountMailer::class, new class extends SmtpAccountMailer {
            public function send(\App\Modules\Email\Models\EmailAccount $account, string $toEmail, ?string $toName, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = []): string
            {
                app()->instance('user_invite_email_payload', compact('toEmail', 'toName', 'subject', 'html', 'text'));

                return '<user-invite@example.test>';
            }
        });

        app()->call([new SendUserInviteEmail($inviteToken->id), 'handle']);

        $payload = app('user_invite_email_payload');
        $this->assertSame('jane-template@example.com', $payload['toEmail']);
        $this->assertSame('Invite to '.config('app.name'), $payload['subject']);
        $this->assertStringContainsString('Jane Template', $payload['html']);
        $this->assertStringContainsString(route('invite.accept', ['token' => $inviteToken->token]), $payload['text']);
    }

    #[Test]
    public function user_can_accept_invite_and_set_password()
    {
        $pendingUser = User::factory()->create(['status' => User::STATUS_PENDING]);
        $inviteToken = InviteToken::generateFor($pendingUser);

        $response = $this->get(route('invite.accept', ['token' => $inviteToken->token]));
        $response->assertOk();
        $response->assertSee('Accept Your Invitation');

        $response = $this->post(route('invite.accept.post', ['token' => $inviteToken->token]), [
            'password' => 'NewSecureP@ss1',
            'password_confirmation' => 'NewSecureP@ss1',
        ]);

        $response->assertRedirect(route('tech.dashboard'));

        // User should now be active
        $pendingUser->refresh();
        $this->assertEquals(User::STATUS_ACTIVE, $pendingUser->status);
        $this->assertNotNull($pendingUser->email_verified_at);

        // Token should be used
        $inviteToken->refresh();
        $this->assertNotNull($inviteToken->used_at);

        // Password should be set
        $this->assertTrue(Hash::check('NewSecureP@ss1', $pendingUser->password));
    }

    #[Test]
    public function expired_invite_token_shows_expired_view()
    {
        $pendingUser = User::factory()->create(['status' => User::STATUS_PENDING]);
        $inviteToken = InviteToken::create([
            'user_id' => $pendingUser->id,
            'token' => 'expired-test-token',
            'expires_at' => now()->subHours(1),
        ]);

        $response = $this->get(route('invite.accept', ['token' => 'expired-test-token']));
        $response->assertOk();
        $response->assertSee('Invitation Expired');
    }

    #[Test]
    public function used_invite_token_is_rejected()
    {
        $pendingUser = User::factory()->create(['status' => User::STATUS_PENDING]);
        $inviteToken = InviteToken::create([
            'user_id' => $pendingUser->id,
            'token' => 'used-test-token',
            'expires_at' => now()->addHours(72),
            'used_at' => now()->subHour(),
        ]);

        $response = $this->get(route('invite.accept', ['token' => 'used-test-token']));
        $response->assertOk();
        $response->assertSee('Invitation Expired');
    }

    #[Test]
    public function invite_token_is_invalidated_when_new_one_is_generated()
    {
        $pendingUser = User::factory()->create(['status' => User::STATUS_PENDING]);
        $oldToken = InviteToken::generateFor($pendingUser);
        $newToken = InviteToken::generateFor($pendingUser);

        $oldToken->refresh();
        $this->assertNotNull($oldToken->used_at); // old one invalidated
        $this->assertNull($newToken->used_at);     // new one valid
        $this->assertTrue($newToken->isValid());
        $this->assertFalse($oldToken->isValid());
    }
}
