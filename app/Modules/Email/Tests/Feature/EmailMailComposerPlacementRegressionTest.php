<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\SendEmailComposerMessage;
use App\Modules\Email\Livewire\Tech\MailWorkspace;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\SmtpAccountMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailMailComposerPlacementRegressionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reply_reply_all_and_forward_send_the_selected_mailbox_placement(): void
    {
        Storage::fake('local');
        config()->set('email_live.enabled', false);
        config()->set('email_live.collaboration_enabled', false);
        Schema::dropIfExists('email_mail_draft_locks');

        $view = Permission::findOrCreate('email.inbox_view', 'web');
        $manage = Permission::findOrCreate('email.inbox_manage', 'web');
        $role = Role::create(['name' => 'Tech']);
        $role->givePermissionTo([$view, $manage]);

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole($role);

        $account = EmailAccount::query()->create([
            'address' => 'composer-placement@example.test',
            'description' => 'Composer placement regression mailbox',
            'from_name' => 'Composer Placement',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'composer-placement@example.test',
            'imap_secret' => 'test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'composer-placement@example.test',
            'smtp_secret' => 'test-secret',
            'smtp_auth_type' => 'password',
        ]);

        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => true,
            'can_send' => true,
            'granted_at' => now(),
        ]);

        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 812,
        ]);
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 8121,
            'message_id' => '<composer-placement-source@example.test>',
            'subject' => 'Composer placement regression',
            'from_name' => 'Customer',
            'from_email' => 'customer@example.test',
            'to_json' => [
                ['name' => 'Composer Placement', 'email' => $account->address],
                ['name' => 'Colleague', 'email' => 'colleague@example.test'],
            ],
            'cc_json' => [
                ['name' => 'Manager', 'email' => 'manager@example.test'],
            ],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Please respond to this message.',
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 812,
            'imap_uid' => 8121,
            'provider_seen' => false,
        ]);

        $mailer = new class extends SmtpAccountMailer
        {
            public array $calls = [];

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls[] = compact('account', 'toRecipients', 'subject', 'html', 'text', 'attachments', 'ccRecipients', 'options');

                return '<composer-placement-'.count($this->calls).'@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        $cases = [
            ['startReply', SendEmailComposerMessage::MODE_REPLY, null, 'Reply sent from '.$account->address.'.'],
            ['startReplyAll', SendEmailComposerMessage::MODE_REPLY_ALL, null, 'Reply all sent from '.$account->address.'.'],
            ['startForward', SendEmailComposerMessage::MODE_FORWARD, 'recipient@example.test', 'Forward sent from '.$account->address.'.'],
        ];

        foreach ($cases as [$startMethod, $mode, $recipient, $expectedStatus]) {
            $component = Livewire::actingAs($user)
                ->test(MailWorkspace::class)
                ->call('selectPlacement', $placement->id)
                ->call($startMethod)
                ->assertSet('composerMode', $mode)
                ->set('composerBodyHtml', '<p>Regression response.</p>');

            if ($recipient !== null) {
                $component->set('composerTo', $recipient);
            }

            $component
                ->call('sendComposer')
                ->assertHasNoErrors()
                ->assertSet('composerOpen', false)
                ->assertSee($expectedStatus);
        }

        $this->assertCount(3, $mailer->calls);
        $this->assertSame(
            [
                SendEmailComposerMessage::MODE_REPLY,
                SendEmailComposerMessage::MODE_REPLY_ALL,
                SendEmailComposerMessage::MODE_FORWARD,
            ],
            EmailLog::query()->orderBy('id')->get()
                ->pluck('context_json')
                ->map(fn (array $context): ?string => $context['mode'] ?? null)
                ->all(),
        );
    }
}
