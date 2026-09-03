<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Models\System\Integrations\Integration;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\Email\Actions\AssistEmailComposerWithAi;
use App\Modules\Email\Actions\CreatePersonalEmailRule;
use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Actions\RecordEmailRemoteOperation;
use App\Modules\Email\Actions\SendEmailComposerMessage;
use App\Modules\Email\Actions\SendEmailReply;
use App\Modules\Email\Actions\UpdateEmailConversationClassification;
use App\Modules\Email\Controllers\Admin\AccountsController;
use App\Modules\Email\Controllers\Admin\Templates\EmailTemplateController;
use App\Modules\Email\Controllers\Tech\InboxController;
use App\Modules\Email\Controllers\Tech\MailController;
use App\Modules\Email\Controllers\Tech\SignatureController;
use App\Modules\Email\Jobs\AppendEmailProviderSentCopy;
use App\Modules\Email\Jobs\EmailAccountHealthCheckJob;
use App\Modules\Email\Jobs\FetchImapAccount;
use App\Modules\Email\Jobs\PollActiveEmailAccounts;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Jobs\StoreInboundMessage;
use App\Modules\Email\Jobs\TestEmailAccountConnectionJob;
use App\Modules\Email\Livewire\Tech\MailSidebar;
use App\Modules\Email\Livewire\Tech\MailWorkspace;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailComposerDraftAttachment;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailConversationClassification;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailHealthCheck;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Models\EmailRuleLog;
use App\Modules\Email\Models\EmailRuleVersion;
use App\Modules\Email\Models\EmailSentReconciliation;
use App\Modules\Email\Models\EmailSignature;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Models\EmailTicketCorrelationConflict;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailSentReconciliationService;
use App\Modules\Email\Services\EmailSignatureRenderer;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\EmailTestResult;
use App\Modules\Email\Services\EmailTestService;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Email\Services\HtmlSanitizer;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Services\MailAiAgentRuntime;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Email\Support\InboundAttachmentPolicy;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Services\ActivateStandardAiRuntime;
use App\Modules\Integration\Services\EmailProviderCredentialCipher;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Webklex\PHPIMAP\Client as WebklexImapClient;
use Webklex\PHPIMAP\Folder as WebklexFolder;

class EmailModuleTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_MAIL_AI_WORKLOAD_SETTING = 'mail_ai_workload_profile_id';

    private User $tech;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Outbound reconciliation writes RFC snapshots after SMTP. Keep the
        // broad module suite isolated from the shared Dev private-mail tree.
        Storage::fake('local');

        $permissions = collect([
            'email.inbox_view',
            'email.inbox_manage',
            'email.account_manage',
            'email.rule_manage',
            'email.rule_publish',
            'email.rule_reprocess',
            'email.template_manage',
            'email.mailbox_sync_manage',
            'integration.email_provider_manage',
            'system.settings_manage',
        ])->mapWithKeys(
            fn (string $name): array => [$name => Permission::findOrCreate($name, 'web')],
        );

        Role::create(['name' => 'Tech'])->givePermissionTo([
            $permissions['email.inbox_view'],
            $permissions['email.inbox_manage'],
        ]);
        Role::create(['name' => 'Admin'])->givePermissionTo([
            $permissions['email.account_manage'],
            $permissions['email.rule_manage'],
            $permissions['email.rule_publish'],
            $permissions['email.rule_reprocess'],
            $permissions['email.template_manage'],
            $permissions['email.mailbox_sync_manage'],
            $permissions['integration.email_provider_manage'],
            $permissions['system.settings_manage'],
        ]);

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function tech_user_can_open_inbox_from_email_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.inbox.index');

        $this->assertSame(InboxController::class.'@index', $route->getActionName());
        $this->assertSame(InboxController::class.'@markSpam', Route::getRoutes()->getByName('tech.inbox.spam')->getActionName());
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
        $this->grantMailbox($account, $this->tech);
        $message = EmailMessage::create([
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
        $this->activeProviderOccurrence($message);

        $this->actingAs($this->tech)
            ->get(route('tech.inbox.index'))
            ->assertOk()
            ->assertViewIs('email::Tech.index')
            ->assertViewHas('messages')
            ->assertSee('inbox-index-row')
            ->assertSee('Mark as spam');
    }

    #[Test]
    public function tech_user_can_open_mail_workspace_with_authorized_provider_placements(): void
    {
        $route = Route::getRoutes()->getByName('tech.mail.index');

        $this->assertSame(MailController::class.'@index', $route->getActionName());

        $visibleAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'visible-mail@example.test',
        ]));
        $privateAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'private-mail@example.test',
            'imap_username' => 'private-mail@example.test',
            'smtp_username' => 'private-mail@example.test',
        ]));
        $this->grantMailbox($visibleAccount, $this->tech);

        $visibleFolder = EmailFolder::create([
            'account_id' => $visibleAccount->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 101,
        ]);
        $privateFolder = EmailFolder::create([
            'account_id' => $privateAccount->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 202,
        ]);

        $visibleMessage = EmailMessage::create([
            'account_id' => $visibleAccount->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 1001,
            'message_id' => '<visible-mail@example.test>',
            'subject' => 'Visible mail workspace message',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'This authorized message should render in Mail.',
        ]);
        $privateMessage = EmailMessage::create([
            'account_id' => $privateAccount->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 2001,
            'message_id' => '<private-mail@example.test>',
            'subject' => 'Private mail workspace message',
            'from_email' => 'private@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'This private message must not render.',
        ]);

        EmailMailboxPlacement::create([
            'email_message_id' => $visibleMessage->id,
            'account_id' => $visibleAccount->id,
            'email_folder_id' => $visibleFolder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 101,
            'imap_uid' => 1001,
            'provider_seen' => false,
        ]);
        EmailMailboxPlacement::create([
            'email_message_id' => $privateMessage->id,
            'account_id' => $privateAccount->id,
            'email_folder_id' => $privateFolder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 202,
            'imap_uid' => 2001,
            'provider_seen' => false,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.mail.index'))
            ->assertOk()
            ->assertViewIs('email::Tech.mail')
            ->assertSeeLivewire('tech.mail.sidebar')
            ->assertSeeLivewire('tech.mail.workspace')
            ->assertSee('Work workspace')
            ->assertSee('Mailboxes')
            ->assertSee('Folders')
            ->assertSee('Visible mail workspace message')
            ->assertSee('Mailbox unread')
            ->assertDontSee('Private mail workspace message');
    }

    #[Test]
    public function technician_can_manage_mail_signature_from_profile_and_mail_rightbar(): void
    {
        $this->assertSame(SignatureController::class.'@update', Route::getRoutes()->getByName('tech.mail.signature.update')->getActionName());

        UserProfile::query()->updateOrCreate(
            ['user_id' => $this->tech->id],
            [
                'work_phone' => '+47 73501010',
                'timezone' => 'Europe/Oslo',
                'working_hours' => [],
            ],
        );

        $this->actingAs($this->tech)
            ->get(route('tech.profile.index'))
            ->assertOk()
            ->assertSee('Email signature')
            ->assertSee('Signature HTML')
            ->assertSee('{user.name}')
            ->assertSee('+47 73501010');

        $this->actingAs($this->tech)
            ->patch(route('tech.mail.signature.update'), [
                'signature_name' => 'Customer support',
                'signature_body_html' => '<p>Regards<br>{user.name}</p><script>alert(1)</script>',
                'use_on_compose' => '1',
                'use_on_reply' => '1',
                'use_on_reply_all' => '0',
                'use_on_forward' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Mail signature updated.');

        $signature = EmailSignature::query()->where('user_id', $this->tech->id)->sole();

        $this->assertSame('Customer support', $signature->name);
        $this->assertStringContainsString('Regards', (string) $signature->body_html);
        $this->assertStringNotContainsString('<script', (string) $signature->body_html);
        $this->assertTrue($signature->use_on_compose);
        $this->assertTrue($signature->use_on_reply);
        $this->assertFalse($signature->use_on_reply_all);
        $this->assertFalse($signature->use_on_forward);

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'signature-sidebar@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $mailResponse = $this->actingAs($this->tech)
            ->get(route('tech.mail.index'))
            ->assertOk()
            ->assertSee('Mail AI')
            ->assertSee('Mail signature')
            ->assertSee('Customer support')
            ->assertSeeHtml('id="mailbox-operations-rightbar-slot"')
            ->assertSeeHtml('id="mail-signature-rightbar-heading"')
            ->assertSeeHtml('data-bs-target="#mail-signature-rightbar-body"')
            ->assertSeeHtml('id="mail-signature-rightbar-body" class="collapse"')
            ->assertSeeHtml('id="mail-signature-modal-trigger"')
            ->assertSeeHtml('data-bs-toggle="modal"')
            ->assertSeeHtml('data-bs-target="#mail-signature-settings-modal"')
            ->assertSeeHtml('id="mail-signature-settings-modal"')
            ->assertSeeHtml('aria-labelledby="mail-signature-settings-modal-title"')
            ->assertSeeHtml('modal-dialog-scrollable modal-fullscreen-sm-down')
            ->assertSeeHtml('class="modal-content text-body"')
            ->assertSeeHtml('class="btn-close" data-bs-dismiss="modal" aria-label="Close"')
            ->assertSeeHtml('data-bs-dismiss="modal">Cancel</button>')
            ->assertSee('mail-ai-runtime-rightbar-body')
            ->assertSee('Edit body')
            ->assertDontSeeHtml('id="mail-signature-rightbar-body" class="collapse show"');

        $mailHtml = $mailResponse->getContent();
        $signatureModalPosition = strpos($mailHtml, 'id="mail-signature-settings-modal"');
        $footerPosition = strpos($mailHtml, '<footer');

        $this->assertNotFalse($signatureModalPosition);
        $this->assertNotFalse($footerPosition);
        $this->assertSame(1, substr_count($mailHtml, 'id="mail-signature-settings-modal"'));
        $this->assertSame(1, substr_count($mailHtml, 'id="mail-signature-settings-modal-title"'));
        $this->assertSame(1, substr_count($mailHtml, 'id="mail-signature-rightbar-body"'));
        $this->assertLessThan($footerPosition, $signatureModalPosition);
        $this->assertLessThan(
            strpos($mailHtml, 'Mail AI runtime status'),
            strpos($mailHtml, 'Mail-owned signature status and modal trigger'),
        );

        $this->actingAs($this->tech)
            ->patch(route('tech.mail.signature.update'), [
                'signature_name' => 'Customer support',
                'use_on_compose' => '1',
                'use_on_reply' => '0',
                'use_on_reply_all' => '0',
                'use_on_forward' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Mail signature updated.');

        $signature->refresh();

        $this->assertStringContainsString('Regards', (string) $signature->body_html);
        $this->assertTrue($signature->use_on_compose);
        $this->assertFalse($signature->use_on_reply);
        $this->assertFalse($signature->use_on_reply_all);
        $this->assertTrue($signature->use_on_forward);
    }

    #[Test]
    public function mail_workspace_saves_and_restores_new_compose_draft(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-compose-draft@example.test',
            'from_name' => 'Workspace Compose Draft',
        ]));
        $this->grantMailbox($account, $this->tech, canView: false, canOrganize: false, canSend: true);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->assertSet('composerMode', SendEmailComposerMessage::MODE_COMPOSE)
            ->set('composerTo', 'customer@example.test')
            ->set('composerCc', 'manager@example.test')
            ->set('composerSubject', 'Drafted planned work')
            ->set('composerBodyHtml', '<p><strong>Draft</strong> body.</p><script>alert(1)</script>')
            ->call('saveComposerDraft')
            ->assertSet('composerDraftStatus', 'saved')
            ->assertSet('mailActionStatus', null)
            ->assertSet('composerActionStatus.type', 'warning')
            ->assertSee('Draft saved');

        $draft = EmailComposerDraft::query()->where('user_id', $this->tech->id)->sole();

        $this->assertSame(EmailComposerDraft::STATUS_ACTIVE, $draft->status);
        $this->assertSame(SendEmailComposerMessage::MODE_COMPOSE, $draft->mode);
        $this->assertSame($account->id, $draft->email_account_id);
        $this->assertSame('customer@example.test', $draft->to_recipients);
        $this->assertSame('manager@example.test', $draft->cc_recipients);
        $this->assertSame('Drafted planned work', $draft->subject);
        $this->assertStringContainsString('<strong>Draft</strong> body.', (string) $draft->body_html);
        $this->assertStringNotContainsString('<script', (string) $draft->body_html);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->assertSet('composerDraftStatus', 'restored')
            ->assertSet('composerTo', 'customer@example.test')
            ->assertSet('composerCc', 'manager@example.test')
            ->assertSet('composerSubject', 'Drafted planned work')
            ->assertSet('composerAttachments', [])
            ->assertSet('mailActionStatus', null)
            ->assertSet('composerActionStatus.type', 'info')
            ->assertSee('Draft restored');
    }

    #[Test]
    public function mail_workspace_restores_reply_draft_and_marks_it_sent_after_smtp_success(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-reply-draft@example.test',
            'from_name' => 'Workspace Reply Draft',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 661,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6611,
            'message_id' => '<reply-draft-source@example.test>',
            'subject' => 'Need a drafted response',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => 'workspace-reply-draft@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Can you draft this?',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 661,
            'imap_uid' => 6611,
            'provider_seen' => false,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->call('startReply')
            ->set('composerBodyHtml', '<p>Drafted reply content.</p>')
            ->call('saveComposerDraft')
            ->assertSet('composerDraftStatus', 'saved');

        $draft = EmailComposerDraft::query()->where('user_id', $this->tech->id)->sole();

        $this->assertSame(EmailComposerDraft::STATUS_ACTIVE, $draft->status);
        $this->assertSame($placement->id, $draft->email_mailbox_placement_id);

        $mailer = new class extends SmtpAccountMailer
        {
            public array $calls = [];

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls[] = compact('account', 'toRecipients', 'subject', 'html', 'text', 'attachments', 'ccRecipients', 'options');

                return '<reply-draft-sent@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->call('startReply')
            ->assertSet('composerDraftStatus', 'restored')
            ->assertSet('composerBodyHtml', '<p>Drafted reply content.</p>')
            ->call('sendComposer')
            ->assertSee('Reply sent from workspace-reply-draft@example.test.');

        $this->assertCount(1, $mailer->calls);
        $this->assertStringContainsString('Drafted reply content.', $mailer->calls[0]['html']);

        $draft->refresh();

        $this->assertSame(EmailComposerDraft::STATUS_SENT, $draft->status);
        $this->assertNotNull($draft->sent_at);
    }

    #[Test]
    public function mail_workspace_discarded_forward_draft_does_not_restore(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-forward-draft@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 662,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6621,
            'message_id' => '<forward-draft-source@example.test>',
            'subject' => 'Forward draft source',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => 'workspace-forward-draft@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Forward this later.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 662,
            'imap_uid' => 6621,
            'provider_seen' => false,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->call('startForward')
            ->set('composerBodyHtml', '<p>Forward draft intro.</p>')
            ->call('saveComposerDraft')
            ->assertSet('composerDraftStatus', 'saved')
            ->call('discardComposerDraft')
            ->assertSet('composerOpen', false)
            ->assertSee('Draft discarded.');

        $draft = EmailComposerDraft::query()->where('user_id', $this->tech->id)->sole();

        $this->assertSame(EmailComposerDraft::STATUS_DISCARDED, $draft->status);

        $component = Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->call('startForward')
            ->assertSet('composerDraftStatus', '');

        $this->assertStringNotContainsString('Forward draft intro.', (string) $component->get('composerBodyHtml'));
        $this->assertStringContainsString('Forwarded message', (string) $component->get('composerBodyHtml'));
    }

    #[Test]
    public function mail_workspace_closing_untouched_forward_does_not_create_local_draft(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-untouched-forward@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 663,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6631,
            'message_id' => '<untouched-forward-source@example.test>',
            'subject' => 'Untouched forward source',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => 'workspace-untouched-forward@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Default forwarded content only.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 663,
            'imap_uid' => 6631,
            'provider_seen' => false,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->call('startForward')
            ->assertSet('composerDraftStatus', '')
            ->call('cancelComposer')
            ->assertSet('composerOpen', false);

        $this->assertDatabaseCount('email_composer_drafts', 0);
    }

    #[Test]
    public function mail_workspace_manual_save_syncs_compose_draft_to_provider_drafts(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-provider-draft@example.test',
            'from_name' => 'Provider Draft Sender',
        ]));
        $this->grantMailbox($account, $this->tech, canView: false, canOrganize: false, canSend: true);

        EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Drafts',
            'name' => 'Drafts',
            'role' => EmailFolder::ROLE_DRAFTS,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 770,
        ]);

        $fakeClient = new class($account) extends ImapClient
        {
            public array $appended = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function appendDraft(string $folderPath, string $message): array
            {
                $this->appended[] = compact('folderPath', 'message');

                return [
                    'ok' => true,
                    'folder_path' => $folderPath,
                    'imap_uid_validity' => 770,
                    'imap_uid' => 771,
                    'response' => ['OK Append completed'],
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $fakeClient);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->set('composerTo', 'customer@example.test')
            ->set('composerCc', 'manager@example.test')
            ->set('composerSubject', 'Provider draft sync')
            ->set('composerBodyHtml', '<p>Provider <strong>Drafts</strong> body.</p>')
            ->call('saveComposerDraft')
            ->assertSet('composerDraftStatus', 'saved')
            ->assertSet('composerDraftProviderStatus', EmailComposerDraft::PROVIDER_DRAFT_SYNCED)
            ->assertSet('mailActionStatus', null)
            ->assertSet('composerActionStatus.type', 'success')
            ->assertSee('Draft saved and synced to provider Drafts.')
            ->assertSee('Provider Drafts synced');

        $draft = EmailComposerDraft::query()->where('user_id', $this->tech->id)->sole();

        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_SYNCED, $draft->provider_draft_status);
        $this->assertSame('Drafts', $draft->provider_draft_folder_path);
        $this->assertSame(770, $draft->provider_draft_uid_validity);
        $this->assertSame(771, $draft->provider_draft_uid);
        $this->assertStringStartsWith('<nexum-draft-', (string) $draft->provider_draft_message_id);
        $this->assertSame(trim((string) $draft->provider_draft_message_id, '<>'), $draft->provider_draft_normalized_message_id);

        $this->assertCount(1, $fakeClient->appended);
        $this->assertSame('Drafts', $fakeClient->appended[0]['folderPath']);
        $raw = $fakeClient->appended[0]['message'];
        $this->assertStringContainsString('X-Unsent: 1', $raw);
        $this->assertStringContainsString('Message-ID: '.$draft->provider_draft_message_id, $raw);
        $this->assertStringContainsString('Provider draft sync', $raw);
        $this->assertStringContainsString('customer@example.test', $raw);
        $this->assertStringContainsString('manager@example.test', $raw);
        $this->assertStringContainsString('Provider <strong>Drafts</strong> body.', $raw);
    }

    #[Test]
    public function mail_workspace_persists_restores_sends_and_cleans_durable_draft_attachment(): void
    {
        Storage::fake('local');

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-durable-draft-attachment@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: false, canOrganize: false, canSend: true);

        EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Drafts',
            'name' => 'Drafts',
            'role' => EmailFolder::ROLE_DRAFTS,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 775,
        ]);

        $fakeClient = new class($account) extends ImapClient
        {
            public array $appended = [];

            public array $deleted = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function appendDraft(string $folderPath, string $message): array
            {
                $this->appended[] = compact('folderPath', 'message');

                return [
                    'ok' => true,
                    'folder_path' => $folderPath,
                    'imap_uid_validity' => 775,
                    'imap_uid' => 776,
                    'response' => ['OK Append completed'],
                ];
            }

            public function folderState(string $folderPath): array
            {
                return [
                    'uid_validity' => 775,
                    'next_uid' => 777,
                    'exists_count' => null,
                    'unseen_count' => null,
                    'highest_modseq' => null,
                ];
            }

            public function deleteByUid(int $uid, string $folderPath = 'INBOX'): bool
            {
                $this->deleted[] = compact('uid', 'folderPath');

                return true;
            }
        };
        $this->app->bind(ImapClient::class, fn () => $fakeClient);

        $attachment = UploadedFile::fake()->createWithContent('draft-design.txt', 'draft attachment body');

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->set('composerTo', 'customer@example.test')
            ->set('composerSubject', 'Durable draft attachment')
            ->set('composerBodyHtml', '<p>Draft with attachment.</p>')
            ->set('composerAttachments', [$attachment])
            ->call('saveComposerDraft')
            ->assertSet('composerAttachments', [])
            ->assertSet('composerDraftProviderStatus', EmailComposerDraft::PROVIDER_DRAFT_SYNCED)
            ->assertSet('mailActionStatus', null)
            ->assertSet('composerActionStatus.type', 'success')
            ->assertSee('draft-design.txt')
            ->assertSee('Draft saved and synced to provider Drafts.');

        $draft = EmailComposerDraft::query()->with('attachments')->where('user_id', $this->tech->id)->sole();
        $storedAttachment = $draft->attachments->sole();

        $this->assertSame('draft-design.txt', $storedAttachment->filename);
        $this->assertSame(strlen('draft attachment body'), $storedAttachment->size_bytes);
        Storage::disk('local')->assertExists($storedAttachment->path);
        $this->assertCount(1, $fakeClient->appended);
        $this->assertStringContainsString('draft-design.txt', $fakeClient->appended[0]['message']);

        $mailer = new class extends SmtpAccountMailer
        {
            public array $calls = [];

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls[] = compact('account', 'toRecipients', 'subject', 'html', 'text', 'attachments', 'ccRecipients', 'options');

                return '<durable-draft-attachment-sent@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->assertSet('composerDraftStatus', 'restored')
            ->assertSee('draft-design.txt')
            ->call('sendComposer')
            ->assertSee('Message sent from workspace-durable-draft-attachment@example.test.');

        $this->assertCount(1, $mailer->calls);
        $this->assertSame('draft-design.txt', $mailer->calls[0]['attachments'][0]['filename']);

        $draft->refresh();

        $this->assertSame(EmailComposerDraft::STATUS_SENT, $draft->status);
        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_DELETED, $draft->provider_draft_status);
        $this->assertSame([['uid' => 776, 'folderPath' => 'Drafts']], $fakeClient->deleted);
        $this->assertDatabaseCount((new EmailComposerDraftAttachment)->getTable(), 0);
        Storage::disk('local')->assertMissing($storedAttachment->path);
    }

    #[Test]
    public function mail_workspace_autosave_keeps_compose_draft_local_without_provider_append(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-provider-autosave@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: false, canOrganize: false, canSend: true);

        EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Drafts',
            'name' => 'Drafts',
            'role' => EmailFolder::ROLE_DRAFTS,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 780,
        ]);

        $fakeClient = new class($account) extends ImapClient
        {
            public array $appended = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function appendDraft(string $folderPath, string $message): array
            {
                $this->appended[] = compact('folderPath', 'message');

                return [
                    'ok' => true,
                    'folder_path' => $folderPath,
                    'imap_uid_validity' => 780,
                    'imap_uid' => 781,
                    'response' => ['OK Append completed'],
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $fakeClient);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->set('composerTo', 'customer@example.test')
            ->set('composerSubject', 'Autosaved local draft')
            ->set('composerBodyHtml', '<p>Autosaved body.</p>')
            ->call('saveComposerDraft', false)
            ->assertSet('composerDraftStatus', 'saved')
            ->assertSet('composerDraftProviderStatus', EmailComposerDraft::PROVIDER_DRAFT_LOCAL_ONLY);

        $draft = EmailComposerDraft::query()->where('user_id', $this->tech->id)->sole();

        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_LOCAL_ONLY, $draft->provider_draft_status);
        $this->assertNull($draft->provider_draft_uid);
        $this->assertCount(0, $fakeClient->appended);
    }

    #[Test]
    public function mail_workspace_discard_deletes_synced_provider_draft_copy(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-provider-discard@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: false, canOrganize: false, canSend: true);

        EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Drafts',
            'name' => 'Drafts',
            'role' => EmailFolder::ROLE_DRAFTS,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 790,
        ]);

        $draft = EmailComposerDraft::create([
            'user_id' => $this->tech->id,
            'email_account_id' => $account->id,
            'provider_binding_version' => 1,
            'mode' => SendEmailComposerMessage::MODE_COMPOSE,
            'draft_key' => 'compose:account:'.$account->id,
            'status' => EmailComposerDraft::STATUS_ACTIVE,
            'to_recipients' => 'customer@example.test',
            'subject' => 'Discard provider draft',
            'body_html' => '<p>Discard body.</p>',
            'body_text' => 'Discard body.',
            'idempotency_key' => (string) Str::uuid(),
            'last_saved_at' => now(),
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_SYNCED,
            'provider_draft_folder_path' => 'Drafts',
            'provider_draft_uid_validity' => 790,
            'provider_draft_uid' => 791,
            'provider_draft_message_id' => '<discard-provider-draft@example.test>',
            'provider_draft_normalized_message_id' => 'discard-provider-draft@example.test',
            'provider_draft_synced_at' => now(),
        ]);

        $fakeClient = new class($account) extends ImapClient
        {
            public array $deleted = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function folderState(string $folderPath): array
            {
                return [
                    'uid_validity' => 790,
                    'next_uid' => 792,
                    'exists_count' => null,
                    'unseen_count' => null,
                    'highest_modseq' => null,
                ];
            }

            public function deleteByUid(int $uid, string $folderPath = 'INBOX'): bool
            {
                $this->deleted[] = compact('uid', 'folderPath');

                return true;
            }
        };
        $this->app->bind(ImapClient::class, fn () => $fakeClient);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->assertSet('composerDraftStatus', 'restored')
            ->call('discardComposerDraft')
            ->assertSet('composerOpen', false)
            ->assertSee('Draft discarded.');

        $draft->refresh();

        $this->assertSame(EmailComposerDraft::STATUS_DISCARDED, $draft->status);
        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_DELETED, $draft->provider_draft_status);
        $this->assertNotNull($draft->provider_draft_deleted_at);
        $this->assertSame([['uid' => 791, 'folderPath' => 'Drafts']], $fakeClient->deleted);
    }

    #[Test]
    public function mail_workspace_send_deletes_synced_provider_draft_copy_after_smtp_success(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-provider-send@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: false, canOrganize: false, canSend: true);

        EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Drafts',
            'name' => 'Drafts',
            'role' => EmailFolder::ROLE_DRAFTS,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 800,
        ]);

        $draft = EmailComposerDraft::create([
            'user_id' => $this->tech->id,
            'email_account_id' => $account->id,
            'provider_binding_version' => 1,
            'mode' => SendEmailComposerMessage::MODE_COMPOSE,
            'draft_key' => 'compose:account:'.$account->id,
            'status' => EmailComposerDraft::STATUS_ACTIVE,
            'to_recipients' => 'customer@example.test',
            'subject' => 'Send provider draft',
            'body_html' => '<p>Send body.</p>',
            'body_text' => 'Send body.',
            'idempotency_key' => (string) Str::uuid(),
            'last_saved_at' => now(),
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_SYNCED,
            'provider_draft_folder_path' => 'Drafts',
            'provider_draft_uid_validity' => 800,
            'provider_draft_uid' => 801,
            'provider_draft_message_id' => '<send-provider-draft@example.test>',
            'provider_draft_normalized_message_id' => 'send-provider-draft@example.test',
            'provider_draft_synced_at' => now(),
        ]);

        $fakeClient = new class($account) extends ImapClient
        {
            public array $deleted = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function folderState(string $folderPath): array
            {
                return [
                    'uid_validity' => 800,
                    'next_uid' => 802,
                    'exists_count' => null,
                    'unseen_count' => null,
                    'highest_modseq' => null,
                ];
            }

            public function deleteByUid(int $uid, string $folderPath = 'INBOX'): bool
            {
                $this->deleted[] = compact('uid', 'folderPath');

                return true;
            }
        };
        $this->app->bind(ImapClient::class, fn () => $fakeClient);

        $mailer = new class extends SmtpAccountMailer
        {
            public array $calls = [];

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls[] = compact('account', 'toRecipients', 'subject', 'html', 'text', 'attachments', 'ccRecipients', 'options');

                return '<provider-draft-sent@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->assertSet('composerDraftStatus', 'restored')
            ->call('sendComposer')
            ->assertSet('composerOpen', false)
            ->assertSee('Message sent from workspace-provider-send@example.test.');

        $draft->refresh();

        $this->assertCount(1, $mailer->calls);
        $this->assertSame(EmailComposerDraft::STATUS_SENT, $draft->status);
        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_DELETED, $draft->provider_draft_status);
        $this->assertNotNull($draft->provider_draft_deleted_at);
        $this->assertSame([['uid' => 801, 'folderPath' => 'Drafts']], $fakeClient->deleted);
    }

    #[Test]
    public function mail_workspace_edits_and_sends_provider_drafts_placement_directly(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-provider-draft-direct@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: false, canSend: true);

        $drafts = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Drafts',
            'name' => 'Drafts',
            'role' => EmailFolder::ROLE_DRAFTS,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 805,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'Drafts',
            'imap_uid' => 806,
            'message_id' => '<provider-draft-direct@example.test>',
            'subject' => 'Stored provider draft',
            'from_email' => $account->address,
            'to_json' => [['email' => 'customer@example.test']],
            'cc_json' => [['email' => 'manager@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_html_sanitized' => '<p>Original draft body.</p>',
            'body_text' => 'Original draft body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $drafts->id,
            'folder_path' => 'Drafts',
            'imap_uid_validity' => 805,
            'imap_uid' => 806,
            'provider_seen' => true,
            'provider_draft' => true,
        ]);

        $fakeClient = new class($account) extends ImapClient
        {
            public array $deleted = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function folderState(string $folderPath): array
            {
                return [
                    'uid_validity' => 805,
                    'next_uid' => 807,
                    'exists_count' => null,
                    'unseen_count' => null,
                    'highest_modseq' => null,
                ];
            }

            public function deleteByUid(int $uid, string $folderPath = 'INBOX'): bool
            {
                $this->deleted[] = compact('uid', 'folderPath');

                return true;
            }
        };
        $this->app->bind(ImapClient::class, fn () => $fakeClient);

        $mailer = new class extends SmtpAccountMailer
        {
            public array $calls = [];

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls[] = compact('account', 'toRecipients', 'subject', 'html', 'text', 'attachments', 'ccRecipients', 'options');

                return '<provider-draft-direct-sent@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertSee('Edit draft')
            ->call('editProviderDraft')
            ->assertSet('composerMode', SendEmailComposerMessage::MODE_PROVIDER_DRAFT)
            ->assertSet('composerTo', 'customer@example.test')
            ->assertSet('composerCc', 'manager@example.test')
            ->set('composerSubject', 'Updated provider draft')
            ->set('composerBodyHtml', '<p>Updated draft body.</p>')
            ->call('sendComposer')
            ->assertSet('composerOpen', false)
            ->assertSee('Draft sent from workspace-provider-draft-direct@example.test.');

        $this->assertCount(1, $mailer->calls);
        $this->assertSame('Updated provider draft', $mailer->calls[0]['subject']);
        $this->assertStringContainsString('Updated draft body.', $mailer->calls[0]['text']);
        $this->assertSame([['uid' => 806, 'folderPath' => 'Drafts']], $fakeClient->deleted);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);

        $draft = EmailComposerDraft::query()->where('mode', SendEmailComposerMessage::MODE_PROVIDER_DRAFT)->sole();
        $this->assertSame(EmailComposerDraft::STATUS_SENT, $draft->status);
        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_DELETED, $draft->provider_draft_status);
    }

    #[Test]
    public function mail_workspace_filters_folders_and_opens_only_authorized_placements(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-filter@example.test',
        ]));
        $privateAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-private@example.test',
            'imap_username' => 'workspace-private@example.test',
            'smtp_username' => 'workspace-private@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $inbox = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 301,
        ]);
        $sent = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Sent',
            'name' => 'Sent',
            'role' => EmailFolder::ROLE_SENT,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 302,
        ]);
        $privateFolder = EmailFolder::create([
            'account_id' => $privateAccount->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 303,
        ]);

        $inboxMessage = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 3011,
            'message_id' => '<workspace-inbox@example.test>',
            'subject' => 'Workspace inbox message',
            'from_email' => 'sender@example.test',
            'received_at' => now()->subMinute(),
            'state' => 'untriaged',
            'body_text' => 'Inbox body.',
        ]);
        $sentMessage = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'Sent',
            'imap_uid' => 3021,
            'message_id' => '<workspace-sent@example.test>',
            'subject' => 'Workspace sent message',
            'from_email' => 'tech@example.test',
            'received_at' => now(),
            'state' => 'linked',
            'body_text' => 'Sent folder body.',
        ]);
        $privateMessage = EmailMessage::create([
            'account_id' => $privateAccount->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 3031,
            'message_id' => '<workspace-private@example.test>',
            'subject' => 'Workspace private message',
            'from_email' => 'private@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Private body.',
        ]);

        EmailMailboxPlacement::create([
            'email_message_id' => $inboxMessage->id,
            'account_id' => $account->id,
            'email_folder_id' => $inbox->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 301,
            'imap_uid' => 3011,
            'provider_seen' => false,
        ]);
        $sentPlacement = EmailMailboxPlacement::create([
            'email_message_id' => $sentMessage->id,
            'account_id' => $account->id,
            'email_folder_id' => $sent->id,
            'folder_path' => 'Sent',
            'imap_uid_validity' => 302,
            'imap_uid' => 3021,
            'provider_seen' => true,
        ]);
        $privatePlacement = EmailMailboxPlacement::create([
            'email_message_id' => $privateMessage->id,
            'account_id' => $privateAccount->id,
            'email_folder_id' => $privateFolder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 303,
            'imap_uid' => 3031,
            'provider_seen' => false,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->assertSee('Workspace inbox message')
            ->assertDontSee('Mailboxes')
            ->assertDontSee('Folders')
            ->assertDontSee('Workspace sent message')
            ->assertDontSee('Workspace private message')
            ->call('selectFolder', $sent->id)
            ->assertSee('Workspace sent message')
            ->assertDontSee('Workspace inbox message')
            ->call('selectPlacement', $sentPlacement->id)
            ->assertSee('Sent folder body.')
            ->assertSee('Mailbox read')
            ->call('selectPlacement', $privatePlacement->id)
            ->assertDontSee('Private body.');

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->assertSee('Views')
            ->assertSee('Unread')
            ->assertSee('Mailboxes')
            ->assertSee('Folders')
            ->call('selectFolder', $sent->id)
            ->assertSet('viewMode', 'folder')
            ->assertSet('folderId', $sent->id);
    }

    #[Test]
    public function mail_workspace_header_send_and_receive_queues_fetch_for_organize_mailboxes(): void
    {
        Queue::fake();

        $firstAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'refresh-one@example.test',
        ]));
        $secondAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'refresh-two@example.test',
            'imap_username' => 'refresh-two@example.test',
            'smtp_username' => 'refresh-two@example.test',
        ]));
        $viewOnlyAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'refresh-view-only@example.test',
            'imap_username' => 'refresh-view-only@example.test',
            'smtp_username' => 'refresh-view-only@example.test',
        ]));
        $privateAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'refresh-private@example.test',
            'imap_username' => 'refresh-private@example.test',
            'smtp_username' => 'refresh-private@example.test',
        ]));

        $this->grantMailbox($firstAccount, $this->tech, canView: true, canOrganize: true);
        $this->grantMailbox($secondAccount, $this->tech, canView: true, canOrganize: true);
        $this->grantMailbox($viewOnlyAccount, $this->tech, canView: true, canOrganize: false);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->assertDontSee('Send/receive');

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->assertSee('Send/receive')
            ->call('sendAndReceiveMail')
            ->assertSee('Send/receive queued for 2 mailboxes.');

        Queue::assertPushed(FetchImapAccount::class, 2);
        Queue::assertPushed(FetchImapAccount::class, fn (FetchImapAccount $job): bool => $job->accountId === $firstAccount->id && $job->batchSize === 20);
        Queue::assertPushed(FetchImapAccount::class, fn (FetchImapAccount $job): bool => $job->accountId === $secondAccount->id && $job->batchSize === 20);
        Queue::assertNotPushed(FetchImapAccount::class, fn (FetchImapAccount $job): bool => $job->accountId === $viewOnlyAccount->id);
        Queue::assertNotPushed(FetchImapAccount::class, fn (FetchImapAccount $job): bool => $job->accountId === $privateAccount->id);
    }

    #[Test]
    public function mail_sidebar_refresh_selected_folder_queues_fetch_for_that_mailbox(): void
    {
        Queue::fake();

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-refresh@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: true);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX/Client',
            'name' => 'Client',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 731,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->call('selectFolder', $folder->id)
            ->assertSee('Refresh folder')
            ->call('refreshSelectedFolder')
            ->assertSee('Folder refresh queued for folder-refresh@example.test / Client.');

        Queue::assertPushed(FetchImapAccount::class, 1);
        Queue::assertPushed(FetchImapAccount::class, fn (FetchImapAccount $job): bool => $job->accountId === $account->id && $job->batchSize === 20);
    }

    #[Test]
    public function mail_sidebar_refresh_selected_folder_requires_organize_access(): void
    {
        Queue::fake();

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-refresh-readonly@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: false);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 732,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->call('selectFolder', $folder->id)
            ->assertDontSee('Refresh folder')
            ->call('refreshSelectedFolder')
            ->assertSee('Choose a folder in a mailbox you can organize before refreshing it.');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function mail_workspace_groups_thread_messages_as_one_conversation_row(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'conversation-list@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: true);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 741,
        ]);

        $rootMessage = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7411,
            'message_id' => '<conversation-root@example.test>',
            'subject' => 'Grouped root only',
            'from_email' => 'customer@example.test',
            'received_at' => now()->subMinutes(5),
            'state' => 'untriaged',
            'body_text' => 'Root body.',
        ]);
        $replyMessage = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7412,
            'message_id' => '<conversation-reply@example.test>',
            'in_reply_to' => '<conversation-root@example.test>',
            'references' => '<conversation-root@example.test>',
            'subject' => 'Latest response only',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Reply body.',
        ]);

        $rootPlacement = EmailMailboxPlacement::create([
            'email_message_id' => $rootMessage->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 741,
            'imap_uid' => 7411,
            'provider_seen' => false,
        ]);
        $replyPlacement = EmailMailboxPlacement::create([
            'email_message_id' => $replyMessage->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 741,
            'imap_uid' => 7412,
            'provider_seen' => false,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->assertSee('1 conversation')
            ->assertSee('Latest response only')
            ->assertDontSee('Grouped root only')
            ->assertSee('2 mails')
            ->call('selectPlacement', $replyPlacement->id)
            ->assertSee('Conversation')
            ->assertSee('Conversation mails')
            ->assertSeeHtml('aria-controls="mail-conversation-children-'.$replyPlacement->id.'"')
            ->assertSeeHtml('data-mail-conversation-child-placement-id="'.$replyPlacement->id.'"')
            ->assertSeeHtml('data-mail-conversation-child-placement-id="'.$rootPlacement->id.'"')
            ->assertSee('Grouped root only')
            ->assertSee('Latest response only')
            ->assertSee('Reply body.')
            ->assertDontSee('Root body.')
            ->call('selectPlacement', $rootPlacement->id)
            ->assertSet('selectedPlacementId', $rootPlacement->id)
            ->assertSeeHtml('aria-current="true"')
            ->assertSee('Root body.');
    }

    #[Test]
    public function mail_workspace_keeps_conversation_rows_account_scoped(): void
    {
        $firstAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'conversation-account-one@example.test',
        ]));
        $secondAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'conversation-account-two@example.test',
            'imap_username' => 'conversation-account-two@example.test',
            'smtp_username' => 'conversation-account-two@example.test',
        ]));
        $this->grantMailbox($firstAccount, $this->tech, canView: true, canOrganize: true);
        $this->grantMailbox($secondAccount, $this->tech, canView: true, canOrganize: true);

        $firstFolder = EmailFolder::create([
            'account_id' => $firstAccount->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 742,
        ]);
        $secondFolder = EmailFolder::create([
            'account_id' => $secondAccount->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 743,
        ]);

        $firstMessage = EmailMessage::create([
            'account_id' => $firstAccount->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7421,
            'message_id' => '<same-message-id@example.test>',
            'subject' => 'Mailbox one copy',
            'from_email' => 'customer@example.test',
            'received_at' => now()->subMinute(),
            'state' => 'untriaged',
            'body_text' => 'First mailbox body.',
        ]);
        $secondMessage = EmailMessage::create([
            'account_id' => $secondAccount->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7431,
            'message_id' => '<same-message-id@example.test>',
            'subject' => 'Mailbox two copy',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Second mailbox body.',
        ]);

        EmailMailboxPlacement::create([
            'email_message_id' => $firstMessage->id,
            'account_id' => $firstAccount->id,
            'email_folder_id' => $firstFolder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 742,
            'imap_uid' => 7421,
            'provider_seen' => false,
        ]);
        EmailMailboxPlacement::create([
            'email_message_id' => $secondMessage->id,
            'account_id' => $secondAccount->id,
            'email_folder_id' => $secondFolder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 743,
            'imap_uid' => 7431,
            'provider_seen' => false,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->assertSee('2 conversations')
            ->assertSee('Mailbox one copy')
            ->assertSee('Mailbox two copy')
            ->assertDontSee('2 mails');
    }

    #[Test]
    public function inbound_storage_projects_durable_account_scoped_conversations(): void
    {
        $firstAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'durable-conversation-one@example.test',
            'ticket_ingress_enabled' => false,
        ]));
        $secondAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'durable-conversation-two@example.test',
            'imap_username' => 'durable-conversation-two@example.test',
            'smtp_username' => 'durable-conversation-two@example.test',
            'ticket_ingress_enabled' => false,
        ]));

        app()->call([new StoreInboundMessage([
            'account_id' => $firstAccount->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7511,
            'uid_validity' => 751,
            'message_id' => '<durable-root@example.test>',
            'subject' => 'Durable root',
            'from_email' => 'customer@example.test',
            'received_at' => now()->subMinutes(4),
            'is_oversize' => true,
            'run_inbound_rules' => false,
        ]), 'handle']);

        app()->call([new StoreInboundMessage([
            'account_id' => $firstAccount->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7512,
            'uid_validity' => 751,
            'message_id' => '<durable-reply@example.test>',
            'in_reply_to' => '<durable-root@example.test>',
            'references' => '<durable-root@example.test>',
            'subject' => 'Re: Durable root',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'is_oversize' => true,
            'run_inbound_rules' => false,
        ]), 'handle']);

        app()->call([new StoreInboundMessage([
            'account_id' => $secondAccount->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7521,
            'uid_validity' => 752,
            'message_id' => '<durable-root@example.test>',
            'subject' => 'Durable root other mailbox',
            'from_email' => 'customer@example.test',
            'received_at' => now()->subMinute(),
            'is_oversize' => true,
            'run_inbound_rules' => false,
        ]), 'handle']);

        $rootPlacement = EmailMailboxPlacement::query()
            ->where('account_id', $firstAccount->id)
            ->where('imap_uid', 7511)
            ->firstOrFail();
        $replyPlacement = EmailMailboxPlacement::query()
            ->where('account_id', $firstAccount->id)
            ->where('imap_uid', 7512)
            ->firstOrFail();
        $otherPlacement = EmailMailboxPlacement::query()
            ->where('account_id', $secondAccount->id)
            ->where('imap_uid', 7521)
            ->firstOrFail();

        $this->assertNotNull($rootPlacement->email_conversation_id);
        $this->assertSame($rootPlacement->email_conversation_id, $replyPlacement->email_conversation_id);
        $this->assertNotSame($rootPlacement->email_conversation_id, $otherPlacement->email_conversation_id);

        $conversation = EmailConversation::query()->findOrFail($rootPlacement->email_conversation_id);
        $this->assertSame($firstAccount->id, $conversation->account_id);
        $this->assertSame(2, $conversation->message_count);
        $this->assertSame(2, $conversation->active_placement_count);
        $this->assertSame(2, $conversation->provider_unread_count);
        $this->assertSame($replyPlacement->email_message_id, $conversation->latest_email_message_id);

        $this->assertDatabaseHas('email_conversations', [
            'id' => $otherPlacement->email_conversation_id,
            'account_id' => $secondAccount->id,
            'message_count' => 1,
        ]);
    }

    #[Test]
    public function mail_sidebar_creates_provider_folder_for_selected_organize_mailbox(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-create@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: true, canSend: false);

        $fakeClient = new class($account) extends ImapClient
        {
            public array $created = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function createFolder(string $folderPath): array
            {
                $this->created[] = $folderPath;

                return [
                    'path' => $folderPath,
                    'name' => 'Client A',
                    'delimiter' => '/',
                    'parent_path' => 'Projects',
                    'remote_id' => $folderPath,
                    'special_use' => null,
                    'role' => EmailFolder::ROLE_CUSTOM,
                    'is_selectable' => true,
                    'sync_enabled' => true,
                    'uid_validity' => 910,
                    'uid_next' => 1,
                    'exists_count' => 0,
                    'unseen_count' => 0,
                    'highest_modseq' => null,
                    'sync_status' => EmailFolder::SYNC_SYNCED,
                    'last_synced_at' => now(),
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $fakeClient);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->set('accountId', $account->id)
            ->assertSee('Manage folders')
            ->call('openFolderManager')
            ->assertSet('folderManagerOpen', true)
            ->assertSee('New folder')
            ->set('newFolderName', 'Projects/Client A')
            ->call('createProviderFolder')
            ->assertSet('folderManagerOpen', true)
            ->assertSet('newFolderName', '')
            ->assertSet('newFolderParentId', '')
            ->assertSet('viewMode', 'unread')
            ->assertSet('folderId', '')
            ->assertSee('Provider folder Projects/Client A was created.')
            ->assertSee('Client A');

        $this->assertSame(['Projects/Client A'], $fakeClient->created);
        $this->assertDatabaseHas('email_folders', [
            'account_id' => $account->id,
            'path' => 'Projects/Client A',
            'name' => 'Client A',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'uid_validity' => 910,
        ]);
    }

    #[Test]
    public function mail_sidebar_creates_provider_folder_inside_selected_parent(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-create-parent@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: true, canSend: false);
        $parent = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Projects',
            'name' => 'Projects',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 912,
        ]);

        $fakeClient = new class($account) extends ImapClient
        {
            public array $created = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function createFolder(string $folderPath): array
            {
                $this->created[] = $folderPath;

                return [
                    'path' => $folderPath,
                    'name' => 'Client B',
                    'delimiter' => '/',
                    'parent_path' => 'Projects',
                    'remote_id' => $folderPath,
                    'special_use' => null,
                    'role' => EmailFolder::ROLE_CUSTOM,
                    'is_selectable' => true,
                    'sync_enabled' => true,
                    'uid_validity' => 913,
                    'uid_next' => 1,
                    'exists_count' => 0,
                    'unseen_count' => 0,
                    'highest_modseq' => null,
                    'sync_status' => EmailFolder::SYNC_SYNCED,
                    'last_synced_at' => now(),
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $fakeClient);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->set('accountId', $account->id)
            ->call('openFolderManager')
            ->set('newFolderParentId', $parent->id)
            ->set('newFolderName', 'Client B')
            ->call('createProviderFolder')
            ->assertSet('folderManagerOpen', true)
            ->assertSet('newFolderName', '')
            ->assertSet('newFolderParentId', '')
            ->assertSet('folderManagerExpandedPaths', ['Projects'])
            ->assertSee('Provider folder Projects/Client B was created.')
            ->assertSee('Projects/Client B');

        $this->assertSame(['Projects/Client B'], $fakeClient->created);
        $this->assertDatabaseHas('email_folders', [
            'account_id' => $account->id,
            'path' => 'Projects/Client B',
            'name' => 'Client B',
            'parent_path' => 'Projects',
            'is_selectable' => true,
        ]);
    }

    #[Test]
    public function mail_sidebar_creates_provider_folder_under_inbox_with_known_account_delimiter(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-create-inbox-parent@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: true, canSend: false);
        $inbox = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 914,
        ]);
        EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Sent',
            'name' => 'Sent',
            'delimiter' => '.',
            'role' => EmailFolder::ROLE_SENT,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 915,
        ]);

        $fakeClient = new class($account) extends ImapClient
        {
            public array $created = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function createFolder(string $folderPath): array
            {
                $this->created[] = $folderPath;

                return [
                    'path' => $folderPath,
                    'name' => 'Client',
                    'delimiter' => '.',
                    'parent_path' => 'INBOX',
                    'remote_id' => $folderPath,
                    'special_use' => null,
                    'role' => EmailFolder::ROLE_CUSTOM,
                    'is_selectable' => true,
                    'sync_enabled' => true,
                    'uid_validity' => 916,
                    'uid_next' => 1,
                    'exists_count' => 0,
                    'unseen_count' => 0,
                    'highest_modseq' => null,
                    'sync_status' => EmailFolder::SYNC_SYNCED,
                    'last_synced_at' => now(),
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $fakeClient);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->set('accountId', $account->id)
            ->call('openFolderManager')
            ->set('newFolderParentId', $inbox->id)
            ->set('newFolderName', 'Client')
            ->call('createProviderFolder')
            ->assertSet('folderManagerOpen', true)
            ->assertSet('newFolderName', '')
            ->assertSet('newFolderParentId', '')
            ->assertSet('folderManagerExpandedPaths', ['INBOX'])
            ->assertSee('Provider folder INBOX.Client was created.')
            ->assertSee('INBOX.Client');

        $this->assertSame(['INBOX.Client'], $fakeClient->created);
        $this->assertDatabaseHas('email_folders', [
            'account_id' => $account->id,
            'path' => 'INBOX.Client',
            'name' => 'Client',
            'parent_path' => 'INBOX',
            'delimiter' => '.',
            'is_selectable' => true,
        ]);
    }

    #[Test]
    public function imap_client_create_folder_does_not_expunge_after_create(): void
    {
        $account = EmailAccount::make($this->emailAccountPayload([
            'address' => 'folder-create-wrapper@example.test',
        ]));
        $providerClient = new class extends WebklexImapClient
        {
            public array $createCalls = [];

            public function __construct() {}

            public function checkConnection(): bool
            {
                return true;
            }

            public function createFolder(string $folder_path, bool $expunge = true, bool $utf7 = false): WebklexFolder
            {
                $this->createCalls[] = [
                    'path' => $folder_path,
                    'expunge' => $expunge,
                    'utf7' => $utf7,
                ];

                return new WebklexFolder($this, $folder_path, '/', []);
            }

            public function getFolderByPath($folder_path, bool $utf7 = false, bool $soft_fail = false): ?WebklexFolder
            {
                return new WebklexFolder($this, $folder_path, '/', []);
            }
        };
        $client = new ImapClient($account);
        $property = new \ReflectionProperty(ImapClient::class, 'client');
        $property->setValue($client, $providerClient);

        $folder = $client->createFolder('INBOX/Client');

        $this->assertSame('INBOX/Client', $folder['path']);
        $this->assertSame([
            [
                'path' => 'INBOX/Client',
                'expunge' => false,
                'utf7' => false,
            ],
        ], $providerClient->createCalls);
    }

    #[Test]
    public function mail_sidebar_shows_folder_manager_when_only_one_organize_mailbox_is_available(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'single-organize-folder-manager@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: true, canSend: false);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->assertSet('accountId', '')
            ->assertSee('Manage folders')
            ->call('openFolderManager')
            ->assertSet('folderManagerOpen', true)
            ->assertSee('single-organize-folder-manager@example.test');
    }

    #[Test]
    public function mail_sidebar_shows_folder_manager_with_mailbox_selector_for_multiple_organize_mailboxes(): void
    {
        $firstAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'aaa-folder-manager@example.test',
        ]));
        $secondAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'zzz-folder-manager@example.test',
        ]));
        $this->grantMailbox($firstAccount, $this->tech, canView: true, canOrganize: true, canSend: false);
        $this->grantMailbox($secondAccount, $this->tech, canView: true, canOrganize: true, canSend: false);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->assertSet('accountId', '')
            ->assertSet('folderId', '')
            ->assertSee('Manage folders')
            ->call('openFolderManager')
            ->assertSet('folderManagerOpen', true)
            ->assertSet('folderManagerAccountId', $firstAccount->id)
            ->assertSee('aaa-folder-manager@example.test')
            ->assertSee('zzz-folder-manager@example.test')
            ->call('changeFolderManagerAccount', $secondAccount->id)
            ->assertSet('folderManagerAccountId', $secondAccount->id)
            ->assertSee('zzz-folder-manager@example.test');
    }

    #[Test]
    public function mail_sidebar_shows_folder_manager_for_selected_folder_without_account_filter(): void
    {
        $firstAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'first-folder-manager@example.test',
        ]));
        $secondAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'second-folder-manager@example.test',
        ]));
        $this->grantMailbox($firstAccount, $this->tech, canView: true, canOrganize: true, canSend: false);
        $this->grantMailbox($secondAccount, $this->tech, canView: true, canOrganize: true, canSend: false);

        $folder = EmailFolder::create([
            'account_id' => $secondAccount->id,
            'path' => 'Projects',
            'name' => 'Projects',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 911,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->set('folderId', $folder->id)
            ->assertSet('accountId', '')
            ->assertSee('Manage folders')
            ->call('openFolderManager')
            ->assertSet('folderManagerOpen', true)
            ->assertSee('second-folder-manager@example.test');
    }

    #[Test]
    public function mail_sidebar_requires_organize_access_before_creating_provider_folder(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-create-view-only@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: false, canSend: false);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->set('accountId', $account->id)
            ->assertDontSee('Manage folders')
            ->call('openFolderManager')
            ->assertSet('folderManagerOpen', false)
            ->assertSee('Choose one mailbox with Organize access before managing provider folders.');
    }

    #[Test]
    public function mail_sidebar_renames_custom_provider_folder_from_manager(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-rename@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: true, canSend: false);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Projects/Old',
            'name' => 'Old',
            'delimiter' => '/',
            'parent_path' => 'Projects',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 920,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'Projects/Old',
            'imap_uid' => 9201,
            'message_id' => '<folder-rename@example.test>',
            'subject' => 'Folder rename projection',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'Projects/Old',
            'imap_uid_validity' => 920,
            'imap_uid' => 9201,
            'provider_seen' => true,
        ]);
        $rule = EmailRule::create([
            'name' => 'Move to old folder',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_PERSONAL,
            'rule_kind' => EmailRule::KIND_PERSONAL_SIMPLE,
            'owner_id' => $this->tech->id,
            'is_active' => true,
            'lifecycle_status' => EmailRule::LIFECYCLE_PUBLISHED,
            'conditions_json' => [['field' => 'from', 'operator' => 'contains', 'value' => 'sender@example.test']],
            'actions_json' => [[
                'type' => CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER,
                'value' => 'Projects/Old',
                'target_folder_id' => $folder->id,
            ]],
        ]);

        $client = new class($account) extends ImapClient
        {
            public array $renamed = [];

            public function connect(): void {}

            public function renameFolder(string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->renamed[] = [$sourceFolderPath, $targetFolderPath];

                return [
                    'ok' => true,
                    'source_folder_path' => $sourceFolderPath,
                    'target_folder_path' => $targetFolderPath,
                    'path' => $targetFolderPath,
                    'name' => 'Renamed',
                    'delimiter' => '/',
                    'parent_path' => 'Projects',
                    'remote_id' => $targetFolderPath,
                    'role' => EmailFolder::ROLE_CUSTOM,
                    'is_selectable' => true,
                    'sync_enabled' => true,
                    'uid_validity' => 921,
                    'uid_next' => 10,
                    'exists_count' => 1,
                    'unseen_count' => 0,
                    'highest_modseq' => null,
                    'sync_status' => EmailFolder::SYNC_SYNCED,
                    'last_synced_at' => now(),
                    'response' => ['OK Rename completed'],
                ];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->set('accountId', $account->id)
            ->call('openFolderManager')
            ->assertSee('Old')
            ->call('startFolderRename', $folder->id)
            ->assertSet('folderRenameName', 'Old')
            ->set('folderRenameName', 'Renamed')
            ->call('renameProviderFolder')
            ->assertSee('Provider folder was renamed to Projects/Renamed.');

        $this->assertSame([['Projects/Old', 'Projects/Renamed']], $client->renamed);
        $this->assertDatabaseHas('email_folders', [
            'id' => $folder->id,
            'path' => 'Projects/Renamed',
            'name' => 'Renamed',
            'uid_validity' => 921,
        ]);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'email_folder_id' => $folder->id,
            'folder_path' => 'Projects/Renamed',
            'imap_uid_validity' => 921,
        ]);
        $this->assertSame('Projects/Renamed', $rule->fresh()->actions_json[0]['value']);
        $this->assertDatabaseHas('email_remote_operations', [
            'email_folder_id' => $folder->id,
            'operation_type' => 'folder_rename',
            'status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            'source_folder_path' => 'Projects/Old',
            'target_folder_path' => 'Projects/Renamed',
        ]);
    }

    #[Test]
    public function mail_sidebar_moves_custom_provider_folder_to_selected_parent(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-move@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: true, canSend: false);

        $parent = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Projects',
            'name' => 'Projects',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 922,
        ]);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Client',
            'name' => 'Client',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 923,
        ]);

        $client = new class($account) extends ImapClient
        {
            public array $renamed = [];

            public function connect(): void {}

            public function renameFolder(string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->renamed[] = [$sourceFolderPath, $targetFolderPath];

                return [
                    'ok' => true,
                    'source_folder_path' => $sourceFolderPath,
                    'target_folder_path' => $targetFolderPath,
                    'path' => $targetFolderPath,
                    'name' => 'Client',
                    'delimiter' => '/',
                    'parent_path' => 'Projects',
                    'remote_id' => $targetFolderPath,
                    'role' => EmailFolder::ROLE_CUSTOM,
                    'is_selectable' => true,
                    'sync_enabled' => true,
                    'uid_validity' => 923,
                    'uid_next' => 1,
                    'exists_count' => 0,
                    'unseen_count' => 0,
                    'highest_modseq' => null,
                    'sync_status' => EmailFolder::SYNC_SYNCED,
                    'last_synced_at' => now(),
                    'response' => ['OK Rename completed'],
                ];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->set('accountId', $account->id)
            ->call('openFolderManager')
            ->call('startFolderMove', $folder->id)
            ->assertSet('folderMoveFolderId', $folder->id)
            ->set('folderMoveParentFolderId', $parent->id)
            ->call('moveProviderFolder')
            ->assertSee('Provider folder was moved to Projects/Client.');

        $this->assertSame([['Client', 'Projects/Client']], $client->renamed);
        $this->assertDatabaseHas('email_folders', [
            'id' => $folder->id,
            'path' => 'Projects/Client',
            'name' => 'Client',
            'parent_path' => 'Projects',
        ]);
        $this->assertDatabaseHas('email_remote_operations', [
            'email_folder_id' => $folder->id,
            'operation_type' => 'folder_move',
            'status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            'source_folder_path' => 'Client',
            'target_folder_path' => 'Projects/Client',
        ]);
    }

    #[Test]
    public function mail_sidebar_moves_mail_before_deleting_custom_provider_folder(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-delete@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: true, canSend: false);

        $source = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Projects/Delete',
            'name' => 'Delete',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 930,
        ]);
        $archive = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 931,
        ]);
        $sourceNamespace = $this->activeUidNamespace($source);
        $this->activeUidNamespace($archive);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'Projects/Delete',
            'imap_uid' => 9301,
            'message_id' => '<folder-delete@example.test>',
            'subject' => 'Folder delete move',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $source->id,
            'uid_namespace_id' => $sourceNamespace->id,
            'folder_path' => 'Projects/Delete',
            'imap_uid_validity' => 930,
            'imap_uid' => 9301,
            'provider_seen' => true,
        ]);
        $conversationId = app(EmailConversationProjector::class)
            ->assignPlacement($placement)
            ?->id;

        $client = new class($account) extends ImapClient
        {
            public array $moves = [];

            public array $deleted = [];

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $folderPath === 'Projects/Delete' ? 930 : 931];
            }

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->moves[] = [$uid, $sourceFolderPath, $targetFolderPath];

                return [
                    'ok' => true,
                    'target_folder_path' => $targetFolderPath,
                    'target_imap_uid' => 9930,
                    'target_uid_validity' => 931,
                    'target_uid_authoritative' => true,
                ];
            }

            public function deleteFolder(string $folderPath): array
            {
                $this->deleted[] = $folderPath;

                return [
                    'ok' => true,
                    'source_folder_path' => $folderPath,
                    'folder_path' => $folderPath,
                    'response' => ['OK Delete completed'],
                ];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->set('accountId', $account->id)
            ->call('openFolderManager')
            ->call('startFolderDelete', $source->id)
            ->assertSet('folderMoveSourceFolderId', $source->id)
            ->assertSet('folderMoveTargetFolderId', $archive->id)
            ->assertSee('This folder contains 1 mail.')
            ->call('moveManagedFolderMail')
            ->assertSee('Moved 1 mail to Archive. The source folder can now be deleted.')
            ->call('deleteProviderFolder')
            ->assertSee('Provider folder Projects/Delete was deleted.');

        $this->assertSame([[9301, 'Projects/Delete', 'Archive']], $client->moves);
        $this->assertSame(['Projects/Delete'], $client->deleted);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $archive->id,
            'email_conversation_id' => $conversationId,
            'folder_path' => 'Archive',
            'imap_uid_validity' => 931,
            'imap_uid' => 9930,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
        $this->assertDatabaseHas('email_conversations', [
            'id' => $conversationId,
            'active_placement_count' => 1,
        ]);
        $this->assertDatabaseHas('email_folders', [
            'id' => $source->id,
            'is_selectable' => false,
            'sync_enabled' => false,
            'exists_count' => 0,
        ]);
        $this->assertDatabaseHas('email_remote_operations', [
            'email_folder_id' => $source->id,
            'operation_type' => 'folder_delete',
            'status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            'source_folder_path' => 'Projects/Delete',
        ]);
    }

    #[Test]
    public function mail_sidebar_blocks_system_or_rule_referenced_folder_delete(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-delete-blocked@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: true, canSend: false);

        $inbox = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 940,
        ]);
        $custom = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Projects/Rule',
            'name' => 'Rule',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 941,
        ]);
        EmailRule::create([
            'name' => 'Move to rule folder',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_PERSONAL,
            'rule_kind' => EmailRule::KIND_PERSONAL_SIMPLE,
            'owner_id' => $this->tech->id,
            'is_active' => true,
            'lifecycle_status' => EmailRule::LIFECYCLE_PUBLISHED,
            'conditions_json' => [['field' => 'from', 'operator' => 'contains', 'value' => 'sender@example.test']],
            'actions_json' => [[
                'type' => CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER,
                'value' => 'Projects/Rule',
                'target_folder_id' => $custom->id,
            ]],
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->set('accountId', $account->id)
            ->call('openFolderManager')
            ->call('startFolderRename', $inbox->id)
            ->assertSee('This provider folder cannot be renamed: System folder.')
            ->call('startFolderDelete', $custom->id)
            ->assertSee('This provider folder cannot be deleted: Used by rules.');
    }

    #[Test]
    public function mail_sidebar_folder_manager_shows_subfolders_and_leaf_actions(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-tree@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canView: true, canOrganize: true, canSend: false);

        $parent = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Projects',
            'name' => 'Projects',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 950,
        ]);
        $child = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Projects/Client',
            'name' => 'Client',
            'delimiter' => '/',
            'parent_path' => 'Projects',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 951,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->set('accountId', $account->id)
            ->call('openFolderManager')
            ->assertSee('No actions: Has subfolders')
            ->assertDontSeeHtml('style="--mail-folder-depth: 1;"')
            ->call('toggleFolderManagerFolder', $parent->id)
            ->assertSee('Projects/Client')
            ->assertSeeHtml('style="--mail-folder-depth: 1;"')
            ->call('toggleFolderManagerFolder', $parent->id)
            ->assertDontSeeHtml('style="--mail-folder-depth: 1;"')
            ->call('toggleFolderManagerFolder', $parent->id)
            ->assertSee('Projects/Client')
            ->assertSeeHtml('style="--mail-folder-depth: 1;"')
            ->call('startFolderRename', $child->id)
            ->assertSet('folderRenameFolderId', $child->id)
            ->assertSet('folderRenameName', 'Client');
    }

    #[Test]
    public function mail_workspace_list_filter_limits_visible_message_list(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-list-filter@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 351,
        ]);

        $plain = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 3511,
            'message_id' => '<workspace-filter-plain@example.test>',
            'subject' => 'Plain filter message',
            'from_email' => 'plain@example.test',
            'received_at' => now()->subMinutes(3),
            'state' => 'untriaged',
            'body_text' => 'Plain filter body.',
        ]);
        $mailboxUnread = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 3512,
            'message_id' => '<workspace-filter-mailbox-unread@example.test>',
            'subject' => 'Mailbox unread filter message',
            'from_email' => 'unread@example.test',
            'received_at' => now()->subMinutes(2),
            'state' => 'untriaged',
            'body_text' => 'Mailbox unread filter body.',
        ]);
        $flagged = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 3513,
            'message_id' => '<workspace-filter-flagged@example.test>',
            'subject' => 'Flagged filter message',
            'from_email' => 'flagged@example.test',
            'received_at' => now()->subMinute(),
            'state' => 'untriaged',
            'body_text' => 'Flagged filter body.',
        ]);
        $attachment = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 3514,
            'message_id' => '<workspace-filter-attachment@example.test>',
            'subject' => 'Attachment filter message',
            'from_email' => 'attachment@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Attachment filter body.',
        ]);

        foreach ([
            [$plain, 3511, true, false],
            [$mailboxUnread, 3512, false, false],
            [$flagged, 3513, true, true],
            [$attachment, 3514, true, false],
        ] as [$message, $uid, $providerSeen, $providerFlagged]) {
            EmailMailboxPlacement::create([
                'email_message_id' => $message->id,
                'account_id' => $account->id,
                'email_folder_id' => $folder->id,
                'folder_path' => 'INBOX',
                'imap_uid_validity' => 351,
                'imap_uid' => $uid,
                'provider_seen' => $providerSeen,
                'provider_flagged' => $providerFlagged,
            ]);
        }

        EmailAttachment::create([
            'message_id' => $attachment->id,
            'filename' => 'filter.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 123,
            'disk' => 'local',
            'path' => 'email/filter.pdf',
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->assertSee('Search mail')
            ->assertSee('Mailbox unread')
            ->assertSee('Flagged')
            ->assertSee('Has attachments')
            ->set('listFilter', 'mailbox_unread')
            ->assertSee('Mailbox unread filter message')
            ->assertDontSee('Plain filter message')
            ->assertDontSee('Flagged filter message')
            ->assertDontSee('Attachment filter message')
            ->set('listFilter', 'flagged')
            ->assertSee('Flagged filter message')
            ->assertDontSee('Mailbox unread filter message')
            ->assertDontSee('Attachment filter message')
            ->set('listFilter', 'has_attachments')
            ->assertSee('Attachment filter message')
            ->assertDontSee('Mailbox unread filter message')
            ->assertDontSee('Flagged filter message');
    }

    #[Test]
    public function mail_workspace_opening_mail_does_not_clear_unread_for_me(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-state@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 401,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 4011,
            'message_id' => '<workspace-state@example.test>',
            'subject' => 'Workspace unread state message',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Unread state body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 401,
            'imap_uid' => 4011,
            'provider_seen' => true,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->assertSee('Workspace unread state message')
            ->call('selectPlacement', $placement->id)
            ->assertSee('Unread state body.')
            ->assertSee('Unread for me');

        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $message->id,
            'user_id' => $this->tech->id,
            'is_unread' => true,
            'opened_count' => 1,
            'last_opened_placement_id' => $placement->id,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->call('setSelectedUnreadForMe', false)
            ->assertSee('Read for me')
            ->assertSee('Mark unread for me');

        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $message->id,
            'user_id' => $this->tech->id,
            'is_unread' => false,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->assertDontSee('Workspace unread state message')
            ->call('setView', 'inbox')
            ->assertSee('Workspace unread state message');
    }

    #[Test]
    public function mail_workspace_provider_actions_update_imap_and_remote_operation_ledger(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-actions@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 501,
        ]);
        $namespace = $this->activeUidNamespace($folder);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 5011,
            'message_id' => '<workspace-actions@example.test>',
            'subject' => 'Workspace provider action message',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Provider action body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 501,
            'imap_uid' => 5011,
            'provider_seen' => false,
            'provider_flagged' => false,
        ]);

        $client = new class($account) extends ImapClient
        {
            public array $seenCalls = [];

            public array $flaggedCalls = [];

            public int $connects = 0;

            public int $disconnects = 0;

            public function connect(): void
            {
                $this->connects++;
            }

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 501];
            }

            public function setSeenByUid(int $uid, bool $seen, string $folderPath = 'INBOX'): bool
            {
                $this->seenCalls[] = [$uid, $seen, $folderPath];

                return true;
            }

            public function setFlaggedByUid(int $uid, bool $flagged, string $folderPath = 'INBOX'): bool
            {
                $this->flaggedCalls[] = [$uid, $flagged, $folderPath];

                return true;
            }

            public function disconnect(): void
            {
                $this->disconnects++;
            }
        };

        $this->app->bind(ImapClient::class, fn () => $client);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertDontSeeHtml('dropdown-toggle')
            ->assertSee('Mark read on mail server')
            ->assertSee('Flag')
            ->assertSee('Category and tags')
            ->call('setProviderSeenForSelected', true)
            ->assertSee('Mailbox read')
            ->assertSee('Message was marked read in the mailbox.')
            ->call('setProviderFlaggedForSelected', true)
            ->assertSee('Unflag')
            ->assertSee('Message was flagged in the mailbox.');

        $this->assertSame([[5011, true, 'INBOX']], $client->seenCalls);
        $this->assertSame([[5011, true, 'INBOX']], $client->flaggedCalls);
        $this->assertSame(2, $client->connects);
        $this->assertSame(2, $client->disconnects);

        $this->assertDatabaseHas('email_remote_operations', [
            'email_mailbox_placement_id' => $placement->id,
            'operation_type' => 'mark_seen',
            'status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            'source_folder_path' => 'INBOX',
        ]);
        $this->assertDatabaseHas('email_remote_operations', [
            'email_mailbox_placement_id' => $placement->id,
            'operation_type' => 'flag',
            'status' => EmailRemoteOperation::STATUS_SUCCEEDED,
        ]);

        $placement->refresh();
        $this->assertTrue($placement->provider_seen);
        $this->assertTrue($placement->provider_flagged);
        $this->assertSame(3, $placement->sync_version);
    }

    #[Test]
    public function mail_workspace_can_generate_governed_ai_summary_for_authorized_message(): void
    {
        $agent = $this->readyDefaultMailAgent();

        Http::fake([
            'http://ollama-mail-ai.test/api/chat' => Http::response([
                'model' => 'mail-fallback-test',
                'message' => [
                    'content' => json_encode([
                        'summary' => 'Customer asks Nexum to restart the backup job before Friday.',
                        'key_points' => [
                            'Backup job is stalled.',
                            'Customer expects confirmation after restart.',
                        ],
                        'questions' => [
                            'Which backup node should be checked first?',
                        ],
                        'action_items' => [
                            [
                                'text' => 'Restart the affected backup job.',
                                'owner' => 'Nexum technician',
                                'due_at' => 'Friday',
                                'source_message_id' => null,
                            ],
                        ],
                        'suggested_labels' => [
                            [
                                'type' => 'tag',
                                'label' => 'backup',
                                'reason' => 'Message asks for backup follow-up.',
                                'confidence' => 0.91,
                                'source_message_id' => null,
                            ],
                        ],
                        'urgency' => 'high',
                        'reply_needed' => true,
                        'provenance' => [
                            'source_message_ids' => [],
                            'limitations' => ['Only message text was included. Attachments and raw source were not used.'],
                        ],
                    ]),
                ],
            ], 200),
        ]);

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ai@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 521,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 5211,
            'message_id' => '<workspace-ai@example.test>',
            'subject' => 'Backup job stalled',
            'from_name' => 'Customer Admin',
            'from_email' => 'admin@example.test',
            'to_json' => [['name' => 'Support', 'email' => 'workspace-ai@example.test']],
            'cc_json' => [['name' => 'Ops', 'email' => 'ops@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Please restart the backup job before Friday and tell us when it is done.',
            'body_html_sanitized' => '<p>Please restart the backup job.</p>',
        ]);
        EmailAttachment::create([
            'message_id' => $message->id,
            'filename' => 'backup-log.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 2000,
            'disk' => 'local',
            'path' => 'email/backup-log.txt',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 521,
            'imap_uid' => 5211,
            'provider_seen' => false,
            'provider_flagged' => false,
        ]);

        $component = Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertSee('AI summary')
            ->call('generateMailAiSummary')
            ->assertSee('Customer asks Nexum to restart the backup job before Friday.')
            ->assertSee('Reply likely needed');

        $summary = $component->get('mailAiSummary');
        $this->assertSame('Customer asks Nexum to restart the backup job before Friday.', $summary['summary']);
        $this->assertSame('high', $summary['urgency']);
        $this->assertTrue($summary['reply_needed']);
        $this->assertSame('default_agent', data_get($summary, 'metadata.source'));
        $this->assertSame($agent->id, data_get($summary, 'metadata.agent_id'));

        Http::assertSent(function ($request): bool {
            $payload = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return $request->url() === 'http://ollama-mail-ai.test/api/chat'
                && $request['model'] === 'mail-fallback-test'
                && str_contains((string) $payload, 'output_is_non_mutating')
                && str_contains((string) $payload, 'Please restart the backup job before Friday and tell us when it is done.')
                && str_contains((string) $payload, 'attachments_count')
                && ! str_contains((string) $payload, 'backup-log.txt');
        });
    }

    #[Test]
    public function mail_workspace_can_generate_ai_summary_with_default_email_agent_without_workload(): void
    {
        $agent = $this->readyDefaultMailAgent([
            'can_execute_actions' => true,
            'data_sources' => ['email', 'tickets'],
            'allowed_tools' => ['tickets.update'],
            'allowed_api_scopes' => ['email.read', 'tickets.update'],
        ]);

        Http::fake([
            'http://ollama-mail-ai.test/api/chat' => Http::response([
                'model' => 'mail-fallback-test',
                'message' => [
                    'content' => json_encode([
                        'summary' => 'Customer asks for a fallback-agent summary.',
                        'key_points' => ['Default agent was used.'],
                        'questions' => [],
                        'action_items' => [],
                        'suggested_labels' => [],
                        'urgency' => 'normal',
                        'reply_needed' => true,
                        'provenance' => [
                            'source_message_ids' => [],
                            'limitations' => ['Default Email agent produced this summary without write actions.'],
                        ],
                    ]),
                ],
            ], 200),
        ]);

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ai-default-agent@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 523,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 5231,
            'message_id' => '<workspace-ai-default-agent@example.test>',
            'subject' => 'Default agent summary',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Please summarize this with the default agent.',
        ]);
        EmailAttachment::create([
            'message_id' => $message->id,
            'filename' => 'default-agent-secret-log.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 2000,
            'disk' => 'local',
            'path' => 'email/default-agent-secret-log.txt',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 523,
            'imap_uid' => 5231,
            'provider_seen' => false,
        ]);

        $component = Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertSee('AI summary')
            ->call('generateMailAiSummary')
            ->assertSee('Customer asks for a fallback-agent summary.');

        $summary = $component->get('mailAiSummary');
        $this->assertSame('default_agent', data_get($summary, 'metadata.source'));
        $this->assertSame($agent->id, data_get($summary, 'metadata.agent_id'));
        $this->assertSame(0, EmailLog::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, EmailRemoteOperation::query()->count());

        Http::assertSent(function ($request): bool {
            $payload = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return $request->url() === 'http://ollama-mail-ai.test/api/chat'
                && $request['model'] === 'mail-fallback-test'
                && str_contains((string) $payload, 'output_is_non_mutating')
                && ! str_contains((string) $payload, 'default-agent-secret-log.txt');
        });
    }

    #[Test]
    public function mail_ai_summary_action_is_hidden_without_available_agent(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ai-disabled@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 522,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 5221,
            'message_id' => '<workspace-ai-disabled@example.test>',
            'subject' => 'AI disabled message',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'AI should not be callable without a configured agent.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 522,
            'imap_uid' => 5221,
            'provider_seen' => false,
        ]);

        $component = Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertDontSee('AI summary')
            ->call('generateMailAiSummary')
            ->assertSet('mailAiSummary', null);

        $this->assertSame('warning', $component->get('mailActionStatus.type'));
        $this->assertSame('Mail AI is not available: default_agent_not_available.', $component->get('mailActionStatus.message'));
    }

    #[Test]
    public function mail_ai_controls_are_hidden_when_selected_agent_model_governance_is_missing(): void
    {
        Http::fake();

        $provider = AiProvider::create([
            'name' => 'External Mail AI',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-mail-test',
            'status' => 'active',
            'config' => [],
            'secrets' => [],
            'is_healthy' => true,
        ]);
        AiAgent::create([
            'ai_provider_id' => $provider->id,
            'name' => 'Governance Missing Mail Agent',
            'slug' => 'governance-missing-mail-agent-'.Str::lower(Str::random(8)),
            'model' => 'gpt-mail-test',
            'instructions' => 'Assist with Mail.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => [],
            'can_execute_actions' => false,
            'is_default' => true,
            'default_domains' => ['email'],
            'is_active' => true,
        ]);

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ai-governance@example.test',
            'from_name' => 'Workspace AI Governance',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 524,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 5241,
            'message_id' => '<workspace-ai-governance@example.test>',
            'subject' => 'Governance missing',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => 'workspace-ai-governance@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'AI should stay hidden until the model is governed.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 524,
            'imap_uid' => 5241,
            'provider_seen' => false,
        ]);

        $component = Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertDontSee('AI summary')
            ->call('startReply')
            ->assertDontSee('Optional AI guidance')
            ->call('applyComposerAi', AssistEmailComposerWithAi::INTENT_DRAFT_REPLY);

        $this->assertNull($component->get('mailActionStatus'));
        $this->assertSame('warning', $component->get('composerActionStatus.type'));
        $this->assertSame('Mail AI is not available: model_governance_missing.', $component->get('composerActionStatus.message'));
        Http::assertNothingSent();
    }

    #[Test]
    public function standard_ai_activation_makes_external_default_email_agent_available(): void
    {
        $provider = AiProvider::create([
            'name' => 'OpenAI Mail',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-mail-test',
            'status' => 'active',
            'config' => [],
            'secrets' => [],
            'is_healthy' => true,
        ]);
        AiAgent::create([
            'ai_provider_id' => $provider->id,
            'name' => 'Mail Agent',
            'slug' => 'mail-agent-standard-activation-'.Str::lower(Str::random(8)),
            'model' => 'gpt-mail-test',
            'instructions' => 'Assist with Mail.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => [],
            'can_execute_actions' => false,
            'is_default' => true,
            'default_domains' => ['email'],
            'is_active' => true,
        ]);

        $before = app(MailAiAgentRuntime::class)->availability($this->tech);

        $this->assertFalse($before['available']);
        $this->assertSame('model_governance_missing', $before['reason']);

        app(ActivateStandardAiRuntime::class)->activate($provider, 'gpt-mail-test', $this->admin);

        $after = app(MailAiAgentRuntime::class)->availability($this->tech);

        $this->assertTrue($after['available']);
        $this->assertSame('Mail Agent', $after['agent']?->name);
        $this->assertSame('gpt-mail-test', $after['model']);
    }

    #[Test]
    public function mail_workspace_can_assign_system_category_and_multiple_tags_to_selected_email(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-classification@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 511,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 5111,
            'message_id' => '<workspace-classification@example.test>',
            'subject' => 'Workspace classification message',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Classification body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 511,
            'imap_uid' => 5111,
            'provider_seen' => true,
            'provider_flagged' => true,
        ]);
        $category = Category::create([
            'name' => 'Security',
            'slug' => 'security',
            'type' => Category::TYPE_EMAIL,
            'is_active' => true,
        ]);
        $urgent = Tag::create([
            'name' => 'Urgent',
            'slug' => 'urgent',
            'active' => true,
        ]);
        $followUp = Tag::create([
            'name' => 'Follow up',
            'slug' => 'follow-up',
            'active' => true,
        ]);

        $component = Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertSet('classificationEditorOpen', false)
            ->assertSee('Category and tags')
            ->assertDontSee('No category')
            ->assertDontSee('No tags')
            ->assertDontSeeHtml('id="mail-classification-category"')
            ->assertDontSeeHtml('id="mail-classification-tags"')
            ->assertSee('Flagged')
            ->call('toggleClassificationEditor')
            ->assertSet('classificationEditorOpen', true)
            ->assertSeeHtml('id="mail-classification-category"')
            ->assertSeeHtml('id="mail-classification-tags"')
            ->assertSee('No category or tags')
            ->set('classificationCategoryId', $category->id)
            ->set('classificationTagsInput', 'Urgent, Follow up')
            ->call('saveClassification')
            ->assertSet('classificationEditorOpen', false)
            ->assertDontSeeHtml('id="mail-classification-category"')
            ->assertSee('Conversation category and tags were updated.')
            ->assertSee('Security')
            ->assertSee('Urgent')
            ->assertSee('Follow up')
            ->assertSee('Unflag in mailbox');

        $conversationId = $placement->fresh()->email_conversation_id;
        $this->assertNotNull($conversationId);

        $classification = EmailConversationClassification::query()
            ->with('tags')
            ->where('account_id', $account->id)
            ->where('email_conversation_id', $conversationId)
            ->firstOrFail();

        $this->assertSame($category->id, $classification->category_id);
        $this->assertEqualsCanonicalizing(
            [$urgent->id, $followUp->id],
            $classification->tags->pluck('id')->all(),
        );
        $this->assertDatabaseHas('email_conversation_classification_events', [
            'email_conversation_classification_id' => $classification->id,
            'account_id' => $account->id,
            'email_conversation_id' => $conversationId,
            'actor_id' => $this->tech->id,
            'event_type' => 'updated',
        ]);

        $reply = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 5112,
            'message_id' => '<workspace-classification-reply@example.test>',
            'in_reply_to' => '<workspace-classification@example.test>',
            'references' => '<workspace-classification@example.test>',
            'subject' => 'Re: Workspace classification message',
            'from_email' => 'sender@example.test',
            'received_at' => now()->addMinute(),
            'state' => 'untriaged',
            'body_text' => 'Classification reply body.',
        ]);
        $replyPlacement = EmailMailboxPlacement::create([
            'email_message_id' => $reply->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 511,
            'imap_uid' => 5112,
            'provider_seen' => true,
        ]);
        app(EmailConversationProjector::class)->assignPlacement($replyPlacement);

        $this->assertSame($conversationId, $replyPlacement->fresh()->email_conversation_id);
        $component
            ->call('selectPlacement', $replyPlacement->id)
            ->assertSee('Security')
            ->assertSee('Urgent')
            ->assertSee('Follow up');

        $component
            ->call('toggleClassificationEditor')
            ->assertSet('classificationEditorOpen', true)
            ->call('clearClassification')
            ->assertSet('classificationEditorOpen', false)
            ->assertDontSee('No category')
            ->assertDontSee('No tags')
            ->assertDontSee('Security')
            ->assertDontSee('Urgent')
            ->assertDontSee('Follow up');

        $classification->refresh()->load('tags');
        $this->assertNull($classification->category_id);
        $this->assertCount(0, $classification->tags);
    }

    #[Test]
    public function mail_workspace_classification_requires_mailbox_organize_access(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-classification-denied@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canOrganize: false);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 512,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 5121,
            'message_id' => '<workspace-classification-denied@example.test>',
            'subject' => 'Workspace classification denied message',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Classification denied body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 512,
            'imap_uid' => 5121,
            'provider_seen' => true,
        ]);
        $category = Category::create([
            'name' => 'Operations',
            'slug' => 'operations',
            'type' => Category::TYPE_EMAIL,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertDontSee('Category and tags')
            ->assertDontSeeHtml('id="mail-classification-category"')
            ->assertDontSee('Apply')
            ->call('toggleClassificationEditor')
            ->assertSee('You need mailbox Organize access before changing email category or tags.')
            ->set('classificationCategoryId', $category->id)
            ->set('classificationTagsInput', 'Operations')
            ->call('saveClassification')
            ->assertSee('You need mailbox Organize access before changing email category or tags.');

        $this->assertDatabaseMissing('email_conversation_classifications', [
            'account_id' => $account->id,
        ]);
    }

    #[Test]
    public function mail_workspace_can_create_unknown_tags_when_user_can_manage_taxonomy_tags(): void
    {
        Permission::findOrCreate('taxonomy.manage_tags', 'web');
        $this->tech->givePermissionTo('taxonomy.manage_tags');

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-classification-new-tag@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 513,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 5131,
            'message_id' => '<workspace-classification-new-tag@example.test>',
            'subject' => 'Workspace classification new tag message',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Classification new tag body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 513,
            'imap_uid' => 5131,
            'provider_seen' => true,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->call('toggleClassificationEditor')
            ->assertSeeHtml('id="mail-classification-tags"')
            ->set('classificationTagsInput', 'Client follow-up, Escalation')
            ->call('saveClassification')
            ->assertSee('Client follow-up')
            ->assertSee('Escalation');

        $this->assertDatabaseHas('tags', [
            'name' => 'Client follow-up',
            'slug' => 'client-follow-up',
            'active' => true,
        ]);
        $this->assertDatabaseHas('tags', [
            'name' => 'Escalation',
            'slug' => 'escalation',
            'active' => true,
        ]);

        $conversationId = $placement->fresh()->email_conversation_id;
        $this->assertNotNull($conversationId);

        $classification = EmailConversationClassification::query()
            ->with('tags')
            ->where('account_id', $account->id)
            ->where('email_conversation_id', $conversationId)
            ->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['Client follow-up', 'Escalation'],
            $classification->tags->pluck('name')->all(),
        );
    }

    #[Test]
    public function conversation_classification_does_not_cross_mailbox_accounts(): void
    {
        $firstAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'classification-account-one@example.test',
        ]));
        $secondAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'classification-account-two@example.test',
            'imap_username' => 'classification-account-two@example.test',
            'smtp_username' => 'classification-account-two@example.test',
        ]));
        $this->grantMailbox($firstAccount, $this->tech);
        $this->grantMailbox($secondAccount, $this->tech);

        $placements = collect([$firstAccount, $secondAccount])->map(function (EmailAccount $account, int $index): EmailMailboxPlacement {
            $folder = EmailFolder::create([
                'account_id' => $account->id,
                'path' => 'INBOX',
                'name' => 'INBOX',
                'role' => EmailFolder::ROLE_INBOX,
                'is_selectable' => true,
                'sync_enabled' => true,
                'uid_validity' => 514 + $index,
            ]);
            $message = EmailMessage::create([
                'account_id' => $account->id,
                'mailbox' => 'INBOX',
                'imap_uid' => 5141 + $index,
                'message_id' => '<classification-shared-id@example.test>',
                'subject' => 'Account-scoped classification',
                'from_email' => 'sender@example.test',
                'received_at' => now(),
                'state' => 'untriaged',
                'body_text' => 'Classification account boundary.',
            ]);
            $placement = EmailMailboxPlacement::create([
                'email_message_id' => $message->id,
                'account_id' => $account->id,
                'email_folder_id' => $folder->id,
                'folder_path' => 'INBOX',
                'imap_uid_validity' => 514 + $index,
                'imap_uid' => 5141 + $index,
                'provider_seen' => true,
            ]);
            app(EmailConversationProjector::class)->assignPlacement($placement);

            return $placement->fresh();
        });

        $category = Category::create([
            'name' => 'Account one category',
            'slug' => 'account-one-category',
            'type' => Category::TYPE_EMAIL,
            'is_active' => true,
        ]);
        $tag = Tag::create([
            'name' => 'Account one tag',
            'slug' => 'account-one-tag',
            'active' => true,
        ]);

        app(UpdateEmailConversationClassification::class)->handle(
            $placements[0],
            $this->tech,
            $category->id,
            [$tag->name],
        );

        $this->assertNotSame($placements[0]->email_conversation_id, $placements[1]->email_conversation_id);
        $this->assertDatabaseHas('email_conversation_classifications', [
            'account_id' => $firstAccount->id,
            'email_conversation_id' => $placements[0]->email_conversation_id,
            'category_id' => $category->id,
        ]);
        $this->assertDatabaseMissing('email_conversation_classifications', [
            'account_id' => $secondAccount->id,
            'email_conversation_id' => $placements[1]->email_conversation_id,
        ]);
    }

    #[Test]
    public function conversation_classification_rejects_non_email_categories(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'classification-category-boundary@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 516,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 5161,
            'message_id' => '<classification-category-boundary@example.test>',
            'subject' => 'Classification category boundary',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Classification category boundary.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 516,
            'imap_uid' => 5161,
            'provider_seen' => true,
        ]);
        $ticketCategory = Category::create([
            'name' => 'Ticket-only category',
            'slug' => 'ticket-only-category',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->call('toggleClassificationEditor')
            ->assertDontSee('Ticket-only category')
            ->set('classificationCategoryId', $ticketCategory->id)
            ->call('saveClassification')
            ->assertSee('Select an active Email category.');

        $this->assertDatabaseMissing('email_conversation_classifications', [
            'account_id' => $account->id,
        ]);
    }

    #[Test]
    public function mail_workspace_archive_hides_source_after_provider_acknowledgement(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-archive@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $inbox = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 601,
        ]);
        $archive = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 602,
        ]);
        $inboxNamespace = $this->activeUidNamespace($inbox);
        $this->activeUidNamespace($archive);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6011,
            'message_id' => '<workspace-archive@example.test>',
            'subject' => 'Workspace archive provider message',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Archive provider action body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $inbox->id,
            'uid_namespace_id' => $inboxNamespace->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 601,
            'imap_uid' => 6011,
            'provider_seen' => true,
        ]);

        $client = new class($account) extends ImapClient
        {
            public array $moveCalls = [];

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $folderPath === 'INBOX' ? 601 : 602];
            }

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->moveCalls[] = [$uid, $sourceFolderPath, $targetFolderPath];

                return [
                    'ok' => true,
                    'target_folder_path' => $targetFolderPath,
                    'target_imap_uid' => 9901,
                    'target_uid_validity' => 602,
                    'target_uid_authoritative' => true,
                ];
            }

            public function disconnect(): void {}
        };

        $this->app->bind(ImapClient::class, fn () => $client);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->assertSee('Workspace archive provider message')
            ->call('selectPlacement', $placement->id)
            ->assertSee('Archive')
            ->call('archiveSelected')
            ->assertSee('Message was archived in the mailbox.')
            ->assertDontSeeHtml('id="mail-conversation-row-'.$placement->id.'"');

        $this->assertSame([[6011, 'INBOX', 'Archive']], $client->moveCalls);

        $placement->refresh();
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->local_state);

        $this->assertDatabaseHas('email_mailbox_placements', [
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $archive->id,
            'folder_path' => 'Archive',
            'imap_uid_validity' => 602,
            'imap_uid' => 9901,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
        $this->assertDatabaseHas('email_remote_operations', [
            'email_mailbox_placement_id' => $placement->id,
            'operation_type' => 'archive',
            'status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            'source_folder_path' => 'INBOX',
            'target_folder_path' => 'Archive',
        ]);
    }

    #[Test]
    public function mail_workspace_moves_message_to_selected_provider_folder(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-move@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $inbox = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 617,
        ]);
        $projectFolder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Projects/Client',
            'name' => 'Client',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 618,
        ]);
        $inboxNamespace = $this->activeUidNamespace($inbox);
        $this->activeUidNamespace($projectFolder);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6171,
            'message_id' => '<workspace-move@example.test>',
            'subject' => 'Workspace move provider message',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Move provider action body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $inbox->id,
            'uid_namespace_id' => $inboxNamespace->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 617,
            'imap_uid' => 6171,
            'provider_seen' => true,
        ]);

        $client = new class($account) extends ImapClient
        {
            public array $moveCalls = [];

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $folderPath === 'INBOX' ? 617 : 618];
            }

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->moveCalls[] = [$uid, $sourceFolderPath, $targetFolderPath];

                return [
                    'ok' => true,
                    'target_folder_path' => $targetFolderPath,
                    'target_imap_uid' => 9917,
                    'target_uid_validity' => 618,
                    'target_uid_authoritative' => true,
                ];
            }

            public function disconnect(): void {}
        };

        $this->app->bind(ImapClient::class, fn () => $client);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->assertSee('Workspace move provider message')
            ->call('selectPlacement', $placement->id)
            ->call('toggleMovePanel')
            ->assertSet('movePanelOpen', true)
            ->assertSet('moveTargetFolderId', $projectFolder->id)
            ->call('moveSelectedToFolder')
            ->assertSee('Message was moved to Projects/Client.')
            ->assertDontSeeHtml('id="mail-conversation-row-'.$placement->id.'"');

        $this->assertSame([[6171, 'INBOX', 'Projects/Client']], $client->moveCalls);

        $placement->refresh();
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->local_state);

        $this->assertDatabaseHas('email_mailbox_placements', [
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $projectFolder->id,
            'folder_path' => 'Projects/Client',
            'imap_uid_validity' => 618,
            'imap_uid' => 9917,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
        $this->assertDatabaseHas('email_remote_operations', [
            'email_mailbox_placement_id' => $placement->id,
            'operation_type' => 'move',
            'status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            'source_folder_path' => 'INBOX',
            'target_folder_path' => 'Projects/Client',
        ]);
    }

    #[Test]
    public function personal_mailbox_owner_can_create_simple_move_rule_from_mail_workspace(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'personal-rules@example.test',
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $this->tech->id,
            'ticket_ingress_enabled' => false,
        ]));
        $inbox = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 721,
        ]);
        $targetFolder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Projects/VIP',
            'name' => 'VIP',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 722,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7211,
            'message_id' => '<personal-rule-source@example.test>',
            'subject' => 'Personal rule source',
            'from_email' => 'vip@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Personal rule source body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $inbox->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 721,
            'imap_uid' => 7211,
            'provider_seen' => true,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertSee('Personal rule source')
            ->call('openRuleAction')
            ->assertSet('personalRuleModalOpen', true)
            ->assertSee('Rule history')
            ->set('personalRuleTargetFolderId', $targetFolder->id)
            ->call('createPersonalRule')
            ->assertSet('personalRuleModalOpen', false)
            ->assertSee('Personal rule "Move mail from vip@example.test" was created.');

        $rule = EmailRule::query()->where('name', 'Move mail from vip@example.test')->firstOrFail();

        $this->assertSame(EmailRule::KIND_PERSONAL_SIMPLE, $rule->rule_kind);
        $this->assertSame(EmailRule::ROUTING_PHASE_PERSONAL, $rule->routing_phase);
        $this->assertSame($this->tech->id, $rule->owner_id);
        $this->assertSame([$account->id], $rule->accounts()->pluck('email_accounts.id')->all());
        $this->assertSame([
            ['field' => 'from', 'operator' => 'equals', 'value' => 'vip@example.test'],
        ], $rule->conditions_json);
        $this->assertSame(CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER, $rule->actions_json[0]['type']);
        $this->assertSame($targetFolder->id, $rule->actions_json[0]['target_folder_id']);

        $version = $rule->publishedVersion()->firstOrFail();
        $this->assertSame(EmailRule::KIND_PERSONAL_SIMPLE, $version->rule_kind);
        $this->assertSame($this->tech->id, $version->owner_id);
    }

    #[Test]
    public function personal_simple_rule_runs_for_future_personal_mail_without_ticket_ingress(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'personal-rule-runner@example.test',
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $this->tech->id,
            'ticket_ingress_enabled' => false,
        ]));
        $inbox = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 731,
        ]);
        $targetFolder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Projects/Auto',
            'name' => 'Auto',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 732,
        ]);
        $inboxNamespace = $this->activeUidNamespace($inbox);
        $this->activeUidNamespace($targetFolder);
        $seedMessage = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7310,
            'message_id' => '<personal-rule-seed@example.test>',
            'subject' => 'Personal rule seed',
            'from_email' => 'vip@example.test',
            'received_at' => now()->subMinute(),
            'state' => 'untriaged',
            'body_text' => 'Seed message.',
        ]);
        $seedPlacement = EmailMailboxPlacement::create([
            'email_message_id' => $seedMessage->id,
            'account_id' => $account->id,
            'email_folder_id' => $inbox->id,
            'uid_namespace_id' => $inboxNamespace->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 731,
            'imap_uid' => 7310,
            'provider_seen' => true,
        ]);

        $rule = app(CreatePersonalEmailRule::class)->handle($seedPlacement, $this->tech, [
            'name' => 'Move VIP personal mail',
            'condition_field' => 'from',
            'condition_value' => 'vip@example.test',
            'action_type' => CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER,
            'target_folder_id' => $targetFolder->id,
        ]);

        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7311,
            'message_id' => '<personal-rule-future@example.test>',
            'subject' => 'Future personal match',
            'from_email' => 'vip@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Future personal rule body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $inbox->id,
            'uid_namespace_id' => $inboxNamespace->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 731,
            'imap_uid' => 7311,
            'provider_seen' => true,
        ]);

        $client = new class($account) extends ImapClient
        {
            public array $moveCalls = [];

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $folderPath === 'INBOX' ? 731 : 732];
            }

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->moveCalls[] = [$uid, $sourceFolderPath, $targetFolderPath];

                return [
                    'ok' => true,
                    'target_folder_path' => $targetFolderPath,
                    'target_imap_uid' => 9732,
                    'target_uid_validity' => 732,
                    'target_uid_authoritative' => true,
                ];
            }

            public function disconnect(): void {}
        };

        $this->app->bind(ImapClient::class, fn () => $client);

        app()->call([new ProcessInboundRules($message->id, true), 'handle']);
        app()->call([new ProcessInboundRules($message->id, true), 'handle']);

        $this->assertSame([[7311, 'INBOX', 'Projects/Auto']], $client->moveCalls);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);
        $this->assertNull($message->fresh()->ticket_id);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $targetFolder->id,
            'folder_path' => 'Projects/Auto',
            'imap_uid' => 9732,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
        $this->assertSame(1, $rule->fresh()->hit_count);

        $attempt = EmailRuleExecutionAttempt::query()
            ->where('email_rule_id', $rule->id)
            ->where('email_message_id', $message->id)
            ->firstOrFail();
        $this->assertSame(EmailRuleExecutionAttempt::STATUS_SUCCEEDED, $attempt->status);
        $this->assertSame(EmailRule::ROUTING_PHASE_PERSONAL, $attempt->routing_phase);
        $this->assertSame($placement->id, $attempt->email_mailbox_placement_id);
    }

    #[Test]
    public function shared_or_system_mailbox_rule_action_redirects_rule_managers_to_admin_builder(): void
    {
        $this->tech->givePermissionTo('email.rule_manage');

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'system-rules@example.test',
            'account_kind' => EmailAccount::KIND_SYSTEM,
        ]));
        $this->grantMailbox($account, $this->tech);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 741,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7411,
            'message_id' => '<system-rule-source@example.test>',
            'subject' => 'System rule source',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'System rule source body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 741,
            'imap_uid' => 7411,
            'provider_seen' => true,
        ]);

        $expectedUrl = route('tech.admin.settings.email.rules.create', [
            'account_id' => $account->id,
            'condition_field' => 'from',
            'condition_value' => 'sender@example.test',
            'name' => 'Rule for sender@example.test',
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertSee('Add rule')
            ->call('openRuleAction')
            ->assertRedirect($expectedUrl);

        $this->actingAs($this->tech)
            ->get($expectedUrl)
            ->assertOk()
            ->assertSee('system-rules@example.test')
            ->assertSee('sender@example.test');
    }

    #[Test]
    public function mail_workspace_spam_action_updates_rule_and_archives_provider_placement(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-spam-action@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $inbox = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 611,
        ]);
        $archive = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 612,
        ]);
        $inboxNamespace = $this->activeUidNamespace($inbox);
        $this->activeUidNamespace($archive);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6111,
            'message_id' => '<workspace-spam-action@example.test>',
            'subject' => 'Spam provider action message',
            'from_email' => 'spammy@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Spam provider action body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $inbox->id,
            'uid_namespace_id' => $inboxNamespace->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 611,
            'imap_uid' => 6111,
            'provider_seen' => true,
        ]);

        $client = new class($account) extends ImapClient
        {
            public array $moveCalls = [];

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $folderPath === 'INBOX' ? 611 : 612];
            }

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->moveCalls[] = [$uid, $sourceFolderPath, $targetFolderPath];

                return [
                    'ok' => true,
                    'target_folder_path' => $targetFolderPath,
                    'target_imap_uid' => 9902,
                    'target_uid_validity' => 612,
                    'target_uid_authoritative' => true,
                ];
            }

            public function disconnect(): void {}
        };

        $this->app->bind(ImapClient::class, fn () => $client);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->assertSee('Spam provider action message')
            ->call('selectPlacement', $placement->id)
            ->assertSee('Mark as spam')
            ->call('markSelectedSpam')
            ->assertSee('Message was marked as spam')
            ->assertDontSeeHtml('id="mail-conversation-row-'.$placement->id.'"');

        $this->assertSame([[6111, 'INBOX', 'Archive']], $client->moveCalls);
        $this->assertSame('archived', $message->fresh()->state);
        $this->assertDatabaseHas('email_rules', [
            'name' => 'Spam: spammy@example.test',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'is_active' => true,
            'stop_processing' => true,
        ]);
        $this->assertTrue($message->fresh()->tags()->where('tags.name', 'spam')->exists());

        $placement->refresh();
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->local_state);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $archive->id,
            'folder_path' => 'Archive',
            'imap_uid_validity' => 612,
            'imap_uid' => 9902,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
    }

    #[Test]
    public function mail_workspace_ticket_action_creates_ticket_when_authorized(): void
    {
        Permission::findOrCreate('ticket.create', 'web');
        $this->tech->givePermissionTo('ticket.create');
        app(EnsureTicketDefaults::class)->handle();

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ticket-action@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 621,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6211,
            'message_id' => '<workspace-ticket-action@example.test>',
            'subject' => 'Create ticket from Mail',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Please create a ticket from this Mail message.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 621,
            'imap_uid' => 6211,
            'provider_seen' => true,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertSee('Create or link Ticket')
            ->call('createTicketForSelected')
            ->assertSee('was linked from the selected email');

        $ticket = Ticket::query()->sole();

        $this->assertSame('Create ticket from Mail', $ticket->subject);
        $this->assertSame($ticket->id, $message->fresh()->ticket_id);
        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Please create a ticket from this Mail message.',
        ]);
    }

    #[Test]
    public function mail_workspace_ticket_action_requires_ticket_create_permission(): void
    {
        app(EnsureTicketDefaults::class)->handle();

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ticket-denied@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 622,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6221,
            'message_id' => '<workspace-ticket-denied@example.test>',
            'subject' => 'Denied ticket action',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'No ticket permission.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 622,
            'imap_uid' => 6221,
            'provider_seen' => true,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertDontSee('Create or link Ticket')
            ->call('createTicketForSelected')
            ->assertSee('You need Ticket create permission');

        $this->assertSame(0, Ticket::query()->count());
        $this->assertNull($message->fresh()->ticket_id);
    }

    #[Test]
    public function mail_workspace_ai_can_draft_reply_without_sending_or_changing_recipients(): void
    {
        $agent = $this->readyDefaultMailAgent();

        Http::fake([
            'http://ollama-mail-ai.test/api/chat' => Http::response([
                'model' => 'mail-fallback-test',
                'message' => [
                    'content' => json_encode([
                        'body' => "Hello,\n\nWe will restart the backup job and confirm when it is complete.",
                        'subject' => null,
                        'confidence' => 0.88,
                        'warnings' => ['Review before sending.'],
                        'provenance' => [
                            'source_message_ids' => [],
                            'limitations' => ['Only authorized message text was included.'],
                        ],
                    ]),
                ],
            ], 200),
        ]);

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ai-draft@example.test',
            'from_name' => 'Workspace AI Draft',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 641,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6411,
            'message_id' => '<workspace-ai-draft@example.test>',
            'subject' => 'Backup restart',
            'from_name' => 'Customer Admin',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => 'workspace-ai-draft@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Please restart the backup job and let us know when it is done.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 641,
            'imap_uid' => 6411,
            'provider_seen' => false,
        ]);

        $component = Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->call('startReply')
            ->assertSee('Optional AI guidance')
            ->assertSet('composerTo', 'customer@example.test')
            ->assertSet('composerSubject', 'Re: Backup restart')
            ->call('applyComposerAi', AssistEmailComposerWithAi::INTENT_DRAFT_REPLY)
            ->assertSet('mailActionStatus', null)
            ->assertSet('composerActionStatus.type', 'success')
            ->assertSee('AI draft was applied to the composer');

        $this->assertSame('customer@example.test', $component->get('composerTo'));
        $this->assertSame('Re: Backup restart', $component->get('composerSubject'));
        $this->assertStringContainsString('We will restart the backup job', (string) $component->get('composerBodyHtml'));
        $this->assertSame(['Review before sending.'], $component->get('composerAiResult.warnings'));
        $this->assertSame('default_agent', data_get($component->get('composerAiResult'), 'metadata.source'));
        $this->assertSame($agent->id, data_get($component->get('composerAiResult'), 'metadata.agent_id'));
        $this->assertSame(0, EmailLog::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, EmailRemoteOperation::query()->count());

        Http::assertSent(function ($request): bool {
            $payload = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return $request->url() === 'http://ollama-mail-ai.test/api/chat'
                && $request['model'] === 'mail-fallback-test'
                && str_contains((string) $payload, 'reply_recommended')
                && str_contains((string) $payload, 'user_notice')
                && str_contains((string) $payload, AssistEmailComposerWithAi::INTENT_DRAFT_REPLY)
                && str_contains((string) $payload, SendEmailComposerMessage::MODE_REPLY)
                && str_contains((string) $payload, 'Re: Backup restart')
                && str_contains((string) $payload, 'attachments_included')
                && str_contains((string) $payload, 'recipients_are_not_changed')
                && str_contains((string) $payload, 'send_is_not_allowed')
                && ! str_contains((string) $payload, 'current_body_html')
                && ! str_contains((string) $payload, 'attachments":');
        });
    }

    #[Test]
    public function mail_workspace_ai_no_reply_advice_does_not_replace_composer_body(): void
    {
        $this->readyDefaultMailAgent();

        Http::fake([
            'http://ollama-mail-ai.test/api/chat' => Http::response([
                'model' => 'mail-fallback-test',
                'message' => [
                    'content' => json_encode([
                        'body' => 'This appears to be an automated RMM alert; consider whether a reply is necessary before sending.',
                        'subject' => null,
                        'confidence' => 0.76,
                        'warnings' => [],
                        'provenance' => [
                            'source_message_ids' => [],
                            'limitations' => ['Only authorized message text was included.'],
                        ],
                    ]),
                ],
            ], 200),
        ]);

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ai-no-reply@example.test',
            'from_name' => 'Workspace AI No Reply',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 644,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6441,
            'message_id' => '<workspace-ai-no-reply@example.test>',
            'subject' => 'RMM alert: backup monitor',
            'from_name' => 'RMM Monitor',
            'from_email' => 'alerts@example.test',
            'to_json' => [['email' => 'workspace-ai-no-reply@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Backup monitor notification. Status: recovered. This is an automated RMM alert.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 644,
            'imap_uid' => 6441,
            'provider_seen' => false,
        ]);

        $component = Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->call('startReply');
        $originalBody = $component->get('composerBodyHtml');

        $component
            ->call('applyComposerAi', AssistEmailComposerWithAi::INTENT_DRAFT_REPLY)
            ->assertSet('mailActionStatus', null)
            ->assertSet('composerActionStatus.type', 'info')
            ->assertSee('automated RMM alert')
            ->assertDontSee('AI draft was applied to the composer');

        $this->assertSame($originalBody, $component->get('composerBodyHtml'));
        $this->assertSame('alerts@example.test', $component->get('composerTo'));
        $this->assertSame('Re: RMM alert: backup monitor', $component->get('composerSubject'));
        $this->assertFalse($component->get('composerAiResult.applied'));
        $this->assertFalse($component->get('composerAiResult.reply_recommended'));
        $this->assertSame(0, EmailLog::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, EmailRemoteOperation::query()->count());
    }

    #[Test]
    public function mail_workspace_ai_can_draft_reply_with_action_capable_default_email_agent(): void
    {
        $agent = $this->readyDefaultMailAgent([
            'can_execute_actions' => true,
            'data_sources' => ['email', 'tickets'],
            'allowed_tools' => ['tickets.update'],
            'allowed_api_scopes' => ['email.read', 'tickets.update'],
        ]);

        Http::fake([
            'http://ollama-mail-ai.test/api/chat' => Http::response([
                'model' => 'mail-fallback-test',
                'message' => [
                    'content' => json_encode([
                        'body' => "Hello,\n\nWe will check the fallback-agent path and reply with status.",
                        'subject' => 'Do not use this returned subject',
                        'confidence' => 0.78,
                        'warnings' => ['Default agent used; review before sending.'],
                        'provenance' => [
                            'source_message_ids' => [],
                            'limitations' => ['Default Email agent produced this draft without write actions.'],
                        ],
                    ]),
                ],
            ], 200),
        ]);

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ai-default-draft@example.test',
            'from_name' => 'Workspace AI Default Draft',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 643,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6431,
            'message_id' => '<workspace-ai-default-draft@example.test>',
            'subject' => 'Fallback draft',
            'from_name' => 'Customer Admin',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => 'workspace-ai-default-draft@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Please check whether the fallback AI path works.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 643,
            'imap_uid' => 6431,
            'provider_seen' => false,
        ]);

        $component = Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->call('startReply')
            ->assertSee('Optional AI guidance')
            ->call('applyComposerAi', AssistEmailComposerWithAi::INTENT_DRAFT_REPLY)
            ->assertSet('mailActionStatus', null)
            ->assertSet('composerActionStatus.type', 'success')
            ->assertSee('AI draft was applied to the composer');

        $this->assertSame('customer@example.test', $component->get('composerTo'));
        $this->assertSame('Re: Fallback draft', $component->get('composerSubject'));
        $this->assertStringContainsString('fallback-agent path', (string) $component->get('composerBodyHtml'));
        $this->assertSame('default_agent', data_get($component->get('composerAiResult'), 'metadata.source'));
        $this->assertSame($agent->id, data_get($component->get('composerAiResult'), 'metadata.agent_id'));
        $this->assertSame(0, EmailLog::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, EmailRemoteOperation::query()->count());

        Http::assertSent(function ($request): bool {
            $payload = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return $request->url() === 'http://ollama-mail-ai.test/api/chat'
                && $request['model'] === 'mail-fallback-test'
                && str_contains((string) $payload, 'recipients_are_not_changed')
                && str_contains((string) $payload, 'send_is_not_allowed');
        });
    }

    #[Test]
    public function mail_workspace_ai_can_rewrite_existing_reply_with_user_instruction(): void
    {
        $this->readyDefaultMailAgent();

        Http::fake([
            'http://ollama-mail-ai.test/api/chat' => Http::response([
                'model' => 'mail-fallback-test',
                'message' => [
                    'content' => json_encode([
                        'body' => 'Hei, takk for beskjed. Vi undersoker saken og kommer tilbake med status.',
                        'subject' => null,
                        'confidence' => 0.82,
                        'warnings' => [],
                        'provenance' => [
                            'source_message_ids' => [],
                            'limitations' => ['Current composer text was used as the rewrite source.'],
                        ],
                    ]),
                ],
            ], 200),
        ]);

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ai-rewrite@example.test',
            'from_name' => 'Workspace AI Rewrite',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 642,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6421,
            'message_id' => '<workspace-ai-rewrite@example.test>',
            'subject' => 'Router unstable',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => 'workspace-ai-rewrite@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'The router has been unstable since yesterday.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 642,
            'imap_uid' => 6421,
            'provider_seen' => false,
        ]);

        $component = Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->call('startReply')
            ->set('composerBodyHtml', '<p>We look at it soon, ok?</p>')
            ->set('composerAiInstruction', 'Svar pa norsk og hold tonen rolig.')
            ->call('applyComposerAi', AssistEmailComposerWithAi::INTENT_TRANSLATE_NORWEGIAN)
            ->assertSet('mailActionStatus', null)
            ->assertSet('composerActionStatus.type', 'success')
            ->assertSee('AI draft was applied to the composer');

        $this->assertStringContainsString('takk for beskjed', (string) $component->get('composerBodyHtml'));
        $this->assertSame('customer@example.test', $component->get('composerTo'));
        $this->assertSame(0, EmailLog::query()->where('direction', 'outbound')->count());

        Http::assertSent(function ($request): bool {
            $payload = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return $request->url() === 'http://ollama-mail-ai.test/api/chat'
                && $request['model'] === 'mail-fallback-test'
                && str_contains((string) $payload, AssistEmailComposerWithAi::INTENT_TRANSLATE_NORWEGIAN)
                && str_contains((string) $payload, 'We look at it soon, ok?')
                && str_contains((string) $payload, 'Svar pa norsk og hold tonen rolig.');
        });
    }

    #[Test]
    public function mail_workspace_ai_can_improve_new_compose_without_selected_message(): void
    {
        $this->readyDefaultMailAgent();

        Http::fake([
            'http://ollama-mail-ai.test/api/chat' => Http::response([
                'model' => 'mail-fallback-test',
                'message' => [
                    'content' => json_encode([
                        'body' => 'Hello,\n\nThanks for the details. We will review the request and follow up with the next step.',
                        'subject' => null,
                        'confidence' => 0.78,
                        'warnings' => [],
                        'provenance' => [
                            'source_message_ids' => [],
                            'limitations' => ['No source email was provided for this compose rewrite.'],
                        ],
                    ]),
                ],
            ], 200),
        ]);

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ai-compose@example.test',
            'from_name' => 'Workspace AI Compose',
        ]));
        $this->grantMailbox($account, $this->tech, canView: false, canOrganize: false, canSend: true);

        $component = Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->assertSet('composerMode', SendEmailComposerMessage::MODE_COMPOSE)
            ->assertSee('Optional AI guidance')
            ->assertDontSee('Draft reply')
            ->set('composerTo', 'customer@example.test')
            ->set('composerSubject', 'Follow-up')
            ->set('composerBodyHtml', '<p>thanks. we check and come back.</p>')
            ->call('applyComposerAi', AssistEmailComposerWithAi::INTENT_IMPROVE)
            ->assertSet('mailActionStatus', null)
            ->assertSet('composerActionStatus.type', 'success')
            ->assertSee('AI draft was applied to the composer');

        $this->assertStringContainsString('Thanks for the details', (string) $component->get('composerBodyHtml'));
        $this->assertSame('customer@example.test', $component->get('composerTo'));
        $this->assertSame('Follow-up', $component->get('composerSubject'));
        $this->assertSame(0, EmailLog::query()->where('direction', 'outbound')->count());

        Http::assertSent(function ($request): bool {
            $payload = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return $request->url() === 'http://ollama-mail-ai.test/api/chat'
                && $request['model'] === 'mail-fallback-test'
                && str_contains((string) $payload, AssistEmailComposerWithAi::INTENT_IMPROVE)
                && str_contains((string) $payload, 'compose')
                && str_contains((string) $payload, 'thanks. we check and come back.')
                && str_contains((string) $payload, 'selected_message_id');
        });
    }

    #[Test]
    public function mail_workspace_ai_can_rewrite_forward_intro_without_losing_forwarded_message(): void
    {
        $this->readyDefaultMailAgent();

        Http::fake([
            'http://ollama-mail-ai.test/api/chat' => Http::response([
                'model' => 'mail-fallback-test',
                'message' => [
                    'content' => json_encode([
                        'body' => 'Hi,\n\nPlease see the forwarded details below.',
                        'subject' => null,
                        'confidence' => 0.81,
                        'warnings' => [],
                        'provenance' => [
                            'source_message_ids' => [],
                            'limitations' => ['Forwarded original message content was preserved by the application.'],
                        ],
                    ]),
                ],
            ], 200),
        ]);

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ai-forward@example.test',
            'from_name' => 'Workspace AI Forward',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 644,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6441,
            'message_id' => '<workspace-ai-forward@example.test>',
            'subject' => 'Forward source',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => 'workspace-ai-forward@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Original outage details must stay in the forwarded block.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 644,
            'imap_uid' => 6441,
            'provider_seen' => false,
        ]);

        $component = Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->call('startForward')
            ->assertSet('composerMode', SendEmailComposerMessage::MODE_FORWARD)
            ->assertSee('Optional AI guidance')
            ->assertDontSee('Draft reply');

        $forwardedBlock = strstr((string) $component->get('composerBodyHtml'), EmailSignatureRenderer::FORWARDED_MESSAGE_MARKER);
        $this->assertIsString($forwardedBlock);

        $component
            ->set('composerBodyHtml', '<p>fyi</p>'.$forwardedBlock)
            ->call('applyComposerAi', AssistEmailComposerWithAi::INTENT_FRIENDLY)
            ->assertSet('mailActionStatus', null)
            ->assertSet('composerActionStatus.type', 'success')
            ->assertSee('AI draft was applied to the composer');

        $bodyHtml = (string) $component->get('composerBodyHtml');

        $this->assertStringContainsString('Please see the forwarded details below.', $bodyHtml);
        $this->assertStringContainsString(EmailSignatureRenderer::FORWARDED_MESSAGE_MARKER, $bodyHtml);
        $this->assertStringContainsString('Forwarded message', $bodyHtml);
        $this->assertStringContainsString('Original outage details must stay in the forwarded block.', $bodyHtml);
        $this->assertSame('', $component->get('composerTo'));
        $this->assertSame('Fwd: Forward source', $component->get('composerSubject'));
        $this->assertSame(0, EmailLog::query()->where('direction', 'outbound')->count());

        Http::assertSent(function ($request): bool {
            $payload = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return $request->url() === 'http://ollama-mail-ai.test/api/chat'
                && $request['model'] === 'mail-fallback-test'
                && str_contains((string) $payload, AssistEmailComposerWithAi::INTENT_FRIENDLY)
                && str_contains((string) $payload, 'forward')
                && str_contains((string) $payload, 'fyi');
        });
    }

    #[Test]
    public function mail_workspace_reply_sends_rich_html_with_to_cc_attachment_and_threading_headers(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-reply@example.test',
            'from_name' => 'Workspace Reply',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 651,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6511,
            'message_id' => '<source-message@example.test>',
            'subject' => 'Need help with printer',
            'from_name' => 'Customer Contact',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => 'workspace-reply@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'references' => '<older-message@example.test>',
            'body_text' => 'Can you help?',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 651,
            'imap_uid' => 6511,
            'provider_seen' => false,
        ]);
        $signature = EmailSignature::create([
            'user_id' => $this->tech->id,
            'name' => 'Reply signature',
            'body_html' => '<p>Regards<br>Workspace Signature</p>',
            'body_text' => 'Regards Workspace Signature',
            'use_on_compose' => true,
            'use_on_reply' => true,
            'use_on_reply_all' => true,
            'use_on_forward' => true,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        $mailer = new class extends SmtpAccountMailer
        {
            public array $calls = [];

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls[] = compact('account', 'toRecipients', 'subject', 'html', 'text', 'attachments', 'ccRecipients', 'options');

                return '<reply-message@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        $attachment = UploadedFile::fake()->createWithContent('diagnostics.txt', 'printer logs');

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertSee('Reply')
            ->assertSee('Forward')
            ->call('startReply')
            ->assertSet('composerMode', SendEmailComposerMessage::MODE_REPLY)
            ->assertSet('composerTo', 'customer@example.test')
            ->assertSet('composerSubject', 'Re: Need help with printer')
            ->set('composerCc', 'manager@example.test')
            ->set('composerBodyHtml', '<p><strong>We</strong> will check this today.</p><script>alert(1)</script>')
            ->set('composerAttachments', [$attachment])
            ->call('sendComposer')
            ->assertSee('Reply sent from workspace-reply@example.test.');

        $this->assertCount(1, $mailer->calls);
        $call = $mailer->calls[0];

        $this->assertSame([['email' => 'customer@example.test', 'name' => '']], $call['toRecipients']);
        $this->assertSame([['email' => 'manager@example.test', 'name' => '']], $call['ccRecipients']);
        $this->assertSame('Re: Need help with printer', $call['subject']);
        $this->assertStringContainsString('We will check this today.', $call['text']);
        $this->assertStringContainsString('Workspace Signature', $call['text']);
        $this->assertStringContainsString('<strong>We</strong>', $call['html']);
        $this->assertStringContainsString('nexum-mail-signature:start', $call['html']);
        $this->assertStringContainsString('Workspace Signature', $call['html']);
        $this->assertStringNotContainsString('<script', $call['html']);
        $this->assertSame('<source-message@example.test>', $call['options']['in_reply_to']);
        $this->assertSame('<older-message@example.test> <source-message@example.test>', $call['options']['references']);
        $this->assertSame('diagnostics.txt', $call['attachments'][0]['filename']);

        $log = EmailLog::query()->where('code', 'MAIL_REPLY_SENT')->sole();

        $this->assertSame('outbound', $log->direction);
        $this->assertSame('inbox', $log->scope);
        $this->assertSame($account->id, $log->account_id);
        $this->assertSame($message->id, $log->email_message_id);
        $this->assertSame('<reply-message@example.test>', $log->rfc_message_id);
        $this->assertSame(['customer@example.test'], $log->context_json['to']);
        $this->assertSame(['manager@example.test'], $log->context_json['cc']);
        $this->assertSame('reply', $log->context_json['mode']);
        $this->assertSame(1, $log->context_json['attachments_count']);
        $this->assertTrue($log->context_json['signature_applied']);
        $this->assertSame($signature->id, $log->context_json['signature_id']);
        $this->assertSame('stored_signature', $log->context_json['signature_source']);
        $this->assertNotEmpty($log->idempotency_key);
    }

    #[Test]
    public function mail_workspace_reply_all_sends_to_thread_recipients_without_self(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-reply-all@example.test',
            'from_name' => 'Workspace Reply All',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 656,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6561,
            'message_id' => '<reply-all-source@example.test>',
            'subject' => 'Coordinate this work',
            'from_name' => 'Customer Contact',
            'from_email' => 'customer@example.test',
            'to_json' => [
                ['name' => 'Workspace Reply All', 'email' => 'workspace-reply-all@example.test'],
                ['name' => 'Escalation Desk', 'email' => 'escalation@example.test'],
            ],
            'cc_json' => [
                ['name' => 'Service Manager', 'email' => 'manager@example.test'],
                ['name' => 'Workspace Reply All', 'email' => 'workspace-reply-all@example.test'],
                ['name' => 'Escalation Desk', 'email' => 'escalation@example.test'],
            ],
            'received_at' => now(),
            'state' => 'untriaged',
            'references' => '<reply-all-root@example.test>',
            'body_text' => 'Can everyone coordinate?',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 656,
            'imap_uid' => 6561,
            'provider_seen' => false,
        ]);

        $mailer = new class extends SmtpAccountMailer
        {
            public array $calls = [];

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls[] = compact('account', 'toRecipients', 'subject', 'html', 'text', 'attachments', 'ccRecipients', 'options');

                return '<reply-all-message@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertSee('Reply all')
            ->call('startReplyAll')
            ->assertSet('composerMode', SendEmailComposerMessage::MODE_REPLY_ALL)
            ->assertSet('composerTo', 'Customer Contact <customer@example.test>, Escalation Desk <escalation@example.test>')
            ->assertSet('composerCc', 'Service Manager <manager@example.test>')
            ->assertSet('composerSubject', 'Re: Coordinate this work')
            ->set('composerBodyHtml', '<p>Looping everyone in.</p>')
            ->call('sendComposer')
            ->assertSee('Reply all sent from workspace-reply-all@example.test.');

        $this->assertCount(1, $mailer->calls);
        $call = $mailer->calls[0];

        $this->assertSame([
            ['email' => 'customer@example.test', 'name' => 'Customer Contact'],
            ['email' => 'escalation@example.test', 'name' => 'Escalation Desk'],
        ], $call['toRecipients']);
        $this->assertSame([
            ['email' => 'manager@example.test', 'name' => 'Service Manager'],
        ], $call['ccRecipients']);
        $this->assertSame('<reply-all-source@example.test>', $call['options']['in_reply_to']);
        $this->assertSame('<reply-all-root@example.test> <reply-all-source@example.test>', $call['options']['references']);

        $log = EmailLog::query()->where('code', 'MAIL_REPLY_ALL_SENT')->sole();

        $this->assertSame($message->id, $log->email_message_id);
        $this->assertSame('reply_all', $log->context_json['mode']);
        $this->assertSame(['customer@example.test', 'escalation@example.test'], $log->context_json['to']);
        $this->assertSame(['manager@example.test'], $log->context_json['cc']);
    }

    #[Test]
    public function mail_workspace_hides_reply_all_when_message_has_no_additional_recipients(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-reply-all-hidden@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 657,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6571,
            'message_id' => '<reply-all-hidden@example.test>',
            'subject' => 'Direct mail',
            'from_name' => 'Customer Contact',
            'from_email' => 'customer@example.test',
            'to_json' => [
                ['name' => 'Workspace Reply All Hidden', 'email' => 'workspace-reply-all-hidden@example.test'],
            ],
            'cc_json' => [],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Just us.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 657,
            'imap_uid' => 6571,
            'provider_seen' => false,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertSee('Reply')
            ->assertDontSee('Reply all')
            ->assertSee('Forward')
            ->call('startReplyAll')
            ->assertSet('composerOpen', false)
            ->assertSee('Reply all is only available when the message has additional recipients.');
    }

    #[Test]
    public function mail_workspace_new_compose_sends_from_send_authorized_account_without_selected_message(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-compose@example.test',
            'from_name' => 'Workspace Compose',
        ]));
        $this->grantMailbox($account, $this->tech, canView: false, canOrganize: false, canSend: true);

        $mailer = new class extends SmtpAccountMailer
        {
            public array $calls = [];

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls[] = compact('account', 'toRecipients', 'subject', 'html', 'text', 'attachments', 'ccRecipients', 'options');

                return '<compose-message@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->assertSee('Compose')
            ->assertSee('No mail matches this view.')
            ->call('startCompose')
            ->assertSet('composerMode', SendEmailComposerMessage::MODE_COMPOSE)
            ->assertSet('composerAccountId', $account->id)
            ->set('composerTo', 'customer@example.test')
            ->set('composerCc', 'manager@example.test')
            ->set('composerSubject', 'New planned work')
            ->set('composerBodyHtml', '<p><strong>Hello</strong> from Nexum.</p>')
            ->call('sendComposer')
            ->assertSee('Message sent from workspace-compose@example.test.');

        $this->assertCount(1, $mailer->calls);
        $call = $mailer->calls[0];

        $this->assertTrue($account->is($call['account']));
        $this->assertSame([['email' => 'customer@example.test', 'name' => '']], $call['toRecipients']);
        $this->assertSame([['email' => 'manager@example.test', 'name' => '']], $call['ccRecipients']);
        $this->assertSame('New planned work', $call['subject']);
        $this->assertStringContainsString('Hello', $call['text']);
        $this->assertStringContainsString($this->tech->name, $call['text']);
        $this->assertStringContainsString('nexum-mail-signature:start', $call['html']);
        $this->assertNotEmpty($call['options']['message_id'] ?? null);
        $this->assertArrayNotHasKey('in_reply_to', $call['options']);
        $this->assertArrayNotHasKey('references', $call['options']);

        $log = EmailLog::query()->where('code', 'MAIL_COMPOSE_SENT')->sole();

        $this->assertSame('outbound', $log->direction);
        $this->assertSame('inbox', $log->scope);
        $this->assertSame($account->id, $log->account_id);
        $this->assertNull($log->email_message_id);
        $this->assertSame('<compose-message@example.test>', $log->rfc_message_id);
        $this->assertSame('compose', $log->context_json['mode']);
        $this->assertNull($log->context_json['source_placement_id']);
        $this->assertSame(['customer@example.test'], $log->context_json['to']);
        $this->assertSame(['manager@example.test'], $log->context_json['cc']);
        $this->assertTrue($log->context_json['signature_applied']);
        $this->assertNull($log->context_json['signature_id']);
        $this->assertSame('default_template', $log->context_json['signature_source']);
    }

    #[Test]
    public function send_pipeline_records_pending_provider_sent_reconciliation_after_smtp_success(): void
    {
        Queue::fake();

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-sent-pending@example.test',
            'from_name' => 'Workspace Sent Pending',
        ]));
        $this->grantMailbox($account, $this->tech, canView: false, canOrganize: false, canSend: true);

        $mailer = new class extends SmtpAccountMailer
        {
            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                return '<pending-sent-copy@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->set('composerTo', 'customer@example.test')
            ->set('composerSubject', 'Pending Sent reconciliation')
            ->set('composerBodyHtml', '<p>Track provider Sent later.</p>')
            ->call('sendComposer')
            ->assertSee('Message sent from workspace-sent-pending@example.test.');

        $log = EmailLog::query()->where('code', 'MAIL_COMPOSE_SENT')->sole();
        $reconciliation = EmailSentReconciliation::query()->sole();

        $this->assertSame(EmailSentReconciliation::STATUS_PENDING, $reconciliation->status);
        $this->assertSame($log->id, $reconciliation->email_log_id);
        $this->assertSame($account->id, $reconciliation->account_id);
        $this->assertSame('<pending-sent-copy@example.test>', $reconciliation->rfc_message_id);
        $this->assertSame('pending-sent-copy@example.test', $reconciliation->normalized_message_id);
        $this->assertNull($reconciliation->sent_email_mailbox_placement_id);
        $this->assertSame(EmailSentReconciliation::STATUS_PENDING, $log->fresh()->context_json['provider_sent']['status']);
        Queue::assertPushed(AppendEmailProviderSentCopy::class, 1);
    }

    #[Test]
    public function provider_sent_import_reconciles_pending_outbound_message(): void
    {
        Queue::fake();

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-sent-reconciled@example.test',
            'from_name' => 'Workspace Sent Reconciled',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);

        $mailer = new class extends SmtpAccountMailer
        {
            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                return '<reconciled-sent-copy@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->set('composerTo', 'customer@example.test')
            ->set('composerSubject', 'Provider Sent copy')
            ->set('composerBodyHtml', '<p>Track provider Sent import.</p>')
            ->call('sendComposer');

        $reconciliation = EmailSentReconciliation::query()->sole();
        $this->assertSame(EmailSentReconciliation::STATUS_PENDING, $reconciliation->status);

        app()->call([new StoreInboundMessage([
            'account_id' => $account->id,
            'mailbox' => 'Sent',
            'imap_uid' => 7701,
            'uid_validity' => 771,
            'message_id' => 'reconciled-sent-copy@example.test',
            'subject' => 'Provider Sent copy',
            'from_email' => $account->address,
            'to' => [['email' => 'customer@example.test']],
            'received_at' => now(),
            'is_oversize' => true,
            'provider_seen' => true,
        ]), 'handle']);

        $sentPlacement = EmailMailboxPlacement::query()
            ->where('account_id', $account->id)
            ->where('folder_path', 'Sent')
            ->sole();

        $reconciliation->refresh();
        $this->assertSame(EmailSentReconciliation::STATUS_RECONCILED, $reconciliation->status);
        $this->assertSame($sentPlacement->id, $reconciliation->sent_email_mailbox_placement_id);
        $this->assertSame($sentPlacement->email_message_id, $reconciliation->sent_email_message_id);
        $this->assertNotNull($reconciliation->reconciled_at);
        $this->assertSame(EmailSentReconciliation::STATUS_RECONCILED, EmailLog::query()->sole()->context_json['provider_sent']['status']);
        Queue::assertNotPushed(ProcessInboundRules::class);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('setView', 'all')
            ->call('selectPlacement', $sentPlacement->id)
            ->assertSee('Provider Sent copy')
            ->assertSee('Sent reconciled');
    }

    #[Test]
    public function provider_sent_append_service_keeps_technical_append_without_workspace_dashboard(): void
    {
        Queue::fake();
        Storage::fake('local');

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-sent-append@example.test',
            'from_name' => 'Workspace Sent Append',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);

        EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Sent',
            'name' => 'Sent',
            'role' => EmailFolder::ROLE_SENT,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 880,
        ]);

        $mailer = new class extends SmtpAccountMailer
        {
            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                return '<append-dashboard@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        $fakeClient = new class($account) extends ImapClient
        {
            public array $appended = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function appendSent(string $folderPath, string $message): array
            {
                $this->appended[] = compact('folderPath', 'message');

                return [
                    'ok' => true,
                    'folder_path' => $folderPath,
                    'imap_uid_validity' => 880,
                    'imap_uid' => 881,
                    'response' => ['OK Append completed'],
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $fakeClient);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->set('composerTo', 'customer@example.test')
            ->set('composerSubject', 'Sent copy service append')
            ->set('composerBodyHtml', '<p>Append a provider Sent copy.</p>')
            ->call('sendComposer')
            ->assertDontSee('Provider Sent')
            ->assertDontSee('Append to Sent');

        $reconciliation = EmailSentReconciliation::query()->sole();
        $this->assertSame(EmailSentReconciliation::STATUS_PENDING, $reconciliation->status);
        $this->assertNotEmpty($reconciliation->context_json['sent_raw_path'] ?? null);
        Queue::assertPushed(AppendEmailProviderSentCopy::class, 1);

        app(EmailSentReconciliationService::class)->appendProviderSentCopy($reconciliation);

        $reconciliation->refresh();
        $this->assertSame(EmailSentReconciliation::STATUS_APPENDED, $reconciliation->status);
        $this->assertSame('Sent', $fakeClient->appended[0]['folderPath']);
        $this->assertStringContainsString('Message-ID: <append-dashboard@example.test>', $fakeClient->appended[0]['message']);
    }

    #[Test]
    public function mail_workspace_retries_failed_remote_mailbox_operation_from_dashboard(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-remote-retry@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canOrganize: true);

        $inbox = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 901,
        ]);
        $archive = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 902,
        ]);
        $inboxNamespace = $this->activeUidNamespace($inbox);
        $this->activeUidNamespace($archive);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9011,
            'message_id' => '<remote-retry@example.test>',
            'subject' => 'Retry failed remote move',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Remote retry body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $inbox->id,
            'uid_namespace_id' => $inboxNamespace->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 901,
            'imap_uid' => 9011,
            'provider_seen' => false,
        ]);
        $operation = EmailRemoteOperation::create([
            'account_id' => $account->id,
            'provider_binding_version' => $account->fresh()->provider_binding_version,
            'email_folder_id' => $inbox->id,
            'email_mailbox_placement_id' => $placement->id,
            'requested_by' => $this->tech->id,
            'operation_type' => PerformEmailRemoteOperation::MOVE,
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'idempotency_key' => 'retry-operation-test',
            'source_folder_path' => 'INBOX',
            'target_folder_path' => 'Archive',
            'expected_placement_sync_version' => $placement->sync_version,
            'expected_provider_uid' => $placement->imap_uid,
            'expected_uid_validity' => $placement->imap_uid_validity,
            'attempts' => 1,
            'failed_at' => now(),
            'next_attempt_at' => now(),
            'error_code' => 'REMOTE_OPERATION_FAILED',
            'error_message' => 'Provider was temporarily unavailable.',
        ]);

        $fakeClient = new class($account) extends ImapClient
        {
            public array $moves = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $folderPath === 'INBOX' ? 901 : 902];
            }

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->moves[] = compact('uid', 'sourceFolderPath', 'targetFolderPath');

                return [
                    'ok' => true,
                    'source_folder_path' => $sourceFolderPath,
                    'target_folder_path' => $targetFolderPath,
                    'target_imap_uid' => 9021,
                    'target_uid_validity' => 902,
                    'target_uid_authoritative' => true,
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $fakeClient);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->assertSet('remoteOperationsOpen', false)
            ->assertSeeHtml('x-teleport="#mailbox-operations-rightbar-slot"')
            ->assertSeeHtml('data-mailbox-operations-location="rightbar"')
            ->assertSeeHtml('data-mailbox-operations-state="collapsed"')
            ->assertSeeHtml('aria-expanded="false"')
            ->call('toggleRemoteOperations')
            ->assertSet('remoteOperationsOpen', true)
            ->assertSeeHtml('data-mailbox-operations-state="expanded"')
            ->assertSeeHtml('aria-expanded="true"')
            ->assertSee('Retry failed remote move')
            ->assertSee('Provider was temporarily unavailable.')
            ->call('retryRemoteOperation', $operation->id)
            ->assertSee('Mailbox operation retried successfully.');

        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $operation->fresh()->status);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'account_id' => $account->id,
            'email_folder_id' => $archive->id,
            'imap_uid' => 9021,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
        $this->assertSame('Archive', $fakeClient->moves[0]['targetFolderPath']);
    }

    #[Test]
    public function mail_workspace_links_multiple_email_conversations_to_existing_ticket(): void
    {
        Permission::findOrCreate('ticket.update', 'web');
        Permission::findOrCreate('ticket.view', 'web');
        $this->tech->givePermissionTo(['ticket.update', 'ticket.view']);

        $defaults = app(EnsureTicketDefaults::class)->handle();
        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-880001',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Existing Ticket for mail links',
            'description' => 'Ticket body.',
            'is_unread' => false,
        ]);

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ticket-links@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canOrganize: true);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 920,
        ]);

        $rootMessage = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9201,
            'message_id' => '<ticket-thread-root@example.test>',
            'subject' => 'Ticket thread root',
            'from_email' => 'customer@example.test',
            'received_at' => now()->subMinutes(5),
            'state' => 'untriaged',
            'body_text' => 'Root mail body.',
        ]);
        $replyMessage = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9202,
            'message_id' => '<ticket-thread-reply@example.test>',
            'in_reply_to' => '<ticket-thread-root@example.test>',
            'references' => '<ticket-thread-root@example.test>',
            'subject' => 'Re: Ticket thread root',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Reply mail body.',
        ]);
        $rootPlacement = EmailMailboxPlacement::create([
            'email_message_id' => $rootMessage->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 920,
            'imap_uid' => 9201,
        ]);
        $replyPlacement = EmailMailboxPlacement::create([
            'email_message_id' => $replyMessage->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 920,
            'imap_uid' => 9202,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $rootPlacement->id)
            ->call('toggleTicketLinkPanel')
            ->set('ticketLinkTarget', $ticket->ticket_key)
            ->call('linkSelectedToTicket')
            ->assertSee('Mail conversation was linked to '.$ticket->ticket_key);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $replyPlacement->id)
            ->call('toggleTicketLinkPanel')
            ->set('ticketLinkTarget', $ticket->ticket_key)
            ->call('linkSelectedToTicket')
            ->assertSee('Mail conversation was linked to '.$ticket->ticket_key)
            ->assertSee('2 Ticket conversation links');

        $links = EmailTicketConversationLink::query()
            ->where('ticket_id', $ticket->id)
            ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $links);
        $this->assertSame($links[0]->conversation_key, $links[1]->conversation_key);
        $this->assertNotNull($links[0]->email_conversation_id);
        $this->assertSame($links[0]->email_conversation_id, $links[1]->email_conversation_id);
        $this->assertDatabaseHas('email_conversations', [
            'id' => $links[0]->email_conversation_id,
            'account_id' => $account->id,
            'message_count' => 2,
        ]);
        $this->assertSame($ticket->id, $rootMessage->fresh()->ticket_id);
        $this->assertSame($ticket->id, $replyMessage->fresh()->ticket_id);
        $this->assertSame(2, TicketMessage::where('ticket_id', $ticket->id)->count());
    }

    #[Test]
    public function mail_ai_write_gated_ticket_action_requires_action_enabled_agent_scope(): void
    {
        Permission::findOrCreate('ticket.create', 'web');
        Permission::findOrCreate('ticket.update', 'web');
        $this->tech->givePermissionTo(['ticket.create', 'ticket.update']);

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-ai-write-gate@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canOrganize: true);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 930,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9301,
            'message_id' => '<ai-write-gate@example.test>',
            'subject' => 'Needs a Ticket from AI review',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Please handle this as a support case.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 930,
            'imap_uid' => 9301,
        ]);
        $summary = [
            'summary' => 'Customer is asking for support.',
            'urgency' => 'normal',
            'reply_needed' => true,
            'key_points' => [],
            'questions' => [],
            'action_items' => [],
            'suggested_labels' => [],
        ];

        $agent = $this->readyDefaultMailAgent();

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->set('mailAiSummary', $summary)
            ->assertSee('AI summary')
            ->assertDontSee('Create Ticket');

        $agent->forceFill([
            'can_execute_actions' => true,
            'allowed_api_scopes' => ['tickets.create', 'tickets.update'],
        ])->save();

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->set('mailAiSummary', $summary)
            ->assertSee('Create Ticket')
            ->call('createTicketFromAiReview')
            ->assertSee('Ticket TD-');

        $this->assertNotNull($message->fresh()->ticket_id);
        $this->assertDatabaseHas('email_ticket_conversation_links', [
            'email_message_id' => $message->id,
            'status' => EmailTicketConversationLink::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function mail_workspace_forward_sends_rich_html_without_original_attachments_or_reply_headers(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-forward@example.test',
            'from_name' => 'Workspace Forward',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 654,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6541,
            'message_id' => '<forward-source@example.test>',
            'subject' => 'Forward this update',
            'from_name' => 'Customer Contact',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => 'workspace-forward@example.test']],
            'cc_json' => [['email' => 'ops@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'references' => '<thread-root@example.test>',
            'body_html_sanitized' => '<p>Original <strong>body</strong></p><script>alert(1)</script>',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 654,
            'imap_uid' => 6541,
            'provider_seen' => false,
        ]);
        EmailAttachment::create([
            'message_id' => $message->id,
            'filename' => 'original.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 1234,
            'disk' => 'local',
            'path' => 'email/original.pdf',
        ]);
        $signature = EmailSignature::create([
            'user_id' => $this->tech->id,
            'name' => 'Forward signature',
            'body_html' => '<p>Forward Signature</p>',
            'body_text' => 'Forward Signature',
            'use_on_compose' => false,
            'use_on_reply' => false,
            'use_on_reply_all' => false,
            'use_on_forward' => true,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        $mailer = new class extends SmtpAccountMailer
        {
            public array $calls = [];

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls[] = compact('account', 'toRecipients', 'subject', 'html', 'text', 'attachments', 'ccRecipients', 'options');

                return '<forward-message@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        $component = Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertSee('Forward')
            ->call('startForward')
            ->assertSet('composerMode', SendEmailComposerMessage::MODE_FORWARD)
            ->assertSet('composerTo', '')
            ->assertSet('composerSubject', 'Fwd: Forward this update');

        $forwardBody = (string) $component->get('composerBodyHtml');
        $this->assertStringContainsString('Forwarded message', $forwardBody);
        $this->assertStringContainsString('Original <strong>body</strong>', $forwardBody);
        $this->assertStringNotContainsString('<script', $forwardBody);

        $component
            ->set('composerTo', 'colleague@example.test')
            ->set('composerCc', 'manager@example.test')
            ->set('composerBodyHtml', '<p>FYI, see below.</p>'.$forwardBody)
            ->call('sendComposer')
            ->assertSee('Forward sent from workspace-forward@example.test.');

        $this->assertCount(1, $mailer->calls);
        $call = $mailer->calls[0];

        $this->assertSame([['email' => 'colleague@example.test', 'name' => '']], $call['toRecipients']);
        $this->assertSame([['email' => 'manager@example.test', 'name' => '']], $call['ccRecipients']);
        $this->assertSame('Fwd: Forward this update', $call['subject']);
        $this->assertStringContainsString('FYI, see below.', $call['text']);
        $this->assertStringContainsString('Forwarded message', $call['text']);
        $this->assertStringContainsString('Original body', $call['text']);
        $this->assertStringContainsString('FYI, see below.', $call['html']);
        $this->assertStringContainsString('Forward Signature', $call['html']);
        $this->assertStringContainsString('Original <strong>body</strong>', $call['html']);
        $this->assertLessThan(strpos($call['html'], 'Forwarded message'), strpos($call['html'], 'Forward Signature'));
        $this->assertSame([], $call['attachments']);
        $this->assertNotEmpty($call['options']['message_id'] ?? null);
        $this->assertArrayNotHasKey('in_reply_to', $call['options']);
        $this->assertArrayNotHasKey('references', $call['options']);

        $log = EmailLog::query()->where('code', 'MAIL_FORWARD_SENT')->sole();

        $this->assertSame('outbound', $log->direction);
        $this->assertSame('inbox', $log->scope);
        $this->assertSame($account->id, $log->account_id);
        $this->assertSame($message->id, $log->email_message_id);
        $this->assertSame('<forward-message@example.test>', $log->rfc_message_id);
        $this->assertSame('forward', $log->context_json['mode']);
        $this->assertSame(['colleague@example.test'], $log->context_json['to']);
        $this->assertSame(['manager@example.test'], $log->context_json['cc']);
        $this->assertSame(0, $log->context_json['attachments_count']);
        $this->assertNull($log->context_json['in_reply_to']);
        $this->assertNull($log->context_json['references']);
        $this->assertTrue($log->context_json['signature_applied']);
        $this->assertSame($signature->id, $log->context_json['signature_id']);
        $this->assertSame('stored_signature', $log->context_json['signature_source']);
        $this->assertNotEmpty($log->idempotency_key);
    }

    #[Test]
    public function mail_send_pipeline_respects_signature_mode_toggles(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-forward-signature-disabled@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 658,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6581,
            'message_id' => '<forward-signature-disabled@example.test>',
            'subject' => 'Forward without signature',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Forward this without a signature.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 658,
            'imap_uid' => 6581,
            'provider_seen' => false,
        ]);
        $signature = EmailSignature::create([
            'user_id' => $this->tech->id,
            'name' => 'Forward disabled signature',
            'body_html' => '<p>Must not be sent</p>',
            'body_text' => 'Must not be sent',
            'use_on_compose' => true,
            'use_on_reply' => true,
            'use_on_reply_all' => true,
            'use_on_forward' => false,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        $mailer = new class extends SmtpAccountMailer
        {
            public array $calls = [];

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls[] = compact('account', 'toRecipients', 'subject', 'html', 'text', 'attachments', 'ccRecipients', 'options');

                return '<forward-signature-disabled-sent@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        app(SendEmailComposerMessage::class)->handle($placement, $this->tech, [
            'mode' => SendEmailComposerMessage::MODE_FORWARD,
            'to' => 'colleague@example.test',
            'subject' => 'Fwd: Forward without signature',
            'body_html' => '<p>Forward only.</p>',
            'idempotency_key' => 'forward-without-signature',
        ]);

        $this->assertCount(1, $mailer->calls);
        $this->assertStringContainsString('Forward only.', $mailer->calls[0]['html']);
        $this->assertStringNotContainsString('Must not be sent', $mailer->calls[0]['html']);
        $this->assertStringNotContainsString('nexum-mail-signature:start', $mailer->calls[0]['html']);

        $log = EmailLog::query()->where('code', 'MAIL_FORWARD_SENT')->sole();

        $this->assertFalse($log->context_json['signature_applied']);
        $this->assertSame($signature->id, $log->context_json['signature_id']);
        $this->assertNull($log->context_json['signature_source']);
    }

    #[Test]
    public function mail_workspace_reply_requires_send_grant(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-reply-denied@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: false);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 652,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6521,
            'message_id' => '<source-denied@example.test>',
            'subject' => 'Denied reply',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Can you reply?',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 652,
            'imap_uid' => 6521,
            'provider_seen' => false,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertDontSee('Reply')
            ->assertDontSee('Forward')
            ->call('startReply')
            ->assertSee('You need mailbox Send access before sending from this mailbox.')
            ->call('startForward')
            ->assertSee('You need mailbox Send access before sending from this mailbox.');
    }

    #[Test]
    public function mail_reply_action_is_idempotent_for_successful_submit_key(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-reply-idempotent@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 653,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6531,
            'message_id' => '<source-idempotent@example.test>',
            'subject' => 'Idempotent reply',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Please confirm.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 653,
            'imap_uid' => 6531,
            'provider_seen' => false,
        ]);

        $mailer = new class extends SmtpAccountMailer
        {
            public int $sendCount = 0;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->sendCount++;

                return '<idempotent-reply@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        $payload = [
            'to' => 'customer@example.test',
            'subject' => 'Re: Idempotent reply',
            'body' => 'Confirmed.',
            'idempotency_key' => 'same-submit-key',
        ];

        $first = app(SendEmailReply::class)->handle($placement, $this->tech, $payload);
        $second = app(SendEmailReply::class)->handle($placement, $this->tech, $payload);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $mailer->sendCount);
        $this->assertSame(1, EmailLog::query()->where('code', 'MAIL_REPLY_SENT')->count());
        $this->assertSame('accepted', data_get($first->context_json, 'smtp_delivery.status'));
        $this->assertNotEmpty($first->rfc_message_id);
    }

    #[Test]
    public function mail_forward_action_is_idempotent_for_successful_submit_key(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-forward-idempotent@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canSend: true);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 655,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 6551,
            'message_id' => '<source-forward-idempotent@example.test>',
            'subject' => 'Idempotent forward',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Please forward.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 655,
            'imap_uid' => 6551,
            'provider_seen' => false,
        ]);

        $mailer = new class extends SmtpAccountMailer
        {
            public int $sendCount = 0;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->sendCount++;

                return '<idempotent-forward@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        $payload = [
            'mode' => SendEmailComposerMessage::MODE_FORWARD,
            'to' => 'colleague@example.test',
            'subject' => 'Fwd: Idempotent forward',
            'body_html' => '<p>Forwarded.</p>',
            'idempotency_key' => 'same-forward-submit-key',
        ];

        $first = app(SendEmailComposerMessage::class)->handle($placement, $this->tech, $payload);
        $second = app(SendEmailComposerMessage::class)->handle($placement, $this->tech, $payload);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $mailer->sendCount);
        $this->assertSame(1, EmailLog::query()->where('code', 'MAIL_FORWARD_SENT')->count());
        $this->assertSame('accepted', data_get($first->context_json, 'smtp_delivery.status'));
        $this->assertNotEmpty($first->rfc_message_id);
    }

    #[Test]
    public function api_can_run_authorized_mailbox_provider_operation(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'api-placement-action@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 701,
        ]);
        $namespace = $this->activeUidNamespace($folder);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7011,
            'message_id' => '<api-placement-action@example.test>',
            'subject' => 'API placement action',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'API placement action body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 701,
            'imap_uid' => 7011,
            'provider_seen' => true,
        ]);

        $client = new class($account) extends ImapClient
        {
            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 701];
            }

            public function setSeenByUid(int $uid, bool $seen, string $folderPath = 'INBOX'): bool
            {
                return $uid === 7011 && $seen === false && $folderPath === 'INBOX';
            }

            public function disconnect(): void {}
        };

        $this->app->bind(ImapClient::class, fn () => $client);

        Sanctum::actingAs($this->tech, ['email.update']);

        $this->postJson(route('api.v1.email.mailbox.placements.operations.store', $placement), [
            'operation' => 'mark_unseen',
        ])
            ->assertOk()
            ->assertJsonPath('data.operation.operation_type', 'mark_unseen')
            ->assertJsonPath('data.operation.status', EmailRemoteOperation::STATUS_SUCCEEDED);

        $this->assertFalse($placement->fresh()->provider_seen);
    }

    #[Test]
    public function api_can_move_authorized_mailbox_placement_to_selected_provider_folder(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'api-placement-move@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $inbox = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 703,
        ]);
        $targetFolder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Projects/API',
            'name' => 'API',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 704,
        ]);
        $inboxNamespace = $this->activeUidNamespace($inbox);
        $this->activeUidNamespace($targetFolder);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7031,
            'message_id' => '<api-placement-move@example.test>',
            'subject' => 'API placement move',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'API placement move body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $inbox->id,
            'uid_namespace_id' => $inboxNamespace->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 703,
            'imap_uid' => 7031,
            'provider_seen' => true,
        ]);

        $client = new class($account) extends ImapClient
        {
            public array $moveCalls = [];

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $folderPath === 'INBOX' ? 703 : 704];
            }

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->moveCalls[] = [$uid, $sourceFolderPath, $targetFolderPath];

                return [
                    'ok' => true,
                    'target_folder_path' => $targetFolderPath,
                    'target_imap_uid' => 9704,
                    'target_uid_validity' => 704,
                    'target_uid_authoritative' => true,
                ];
            }

            public function disconnect(): void {}
        };

        $this->app->bind(ImapClient::class, fn () => $client);

        Sanctum::actingAs($this->tech, ['email.update']);

        $this->postJson(route('api.v1.email.mailbox.placements.operations.store', $placement), [
            'operation' => 'move',
            'target_folder_id' => $targetFolder->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.operation.operation_type', 'move')
            ->assertJsonPath('data.operation.status', EmailRemoteOperation::STATUS_SUCCEEDED)
            ->assertJsonPath('data.operation.target_folder_path', 'Projects/API');

        $this->assertSame([[7031, 'INBOX', 'Projects/API']], $client->moveCalls);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'email_message_id' => $message->id,
            'email_folder_id' => $targetFolder->id,
            'folder_path' => 'Projects/API',
            'imap_uid' => 9704,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
    }

    #[Test]
    public function api_view_only_mailbox_grant_cannot_run_provider_operation(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'api-placement-readonly@example.test',
        ]));
        $this->grantMailbox($account, $this->tech, canOrganize: false);

        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 702,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7021,
            'message_id' => '<api-placement-readonly@example.test>',
            'subject' => 'API placement readonly',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'API placement readonly body.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 702,
            'imap_uid' => 7021,
            'provider_seen' => true,
        ]);

        Sanctum::actingAs($this->tech, ['email.update']);

        $this->postJson(route('api.v1.email.mailbox.placements.operations.store', $placement), [
            'operation' => 'mark_unseen',
        ])->assertForbidden();
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
        $this->grantMailbox($account, $this->tech);
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
        $this->activeProviderOccurrence($email);

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
        $this->grantMailbox($account, $this->tech);
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
        $this->activeProviderOccurrence($message);
        $linkedMessage = EmailMessage::create([
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
        $this->activeProviderOccurrence($linkedMessage);

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
        $this->grantMailbox($account, $this->tech, canOrganize: false);
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
        $this->activeProviderOccurrence($message);

        Sanctum::actingAs($this->tech, ['email.read']);

        $this->getJson(route('api.v1.email.inbox.messages.show', $message))
            ->assertOk();

        $this->postJson(route('api.v1.email.inbox.messages.spam', $message))
            ->assertForbidden();

        $this->postJson(route('api.v1.email.inbox.poll'))
            ->assertForbidden();
    }

    #[Test]
    public function inbox_ui_and_api_only_expose_mailboxes_granted_to_the_user(): void
    {
        $visibleAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'visible@example.test',
        ]));
        $privateAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'private@example.test',
            'imap_username' => 'private@example.test',
            'smtp_username' => 'private@example.test',
        ]));
        $this->grantMailbox($visibleAccount, $this->tech);

        $visibleMessage = EmailMessage::create([
            'account_id' => $visibleAccount->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9201,
            'message_id' => '<visible@example.test>',
            'subject' => 'Visible mailbox message',
            'from_email' => 'visible-sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $this->activeProviderOccurrence($visibleMessage);
        $privateMessage = EmailMessage::create([
            'account_id' => $privateAccount->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9202,
            'message_id' => '<private@example.test>',
            'subject' => 'Private mailbox message',
            'from_email' => 'private-sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $this->activeProviderOccurrence($privateMessage);

        $this->actingAs($this->tech)
            ->get(route('tech.inbox.index'))
            ->assertOk()
            ->assertSee('Visible mailbox message')
            ->assertDontSee('Private mailbox message');

        $this->actingAs($this->tech)
            ->get(route('tech.inbox.show', $privateMessage))
            ->assertNotFound();

        Sanctum::actingAs($this->tech, ['email.read']);

        $this->getJson(route('api.v1.email.inbox.messages.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleMessage->id);

        $this->getJson(route('api.v1.email.inbox.messages.show', $privateMessage))
            ->assertNotFound();
    }

    #[Test]
    public function personal_mailbox_owner_can_view_it_and_personal_mail_never_runs_ticket_ingress(): void
    {
        $personal = EmailAccount::create($this->emailAccountPayload([
            'address' => 'owner@example.test',
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $this->tech->id,
            'ticket_ingress_enabled' => true,
            'defaults_for' => ['tickets'],
        ]));
        $message = EmailMessage::create([
            'account_id' => $personal->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9210,
            'message_id' => '<personal@example.test>',
            'subject' => 'Personal mailbox should not ticketize',
            'from_email' => 'unknown-person@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'This is personal mail.',
        ]);
        $this->activeProviderOccurrence($message);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->givePermissionTo('email.inbox_view');

        app()->call([new ProcessInboundRules($message->id), 'handle']);

        $this->assertNull($message->fresh()->ticket_id);
        $this->assertSame('untriaged', $message->fresh()->state);

        $this->actingAs($this->tech)
            ->get(route('tech.inbox.show', $message))
            ->assertOk()
            ->assertSee('Personal mailbox should not ticketize');

        $this->actingAs($other)
            ->get(route('tech.inbox.show', $message))
            ->assertNotFound();
    }

    #[Test]
    public function email_account_connection_can_be_saved_corrected_and_tested_again_in_place(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.accounts.store'), $this->emailAccountFormPayload(null, [
                'address' => 'correctable@example.test',
                'imap_secret' => 'first-imap-password',
                'smtp_secret' => 'first-smtp-password',
            ]))
            ->assertRedirect();

        $account = EmailAccount::query()->where('address', 'correctable@example.test')->firstOrFail();
        $initialImapCiphertext = $account->getAttribute('imap_secret');
        $initialSmtpCiphertext = $account->getAttribute('smtp_secret');

        $this->assertSame('account', $account->provider_credential_source);
        $this->assertNull($account->provider_integration_id);
        $this->assertFalse($account->is_active);
        $this->assertSame('Testing', $account->last_test_result);
        $this->assertSame('first-imap-password', Crypt::decryptString($initialImapCiphertext));
        $this->assertSame('first-smtp-password', Crypt::decryptString($initialSmtpCiphertext));
        Queue::assertPushed(TestEmailAccountConnectionJob::class, fn (TestEmailAccountConnectionJob $job): bool => $job->accountId === $account->id
            && $job->bindingVersion === 1
            && $job->activateWhenVerified
        );

        $this->actingAs($this->admin)
            ->put(route('tech.admin.settings.email.accounts.update', $account), $this->emailAccountFormPayload(null, [
                'address' => $account->address,
                'imap_host' => 'imap-corrected.example.test',
                'imap_secret' => '',
                'smtp_secret' => '',
            ]))
            ->assertRedirect();

        $account->refresh();
        $this->assertSame('imap-corrected.example.test', $account->imap_host);
        $this->assertSame($initialImapCiphertext, $account->getAttribute('imap_secret'));
        $this->assertSame($initialSmtpCiphertext, $account->getAttribute('smtp_secret'));
        $this->assertSame(2, $account->provider_binding_version);

        $this->actingAs($this->admin)
            ->put(route('tech.admin.settings.email.accounts.update', $account), $this->emailAccountFormPayload(null, [
                'address' => $account->address,
                'imap_host' => $account->imap_host,
                'imap_secret' => 'corrected-imap-password',
                'smtp_secret' => 'corrected-smtp-password',
            ]))
            ->assertRedirect();

        $account->refresh();
        $this->assertSame('corrected-imap-password', Crypt::decryptString($account->getAttribute('imap_secret')));
        $this->assertSame('corrected-smtp-password', Crypt::decryptString($account->getAttribute('smtp_secret')));
        $this->assertSame(3, $account->provider_binding_version);
        Queue::assertPushed(TestEmailAccountConnectionJob::class, 3);
    }

    #[Test]
    public function admin_account_form_stores_kind_ticket_ingress_and_user_grants(): void
    {
        Queue::fake();
        $provider = $this->activeEmailProvider('Admin account form provider');

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.accounts.store'), $this->emailAccountFormPayload($provider, [
                'address' => 'shared-admin@example.test',
                'account_kind' => EmailAccount::KIND_SHARED,
                'ticket_ingress_enabled' => '1',
                'grants' => [
                    $this->tech->id => [
                        'user_id' => $this->tech->id,
                        'can_view' => '1',
                        'can_organize' => '1',
                        'can_send' => '0',
                    ],
                ],
            ]))
            ->assertRedirect();

        $account = EmailAccount::query()->where('address', 'shared-admin@example.test')->firstOrFail();

        $this->assertTrue($account->ticket_ingress_enabled);
        $this->assertSame('local_only', $account->delete_policy);
        $this->assertDatabaseHas('email_account_user_grants', [
            'email_account_id' => $account->id,
            'user_id' => $this->tech->id,
            'can_view' => true,
            'can_organize' => true,
            'can_send' => false,
        ]);
        $this->assertDatabaseHas('email_account_user_read_baselines', [
            'email_account_id' => $account->id,
            'user_id' => $this->tech->id,
            'access_epoch' => 1,
            'baseline_message_id' => 0,
            'ordinary_view_entitled' => true,
            'source' => 'direct_grant',
        ]);

        $this->actingAs($this->admin)
            ->put(route('tech.admin.settings.email.accounts.update', $account), $this->emailAccountFormPayload(null, [
                'address' => $account->address,
                'grants' => [],
            ]))
            ->assertRedirect();
        $this->assertFalse(EmailAccountUserReadBaseline::query()
            ->where('email_account_id', $account->id)
            ->where('user_id', $this->tech->id)
            ->sole()
            ->ordinary_view_entitled);

        $this->actingAs($this->admin)
            ->put(route('tech.admin.settings.email.accounts.update', $account), $this->emailAccountFormPayload(null, [
                'address' => $account->address,
                'grants' => [
                    $this->tech->id => [
                        'user_id' => $this->tech->id,
                        'can_view' => '1',
                        'can_organize' => '1',
                        'can_send' => '0',
                    ],
                ],
            ]))
            ->assertRedirect();
        $regrantedBaseline = EmailAccountUserReadBaseline::query()
            ->where('email_account_id', $account->id)
            ->where('user_id', $this->tech->id)
            ->sole();
        $this->assertSame(2, $regrantedBaseline->access_epoch);
        $this->assertTrue($regrantedBaseline->ordinary_view_entitled);

        $account->forceFill(['is_active' => true, 'last_test_result' => 'OK'])->save();

        foreach ([false, true] as $expectedActive) {
            $this->actingAs($this->admin)
                ->post(route('tech.admin.settings.email.accounts.toggle', $account))
                ->assertRedirect();
            $this->assertSame($expectedActive, $account->fresh()->is_active);
            $this->assertSame(2, EmailAccountUserReadBaseline::query()
                ->where('email_account_id', $account->id)
                ->where('user_id', $this->tech->id)
                ->sole()
                ->access_epoch);
        }

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.accounts.store'), $this->emailAccountFormPayload($provider, [
                'address' => 'personal-admin@example.test',
                'account_kind' => EmailAccount::KIND_PERSONAL,
                'owner_id' => $this->tech->id,
                'ticket_ingress_enabled' => '1',
                'is_global_default' => '1',
                'defaults_for' => ['tickets'],
            ]))
            ->assertRedirect();

        $personal = EmailAccount::query()->where('address', 'personal-admin@example.test')->firstOrFail();

        $this->assertSame(EmailAccount::KIND_PERSONAL, $personal->account_kind);
        $this->assertSame($this->tech->id, $personal->owner_id);
        $this->assertFalse($personal->ticket_ingress_enabled);
        $this->assertFalse($personal->is_global_default);
        $this->assertSame([], $personal->defaults_for);
        $this->assertDatabaseHas('email_account_user_read_baselines', [
            'email_account_id' => $personal->id,
            'user_id' => $this->tech->id,
            'access_epoch' => 1,
            'baseline_message_id' => 0,
            'ordinary_view_entitled' => true,
            'source' => 'personal_owner',
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.accounts.store'), $this->emailAccountFormPayload($provider, [
                'address' => 'legacy-cleanup@example.test',
                'delete_policy' => 'legacy_default',
            ]))
            ->assertRedirect();

        $legacyCleanup = EmailAccount::query()->where('address', 'legacy-cleanup@example.test')->firstOrFail();
        $this->assertSame('legacy_default', $legacyCleanup->delete_policy);
    }

    #[Test]
    public function inbound_email_rules_run_only_for_their_selected_mailboxes(): void
    {
        $scopedAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'rules-one@example.test',
        ]));
        $otherAccount = EmailAccount::create($this->emailAccountPayload([
            'address' => 'rules-two@example.test',
            'imap_username' => 'rules-two@example.test',
            'smtp_username' => 'rules-two@example.test',
        ]));
        $rule = EmailRule::create([
            'name' => 'Archive scoped mail',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_NORMAL,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'Scoped'],
            ],
            'actions_json' => [
                ['type' => 'archive', 'value' => ''],
            ],
        ]);
        $rule->accounts()->sync([$scopedAccount->id]);

        $scopedMessage = EmailMessage::create([
            'account_id' => $scopedAccount->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9220,
            'message_id' => '<scoped-one@example.test>',
            'subject' => 'Scoped archive',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $this->activeProviderOccurrence($scopedMessage);
        $otherMessage = EmailMessage::create([
            'account_id' => $otherAccount->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9221,
            'message_id' => '<scoped-two@example.test>',
            'subject' => 'Scoped archive',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'archived',
        ]);
        $this->activeProviderOccurrence($otherMessage);

        app()->call([new ProcessInboundRules($scopedMessage->id), 'handle']);
        app()->call([new ProcessInboundRules($otherMessage->id), 'handle']);

        $this->assertSame('archived', $scopedMessage->fresh()->state);
        $this->assertDatabaseHas('email_rule_logs', [
            'email_rule_id' => $rule->id,
            'email_message_id' => $scopedMessage->id,
        ]);
        $this->assertDatabaseMissing('email_rule_logs', [
            'email_rule_id' => $rule->id,
            'email_message_id' => $otherMessage->id,
        ]);
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
        $this->grantMailbox(EmailAccount::query()->where('address', 'support@example.test')->firstOrFail(), $this->tech);

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
    public function automatic_email_polling_is_registered_in_laravel_scheduler(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'email.poll');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    #[Test]
    public function automatic_email_poll_job_queues_fetch_jobs_for_active_accounts(): void
    {
        Queue::fake();
        Cache::forget('email_last_poll_run');

        EmailAccount::create($this->emailAccountPayload([
            'address' => 'automatic@example.test',
            'is_active' => true,
        ]));
        EmailAccount::create($this->emailAccountPayload([
            'address' => 'disabled-automatic@example.test',
            'is_active' => false,
        ]));

        app()->call([new PollActiveEmailAccounts, 'handle']);

        Queue::assertPushed(FetchImapAccount::class, 1);
        Queue::assertPushed(FetchImapAccount::class, fn (FetchImapAccount $job): bool => $job->batchSize === 20);
        $this->assertNotNull(Cache::get('email_last_poll_run'));
    }

    #[Test]
    public function automatic_email_poll_job_treats_past_heartbeat_as_elapsed_time(): void
    {
        Queue::fake();
        Cache::put('email_last_poll_run', now()->subMinutes(10));

        EmailAccount::create($this->emailAccountPayload([
            'address' => 'automatic-carbon3@example.test',
            'is_active' => true,
        ]));

        app()->call([new PollActiveEmailAccounts, 'handle']);

        Queue::assertPushed(FetchImapAccount::class, 1);
        $this->assertTrue(Cache::get('email_last_poll_run')->greaterThan(now()->subMinute()));
    }

    #[Test]
    public function polling_drains_the_oldest_new_uid_batch_without_backfilling_unread_history(): void
    {
        Queue::fake();
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'poll-fairness@example.test',
            'is_active' => true,
            'imap_uid_validity' => 123,
            'imap_live_start_uid' => 4,
        ]));
        $storedHighWater = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 4,
            'message_id' => '<stored-4@example.test>',
            'subject' => 'Already stored high-water message',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $storedHighWater->delete();

        $payload = fn (int $uid): array => [
            'imap_uid' => $uid,
            'message_id' => '<'.$uid.'@example.test>',
            'subject' => 'Message '.$uid,
            'from_email' => 'sender@example.test',
            'to' => [],
            'cc' => [],
            'headers' => [],
            'received_at' => now()->toDateTimeString(),
            'size_bytes' => 100,
        ];

        $client = new class($account, $payload) extends ImapClient
        {
            public ?int $requestedAfterUid = null;

            public bool $disconnected = false;

            public function __construct(EmailAccount $account, private $payload)
            {
                parent::__construct($account);
            }

            public function connect(): void {}

            public function disconnect(): void
            {
                $this->disconnected = true;
            }

            public function mailboxState(): array
            {
                return [
                    'uid_validity' => 123,
                    'next_uid' => 1448,
                ];
            }

            public function fetchAfterUid(int $uid, int $limit = 20): array
            {
                $this->requestedAfterUid = $uid;

                return array_map($this->payload, [5, 6, 7, 8]);
            }

            public function fetchUnseen(int $limit = 20, int $page = 1): array
            {
                throw new \LogicException('Automatic polling must not scan unread history.');
            }
        };

        $job = new class($account->id, 4, false, $client) extends FetchImapAccount
        {
            public function __construct(int $accountId, int $batchSize, bool $syncStore, private ImapClient $client)
            {
                parent::__construct($accountId, $batchSize, $syncStore);
            }

            protected function makeImapClient(EmailAccount $account): ImapClient
            {
                return $this->client;
            }
        };

        $job->handle();

        $queuedUids = collect();
        Queue::assertPushed(StoreInboundMessage::class, function (StoreInboundMessage $queued) use ($queuedUids): bool {
            $queuedUids->push((int) $queued->payload['imap_uid']);

            return true;
        });

        $this->assertSame([5, 6, 7, 8], $queuedUids->all());
        $this->assertSame(4, $client->requestedAfterUid);
        $this->assertTrue($client->disconnected);
    }

    #[Test]
    public function first_poll_establishes_a_forward_only_uid_baseline_without_importing_mail(): void
    {
        Queue::fake();
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'poll-baseline@example.test',
            'is_active' => true,
        ]));

        $client = new class($account) extends ImapClient
        {
            public bool $disconnected = false;

            public function connect(): void {}

            public function disconnect(): void
            {
                $this->disconnected = true;
            }

            public function mailboxState(): array
            {
                return [
                    'uid_validity' => 456,
                    'next_uid' => 1448,
                ];
            }

            public function fetchAfterUid(int $uid, int $limit = 20): array
            {
                throw new \LogicException('A baseline poll must not fetch historical messages.');
            }
        };

        $job = new class($account->id, 20, false, $client) extends FetchImapAccount
        {
            public function __construct(int $accountId, int $batchSize, bool $syncStore, private ImapClient $client)
            {
                parent::__construct($accountId, $batchSize, $syncStore);
            }

            protected function makeImapClient(EmailAccount $account): ImapClient
            {
                return $this->client;
            }
        };

        $job->handle();

        Queue::assertNotPushed(StoreInboundMessage::class);
        $account->refresh();
        $this->assertSame(456, $account->imap_uid_validity);
        $this->assertSame(1447, $account->imap_live_start_uid);
        $this->assertNotNull($account->imap_live_cursor_initialized_at);
        $this->assertNotNull($account->last_successful_fetch_at);
        $this->assertTrue($client->disconnected);
    }

    #[Test]
    public function changed_uidvalidity_fails_closed_without_fetching_or_queueing_mail(): void
    {
        Queue::fake();
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'poll-uidvalidity@example.test',
            'is_active' => true,
            'imap_uid_validity' => 123,
            'imap_live_start_uid' => 4,
            'last_successful_fetch_at' => now()->subMinute(),
        ]));
        $previousSuccessfulFetch = $account->last_successful_fetch_at;

        $client = new class($account) extends ImapClient
        {
            public bool $disconnected = false;

            public function connect(): void {}

            public function disconnect(): void
            {
                $this->disconnected = true;
            }

            public function mailboxState(): array
            {
                return [
                    'uid_validity' => 999,
                    'next_uid' => 10,
                ];
            }

            public function fetchAfterUid(int $uid, int $limit = 20): array
            {
                throw new \LogicException('Changed UIDVALIDITY must stop before fetching messages.');
            }
        };

        $job = new class($account->id, 20, false, $client) extends FetchImapAccount
        {
            public function __construct(int $accountId, int $batchSize, bool $syncStore, private ImapClient $client)
            {
                parent::__construct($accountId, $batchSize, $syncStore);
            }

            protected function makeImapClient(EmailAccount $account): ImapClient
            {
                return $this->client;
            }
        };

        $job->handle();

        Queue::assertNotPushed(StoreInboundMessage::class);
        $account->refresh();
        $this->assertSame('IMAP_UIDVALIDITY_CHANGED', $account->last_error_code);
        $this->assertSame(123, $account->imap_uid_validity);
        $this->assertSame(4, $account->imap_live_start_uid);
        $this->assertTrue($account->last_successful_fetch_at->equalTo($previousSuccessfulFetch));
        $this->assertTrue($client->disconnected);
    }

    #[Test]
    public function store_inbound_message_creates_non_inbox_placement_without_inbound_automation(): void
    {
        Queue::fake();

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'sent-cache@example.test',
            'ticket_ingress_enabled' => true,
        ]));

        app()->call([new StoreInboundMessage([
            'account_id' => $account->id,
            'mailbox' => 'Sent',
            'imap_uid' => 7001,
            'uid_validity' => 701,
            'message_id' => '<sent-cache@example.test>',
            'subject' => 'Cached sent message',
            'from_email' => 'support@example.test',
            'received_at' => now(),
            'is_oversize' => true,
        ]), 'handle']);

        $message = EmailMessage::query()->where('message_id', '<sent-cache@example.test>')->firstOrFail();
        $folder = EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('path', 'Sent')
            ->firstOrFail();

        $this->assertSame(EmailFolder::ROLE_SENT, $folder->role);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'email_message_id' => $message->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'Sent',
            'imap_uid_validity' => 701,
            'imap_uid' => 7001,
        ]);
        Queue::assertNotPushed(ProcessInboundRules::class);

        app()->call([new ProcessInboundRules($message->id), 'handle']);
        $this->assertNull($message->fresh()->ticket_id);
    }

    #[Test]
    public function store_inbound_message_marks_drafts_folder_placement_as_provider_draft_without_inbound_automation(): void
    {
        Queue::fake();

        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'drafts-cache@example.test',
            'ticket_ingress_enabled' => true,
        ]));

        app()->call([new StoreInboundMessage([
            'account_id' => $account->id,
            'mailbox' => 'Drafts',
            'imap_uid' => 7021,
            'uid_validity' => 702,
            'message_id' => '<drafts-cache@example.test>',
            'subject' => 'Cached provider draft',
            'from_email' => $account->address,
            'to' => [['email' => 'customer@example.test']],
            'received_at' => now(),
            'is_oversize' => true,
            'provider_seen' => true,
        ]), 'handle']);

        $message = EmailMessage::query()->where('message_id', '<drafts-cache@example.test>')->firstOrFail();
        $folder = EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('path', 'Drafts')
            ->firstOrFail();
        $placement = EmailMailboxPlacement::query()
            ->where('email_message_id', $message->id)
            ->where('email_folder_id', $folder->id)
            ->sole();

        $this->assertSame(EmailFolder::ROLE_DRAFTS, $folder->role);
        $this->assertTrue($placement->provider_draft);
        $this->assertSame('Drafts', $placement->folder_path);
        Queue::assertNotPushed(ProcessInboundRules::class);
    }

    #[Test]
    public function mail_workspace_shows_provider_drafts_view_and_hides_reply_actions(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'workspace-provider-drafts@example.test',
            'from_name' => 'Workspace Provider Drafts',
        ]));
        $this->grantMailbox($account, $this->tech, canOrganize: true, canSend: true);

        $drafts = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Drafts',
            'name' => 'Drafts',
            'role' => EmailFolder::ROLE_DRAFTS,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 780,
        ]);
        $inbox = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 781,
        ]);
        $draftMessage = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'Drafts',
            'imap_uid' => 7801,
            'message_id' => '<provider-draft-workspace@example.test>',
            'subject' => 'Provider draft workspace message',
            'from_email' => $account->address,
            'to_json' => [['email' => 'customer@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Draft body from provider.',
        ]);
        $inboxMessage = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7811,
            'message_id' => '<provider-draft-inbox@example.test>',
            'subject' => 'Inbox message outside drafts',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Inbox body.',
        ]);
        $draftPlacement = EmailMailboxPlacement::create([
            'email_message_id' => $draftMessage->id,
            'account_id' => $account->id,
            'email_folder_id' => $drafts->id,
            'folder_path' => 'Drafts',
            'imap_uid_validity' => 780,
            'imap_uid' => 7801,
            'provider_seen' => true,
            'provider_draft' => true,
        ]);
        EmailMailboxPlacement::create([
            'email_message_id' => $inboxMessage->id,
            'account_id' => $account->id,
            'email_folder_id' => $inbox->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 781,
            'imap_uid' => 7811,
            'provider_seen' => false,
        ]);

        Livewire::actingAs($this->tech)
            ->test(MailSidebar::class)
            ->assertSee('Drafts');

        Livewire::actingAs($this->tech)
            ->test(MailWorkspace::class)
            ->call('setView', 'drafts')
            ->assertSee('Provider draft workspace message')
            ->assertSee('Provider draft')
            ->assertDontSee('Inbox message outside drafts')
            ->call('selectPlacement', $draftPlacement->id)
            ->assertSee('Draft body from provider.')
            ->assertSee('Provider draft')
            ->assertDontSee('Reply')
            ->assertDontSee('Reply all')
            ->assertDontSee('Forward')
            ->call('startReply')
            ->assertSet('composerOpen', false)
            ->assertSee('You need mailbox Send access before sending from this mailbox.');
    }

    #[Test]
    public function inbox_ui_and_api_do_not_expose_authorized_non_inbox_messages(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-scope@example.test',
        ]));
        $this->grantMailbox($account, $this->tech);

        $inbox = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7101,
            'message_id' => '<folder-inbox@example.test>',
            'subject' => 'Visible Inbox message',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $this->activeProviderOccurrence($inbox);
        $sent = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'Sent',
            'imap_uid' => 7102,
            'message_id' => '<folder-sent@example.test>',
            'subject' => 'Hidden Sent message',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $this->activeProviderOccurrence($sent, 'Sent', EmailFolder::ROLE_SENT);

        $this->actingAs($this->tech)
            ->get(route('tech.inbox.index'))
            ->assertOk()
            ->assertSee('Visible Inbox message')
            ->assertDontSee('Hidden Sent message');

        $this->actingAs($this->tech)
            ->get(route('tech.inbox.show', $sent))
            ->assertNotFound();

        Sanctum::actingAs($this->tech, ['email.read']);

        $this->getJson(route('api.v1.email.inbox.messages.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inbox->id);

        $this->getJson(route('api.v1.email.inbox.messages.show', $sent))
            ->assertNotFound();
    }

    #[Test]
    public function first_folder_discovery_baselines_each_selectable_folder_without_importing_history(): void
    {
        Queue::fake();
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-baseline@example.test',
            'is_active' => true,
        ]));

        $client = new class($account) extends ImapClient
        {
            public bool $disconnected = false;

            public function connect(): void {}

            public function disconnect(): void
            {
                $this->disconnected = true;
            }

            public function mailboxState(): array
            {
                return ['uid_validity' => 101, 'next_uid' => 10];
            }

            public function folders(): array
            {
                return [
                    ['path' => 'INBOX', 'name' => 'INBOX', 'role' => EmailFolder::ROLE_INBOX, 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 101, 'uid_next' => 10],
                    ['path' => 'Sent', 'name' => 'Sent', 'role' => EmailFolder::ROLE_SENT, 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 202, 'uid_next' => 50],
                ];
            }

            public function fetchAfterUid(int $uid, int $limit = 20): array
            {
                throw new \LogicException('First folder discovery must not import historical INBOX mail.');
            }

            public function fetchAfterUidInFolder(string $folderPath, int $uid, int $limit = 20): array
            {
                throw new \LogicException('First folder discovery must not import historical non-INBOX mail.');
            }
        };

        $job = new class($account->id, 20, false, $client) extends FetchImapAccount
        {
            public function __construct(int $accountId, int $batchSize, bool $syncStore, private ImapClient $client)
            {
                parent::__construct($accountId, $batchSize, $syncStore);
            }

            protected function makeImapClient(EmailAccount $account): ImapClient
            {
                return $this->client;
            }
        };

        $job->handle();

        Queue::assertNotPushed(StoreInboundMessage::class);
        $account->refresh();
        $this->assertSame(101, $account->imap_uid_validity);
        $this->assertSame(9, $account->imap_live_start_uid);
        $this->assertDatabaseHas('email_folders', [
            'account_id' => $account->id,
            'path' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'live_start_uid' => 9,
            'sync_status' => EmailFolder::SYNC_BASELINED,
        ]);
        $this->assertDatabaseHas('email_folders', [
            'account_id' => $account->id,
            'path' => 'Sent',
            'role' => EmailFolder::ROLE_SENT,
            'live_start_uid' => 49,
            'sync_status' => EmailFolder::SYNC_BASELINED,
        ]);
        $this->assertTrue($client->disconnected);
    }

    #[Test]
    public function polling_imports_new_messages_per_folder_and_only_inbox_runs_inbound_automation(): void
    {
        Queue::fake();
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-import@example.test',
            'is_active' => true,
            'imap_uid_validity' => 101,
            'imap_live_start_uid' => 10,
            'ticket_ingress_enabled' => true,
        ]));
        EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'uid_validity' => 101,
            'uid_next' => 11,
            'live_start_uid' => 10,
            'sync_status' => EmailFolder::SYNC_BASELINED,
            'last_synced_at' => now()->subMinute(),
        ]);
        EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Sent',
            'name' => 'Sent',
            'role' => EmailFolder::ROLE_SENT,
            'uid_validity' => 202,
            'uid_next' => 51,
            'live_start_uid' => 50,
            'sync_status' => EmailFolder::SYNC_BASELINED,
            'last_synced_at' => now()->subMinute(),
        ]);

        $client = new class($account) extends ImapClient
        {
            public array $requested = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function mailboxState(): array
            {
                return ['uid_validity' => 101, 'next_uid' => 13];
            }

            public function folders(): array
            {
                return [
                    ['path' => 'INBOX', 'name' => 'INBOX', 'role' => EmailFolder::ROLE_INBOX, 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 101, 'uid_next' => 13],
                    ['path' => 'Sent', 'name' => 'Sent', 'role' => EmailFolder::ROLE_SENT, 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 202, 'uid_next' => 53],
                ];
            }

            public function fetchAfterUid(int $uid, int $limit = 20): array
            {
                $this->requested['INBOX'] = $uid;

                return [$this->payload('INBOX', 11)];
            }

            public function fetchAfterUidInFolder(string $folderPath, int $uid, int $limit = 20): array
            {
                $this->requested[$folderPath] = $uid;

                return [$this->payload($folderPath, 51)];
            }

            private function payload(string $mailbox, int $uid): array
            {
                return [
                    'mailbox' => $mailbox,
                    'imap_uid' => $uid,
                    'message_id' => '<'.$mailbox.'-'.$uid.'@example.test>',
                    'subject' => $mailbox.' '.$uid,
                    'from_email' => 'sender@example.test',
                    'to' => [],
                    'cc' => [],
                    'headers' => [],
                    'received_at' => now()->toDateTimeString(),
                    'size_bytes' => 100,
                    'provider_seen' => $mailbox !== 'INBOX',
                ];
            }
        };

        $job = new class($account->id, 20, false, $client) extends FetchImapAccount
        {
            public function __construct(int $accountId, int $batchSize, bool $syncStore, private ImapClient $client)
            {
                parent::__construct($accountId, $batchSize, $syncStore);
            }

            protected function makeImapClient(EmailAccount $account): ImapClient
            {
                return $this->client;
            }
        };

        $job->handle();

        $payloads = collect();
        Queue::assertPushed(StoreInboundMessage::class, function (StoreInboundMessage $job) use ($payloads): bool {
            $payloads->push($job->payload);

            return true;
        });

        $this->assertSame(10, $client->requested['INBOX']);
        $this->assertSame(50, $client->requested['Sent']);
        $this->assertSame(['INBOX', 'Sent'], $payloads->pluck('mailbox')->sort()->values()->all());
        $this->assertTrue((bool) $payloads->firstWhere('mailbox', 'INBOX')['run_inbound_rules']);
        $this->assertFalse((bool) $payloads->firstWhere('mailbox', 'Sent')['run_inbound_rules']);
        $this->assertSame(101, (int) $payloads->firstWhere('mailbox', 'INBOX')['uid_validity']);
        $this->assertSame(202, (int) $payloads->firstWhere('mailbox', 'Sent')['uid_validity']);
    }

    #[Test]
    public function changed_non_inbox_folder_uidvalidity_fails_closed_without_fetching_that_folder(): void
    {
        Queue::fake();
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'folder-uidvalidity@example.test',
            'is_active' => true,
            'imap_uid_validity' => 101,
            'imap_live_start_uid' => 10,
        ]));
        EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'uid_validity' => 101,
            'uid_next' => 11,
            'live_start_uid' => 10,
            'sync_status' => EmailFolder::SYNC_BASELINED,
            'last_synced_at' => now()->subMinute(),
        ]);
        EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Sent',
            'name' => 'Sent',
            'role' => EmailFolder::ROLE_SENT,
            'uid_validity' => 202,
            'uid_next' => 51,
            'live_start_uid' => 50,
            'sync_status' => EmailFolder::SYNC_BASELINED,
            'last_synced_at' => now()->subMinute(),
        ]);

        $client = new class($account) extends ImapClient
        {
            public function connect(): void {}

            public function disconnect(): void {}

            public function mailboxState(): array
            {
                return ['uid_validity' => 101, 'next_uid' => 12];
            }

            public function folders(): array
            {
                return [
                    ['path' => 'INBOX', 'name' => 'INBOX', 'role' => EmailFolder::ROLE_INBOX, 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 101, 'uid_next' => 12],
                    ['path' => 'Sent', 'name' => 'Sent', 'role' => EmailFolder::ROLE_SENT, 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 999, 'uid_next' => 55],
                ];
            }

            public function fetchAfterUid(int $uid, int $limit = 20): array
            {
                return [];
            }

            public function fetchAfterUidInFolder(string $folderPath, int $uid, int $limit = 20): array
            {
                throw new \LogicException('Changed folder UIDVALIDITY must stop before fetching messages.');
            }
        };

        $job = new class($account->id, 20, false, $client) extends FetchImapAccount
        {
            public function __construct(int $accountId, int $batchSize, bool $syncStore, private ImapClient $client)
            {
                parent::__construct($accountId, $batchSize, $syncStore);
            }

            protected function makeImapClient(EmailAccount $account): ImapClient
            {
                return $this->client;
            }
        };

        $job->handle();

        $sent = EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('path', 'Sent')
            ->firstOrFail();

        $this->assertSame(202, $sent->uid_validity);
        $this->assertSame(EmailFolder::SYNC_ERROR, $sent->sync_status);
        $this->assertSame('IMAP_UIDVALIDITY_CHANGED', $sent->sync_error_code);
        Queue::assertNotPushed(StoreInboundMessage::class);
    }

    #[Test]
    public function remote_operation_ledger_keeps_idempotent_pending_operations_unique(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'remote-operation@example.test',
        ]));
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'uid_validity' => 321,
            'live_start_uid' => 10,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 11,
            'message_id' => '<remote-operation@example.test>',
            'subject' => 'Remote operation',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 321,
            'imap_uid' => 11,
        ]);

        $first = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            'mark_seen',
            'mail-op:test-idempotency',
            $this->tech,
            $folder,
            $placement,
            ['source_folder_path' => 'INBOX'],
        );
        $second = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            'mark_seen',
            'mail-op:test-idempotency',
            $this->tech,
            $folder,
            $placement,
            ['source_folder_path' => 'INBOX', 'target_folder_path' => 'Archive'],
        );

        $this->assertTrue($first->is($second));
        $this->assertSame(1, EmailRemoteOperation::query()->where('idempotency_key', 'mail-op:test-idempotency')->count());
        $this->assertSame(EmailRemoteOperation::STATUS_PENDING, $first->status);
        $this->assertNull($first->target_folder_path);
    }

    #[Test]
    public function email_config_health_card_reports_no_active_accounts(): void
    {
        Cache::forget('email_last_poll_run');

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.config'))
            ->assertOk()
            ->assertSee('System Health')
            ->assertSee('0 active')
            ->assertSee('No active email accounts are enabled for automatic polling.')
            ->assertSee('No email poll heartbeat has been recorded.');
    }

    #[Test]
    public function email_config_health_card_reports_fresh_fetch_activity(): void
    {
        config(['queue.default' => 'database']);
        Cache::put('email_last_poll_run', now()->subMinute());
        EmailAccount::create($this->emailAccountPayload([
            'address' => 'fresh@example.test',
            'is_active' => true,
            'last_successful_fetch_at' => now()->subMinute(),
        ]));

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.config'))
            ->assertOk()
            ->assertSee('1 active')
            ->assertSee('Latest successful fetch was')
            ->assertSee('Ready: none. Reserved: none. Failed on default/email: 0. Failed on other queues: 0.');
    }

    #[Test]
    public function email_config_health_card_reports_missing_scheduler_heartbeat(): void
    {
        Cache::forget('email_last_poll_run');
        EmailAccount::create($this->emailAccountPayload([
            'address' => 'stale-scheduler@example.test',
            'is_active' => true,
            'last_successful_fetch_at' => now(),
        ]));

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.config'))
            ->assertOk()
            ->assertSee('No heartbeat')
            ->assertSee('Confirm cron runs schedule:run');
    }

    #[Test]
    public function email_config_health_card_reports_pending_and_failed_queue_jobs(): void
    {
        config(['queue.default' => 'database']);
        Cache::put('email_last_poll_run', now());
        EmailAccount::create($this->emailAccountPayload([
            'address' => 'queue@example.test',
            'is_active' => true,
            'last_successful_fetch_at' => now(),
        ]));

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(10)->timestamp,
            'created_at' => now()->subMinutes(10)->timestamp,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'email',
            'payload' => '{}',
            'exception' => 'Test failure',
            'failed_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.config'))
            ->assertOk()
            ->assertSee('Queue worker')
            ->assertSee('Error')
            ->assertSee('Failed on default/email: 1')
            ->assertSee('Stale ready jobs on default/email: default=1');
    }

    #[Test]
    public function email_config_health_does_not_report_other_queue_failures_as_mail_errors(): void
    {
        config(['queue.default' => 'database']);
        Cache::put('email_last_poll_run', now());
        EmailAccount::create($this->emailAccountPayload([
            'address' => 'healthy-mail-queue@example.test',
            'is_active' => true,
            'last_successful_fetch_at' => now(),
        ]));

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'notifications',
            'payload' => '{}',
            'exception' => 'Unrelated notification failure',
            'failed_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.config'))
            ->assertOk()
            ->assertSee('Other queue failures')
            ->assertSee('Failed on default/email: 0')
            ->assertSee('Failed on other queues: 1');
    }

    #[Test]
    public function email_configuration_uses_agents_and_clears_legacy_structured_workload_setting(): void
    {
        $legacyWorkload = $this->readyMailAiWorkload();
        $this->readyDefaultMailAgent(['name' => 'Datanora']);
        CommonSetting::query()->updateOrCreate(
            ['type' => 'emailhub', 'name' => self::LEGACY_MAIL_AI_WORKLOAD_SETTING],
            ['value' => (string) $legacyWorkload->id],
        );

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.config'))
            ->assertOk()
            ->assertSee('Mail AI')
            ->assertSee('Default Email agent')
            ->assertSee('Use global fallback agent')
            ->assertSee('Global fallback agent')
            ->assertSee('Datanora')
            ->assertDontSee('Structured workload override')
            ->assertDontSee($legacyWorkload->name)
            ->assertDontSee('mail_ai_workload_owned_by_other_domain');

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.config.update'), [
                'poll_interval' => 1,
                'concurrency' => 2,
                'batch_size' => 20,
                'size_limit_mb' => 25,
                'retention_months' => 24,
                'max_failures' => 3,
                'attachment_max_count' => InboundAttachmentPolicy::DEFAULT_MAX_COUNT,
                'attachment_max_size_mb' => InboundAttachmentPolicy::DEFAULT_MAX_SIZE_MB,
                'attachment_allowed_mime_types' => implode("\n", InboundAttachmentPolicy::DEFAULT_ALLOWED_MIME_TYPES),
                'mail_ai_default_agent_id' => '',
            ])
            ->assertRedirect(route('tech.admin.settings.email.config'));

        $this->assertDatabaseHas('common_settings', [
            'type' => 'emailhub',
            'name' => self::LEGACY_MAIL_AI_WORKLOAD_SETTING,
            'value' => '',
        ]);
        $this->assertDatabaseHas('common_settings', [
            'type' => 'emailhub',
            'name' => 'attachment_allowed_mime_types',
            'value' => implode("\n", InboundAttachmentPolicy::DEFAULT_ALLOWED_MIME_TYPES),
        ]);
    }

    #[Test]
    public function admin_can_select_default_email_agent_from_email_configuration(): void
    {
        $global = $this->readyDefaultMailAgent([
            'name' => 'Datanora',
            'slug' => 'datanora-default-'.Str::lower(Str::random(8)),
            'default_domains' => [],
            'can_execute_actions' => true,
        ]);
        $mailAgent = AiAgent::create([
            'ai_provider_id' => $global->ai_provider_id,
            'name' => 'Mail Agent',
            'slug' => 'mail-agent-select-'.Str::lower(Str::random(8)),
            'model' => 'mail-fallback-test',
            'instructions' => 'Mail-specific agent.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => [],
            'can_execute_actions' => false,
            'is_default' => false,
            'default_domains' => [],
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.config'))
            ->assertOk()
            ->assertSee('Default Email agent')
            ->assertSee('Use global fallback agent')
            ->assertSee('Global fallback agent')
            ->assertSee('Datanora')
            ->assertSee('Mail Agent')
            ->assertDontSee('Email agent in use')
            ->assertDontSee('Structured workload override')
            ->assertSee('Datanora');

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.config.update'), [
                'poll_interval' => 1,
                'concurrency' => 2,
                'batch_size' => 20,
                'size_limit_mb' => 25,
                'retention_months' => 24,
                'max_failures' => 3,
                'attachment_max_count' => InboundAttachmentPolicy::DEFAULT_MAX_COUNT,
                'attachment_max_size_mb' => InboundAttachmentPolicy::DEFAULT_MAX_SIZE_MB,
                'attachment_allowed_mime_types' => implode("\n", InboundAttachmentPolicy::DEFAULT_ALLOWED_MIME_TYPES),
                'mail_ai_default_agent_id' => $mailAgent->id,
            ])
            ->assertRedirect(route('tech.admin.settings.email.config'));

        $this->assertSame([], $global->fresh()->default_domains ?? []);
        $this->assertSame(['email'], $mailAgent->fresh()->default_domains ?? []);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.config.update'), [
                'poll_interval' => 1,
                'concurrency' => 2,
                'batch_size' => 20,
                'size_limit_mb' => 25,
                'retention_months' => 24,
                'max_failures' => 3,
                'attachment_max_count' => InboundAttachmentPolicy::DEFAULT_MAX_COUNT,
                'attachment_max_size_mb' => InboundAttachmentPolicy::DEFAULT_MAX_SIZE_MB,
                'attachment_allowed_mime_types' => implode("\n", InboundAttachmentPolicy::DEFAULT_ALLOWED_MIME_TYPES),
                'mail_ai_default_agent_id' => '',
            ])
            ->assertRedirect(route('tech.admin.settings.email.config'));

        $this->assertSame([], $mailAgent->fresh()->default_domains ?? []);
        $this->assertTrue($global->fresh()->is_default);
    }

    #[Test]
    public function admin_can_open_email_accounts_from_email_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.settings.email.accounts');

        $this->assertSame(AccountsController::class.'@index', $route->getActionName());

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
            $legacyClass = 'App\\Domain\\Email\\Jobs\\'.$job;
            $moduleClass = 'App\\Modules\\Email\\Jobs\\'.$job;

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
        $this->grantMailbox($account, $this->tech);
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
        $this->activeProviderOccurrence($message);

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
        $result = new EmailTestResult;
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
    public function connection_test_job_activates_only_the_exact_configuration_after_both_logins_pass(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'connection-job@example.test',
            'is_active' => false,
            'provider_credential_source' => 'account',
            'provider_binding_version' => 4,
            'last_test_result' => 'Testing',
        ]));
        $result = new EmailTestResult;
        $result->imap_ok = true;
        $result->smtp_ok = true;

        $this->mock(EmailTestService::class)
            ->shouldReceive('runConfiguration')
            ->once()
            ->withArgs(fn (EmailAccount $testedAccount, int $bindingVersion): bool => $testedAccount->is($account) && $bindingVersion === 4
            )
            ->andReturn($result);

        app()->call([new TestEmailAccountConnectionJob($account->id, 4, true), 'handle']);

        $this->assertTrue($account->fresh()->is_active);

        app()->call([new TestEmailAccountConnectionJob($account->id, 3, true), 'handle']);

        $this->assertTrue($account->fresh()->is_active);
    }

    #[Test]
    public function admin_can_open_email_templates_from_template_hub(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.system.templatesManagement.email.index');

        $this->assertSame(EmailTemplateController::class.'@index', $route->getActionName());

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
        Queue::fake();
        $provider = $this->activeEmailProvider('Marketing account provider');

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.accounts.store'), $this->emailAccountFormPayload($provider, [
                'address' => 'marketing@example.test',
                'defaults_for' => ['marketing'],
            ]))
            ->assertRedirect();

        $account = EmailAccount::query()->where('address', 'marketing@example.test')->firstOrFail();
        $account->forceFill(['is_active' => true, 'last_test_result' => 'OK'])->save();

        $this->assertSame(['marketing'], $account->defaults_for);
        $this->assertTrue(app(DefaultEmailAccountResolver::class)->forScope('marketing')->is($account));

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.accounts'))
            ->assertOk()
            ->assertSee('Marketing');
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
            'scope' => 'tickets',
            'key' => 'ticket_status_update',
            'is_default' => true,
            'is_active' => true,
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
                'light_header_background' => '#003366',
                'light_header_color' => '#FFFFFF',
                'light_footer_background' => '#003366',
                'light_footer_color' => '#FFFFFF',
                'light_main_background' => '#EEF4F8',
                'light_content_background' => '#FFFFFF',
                'light_left_sidebar_color' => '#172B3A',
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
            ->assertSee('Rendered preview')
            ->assertSee('marketing', false)
            ->assertSee('unsubscribe_url');
    }

    #[Test]
    public function branding_layout_tracks_company_colors_until_it_is_explicitly_customized(): void
    {
        $setting = CommonSetting::query()->create([
            'type' => 'company_profile',
            'name' => 'branding',
            'description' => 'Template layout branding test',
            'value' => 'Layout Company',
            'json' => json_encode([
                'company_name' => 'Layout Company',
                'light_header_background' => '#123456',
                'light_header_color' => '#FFFFFF',
                'light_footer_background' => '#234567',
                'light_footer_color' => '#FFFFFF',
                'light_main_background' => '#EAF0F4',
                'light_content_background' => '#FFFFFF',
                'light_left_sidebar_color' => '#102030',
                'light_primary_button_background' => '#345678',
                'accent_color' => '#ABCDEF',
            ]),
        ]);
        $template = EmailTemplate::query()->create([
            'scope' => 'marketing',
            'key' => 'managed_branding_test',
            'name' => 'Managed branding test',
            'subject' => 'Hello {{ contact_name }}',
            'body_html' => '<p>First body</p>',
            'body_text' => 'First body',
            'layout_mode' => EmailTemplate::LAYOUT_BRANDING,
            'variables' => ['contact_name'],
            'is_active' => true,
        ]);
        $renderer = app(EmailTemplateRenderer::class);

        $this->assertStringContainsString('#123456', $renderer->render($template, [])['html']);

        $frozenLayout = $renderer->materializeLayout($template);
        $template->forceFill([
            'layout_mode' => EmailTemplate::LAYOUT_CUSTOM,
            'layout_html' => $frozenLayout,
            'body_html' => '<p>Updated body</p>',
        ])->save();
        $setting->update([
            'json' => json_encode([
                'company_name' => 'Layout Company',
                'light_header_background' => '#654321',
                'light_header_color' => '#FFFFFF',
                'light_footer_background' => '#765432',
                'light_footer_color' => '#FFFFFF',
                'light_main_background' => '#F4F0EA',
                'light_content_background' => '#FFFFFF',
                'light_left_sidebar_color' => '#302010',
                'light_primary_button_background' => '#876543',
                'accent_color' => '#FEDCBA',
            ]),
        ]);

        $custom = $renderer->render($template->fresh(), []);
        $this->assertStringContainsString('#123456', $custom['html']);
        $this->assertStringNotContainsString('#654321', $custom['html']);
        $this->assertStringContainsString('Updated body', $custom['html']);

        $template->forceFill([
            'layout_mode' => EmailTemplate::LAYOUT_BRANDING,
            'layout_html' => null,
        ])->save();

        $this->assertStringContainsString('#654321', $renderer->render($template->fresh(), [])['html']);
    }

    #[Test]
    public function template_editor_uses_a_sandboxed_preview_and_validates_body_and_layout_html(): void
    {
        $template = EmailTemplate::query()->create([
            'scope' => 'marketing',
            'key' => 'editor_policy_test',
            'name' => 'Editor policy test',
            'subject' => 'Preview {{ contact_name }}',
            'body_html' => '<p>Safe body</p>',
            'body_text' => 'Safe body',
            'layout_mode' => EmailTemplate::LAYOUT_BRANDING,
            'variables' => ['contact_name'],
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.templatesManagement.email.edit', $template))
            ->assertOk()
            ->assertSee('data-html-editor', false)
            ->assertSee('data-template-preview', false)
            ->assertSee('sandbox=""', false)
            ->assertSee('Customize layout')
            ->assertDontSee('&amp;lt;!doctype html', false);

        $this->actingAs($this->admin)
            ->postJson(route('tech.admin.system.templatesManagement.email.preview'), [
                'subject' => 'Preview {{ contact_name }}',
                'body_html' => '<p>Unsaved safe body</p>',
                'body_text' => 'Unsaved safe body',
                'layout_mode' => EmailTemplate::LAYOUT_CUSTOM,
                'layout_html' => '<!doctype html><html><body><main>{{ email_body }}</main></body></html>',
                'variables' => 'contact_name',
            ])
            ->assertOk()
            ->assertJsonPath('subject', 'Preview Ola Nordmann')
            ->assertJsonPath('text', 'Unsaved safe body')
            ->assertJsonPath('html', '<!doctype html><html><body><main><p>Unsaved safe body</p></main></body></html>');

        $this->actingAs($this->admin)
            ->postJson(route('tech.admin.system.templatesManagement.email.preview'), [
                'subject' => 'Unsafe layout',
                'body_html' => '<p>Safe body</p>',
                'layout_mode' => EmailTemplate::LAYOUT_CUSTOM,
                'layout_html' => '<html><body>{{ email_body }}<script>alert(1)</script></body></html>',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('layout_html');

        $this->actingAs($this->admin)
            ->postJson(route('tech.admin.system.templatesManagement.email.preview'), [
                'subject' => 'Wrong field',
                'body_html' => '<html><body>Document in body</body></html>',
                'layout_mode' => EmailTemplate::LAYOUT_BRANDING,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body_html');
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
        $this->activeProviderOccurrence($email);

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
        Notification::fake();
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
        $this->activeProviderOccurrence($email);

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
        $this->activeProviderOccurrence($email);

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
        $this->activeProviderOccurrence($email);

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
        $this->activeProviderOccurrence($email);

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
        $this->activeProviderOccurrence($email);

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
    public function conflicting_header_and_subject_ticket_evidence_requires_audited_resolution(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $headerTicket = Ticket::create([
            'ticket_key' => 'TD-2026-700001',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Header candidate',
            'is_unread' => false,
        ]);
        $subjectTicket = Ticket::create([
            'ticket_key' => 'TD-2026-700002',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Subject candidate',
            'is_unread' => false,
        ]);
        $outboundMessage = TicketMessage::create([
            'ticket_id' => $headerTicket->id,
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
            'message' => 'SMTP accepted.',
            'context_json' => ['ticket_message_id' => $outboundMessage->id],
            'rfc_message_id' => '<header-ticket@example.test>',
        ]);
        $account = EmailAccount::create([
            'address' => 'support@example.test',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@example.test',
            'imap_secret' => 'encrypted',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'support@example.test',
            'smtp_secret' => 'encrypted',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 701,
            'message_id' => '<conflicting-correlation@example.test>',
            'in_reply_to' => '<header-ticket@example.test>',
            'subject' => 'Re: [TD-2026-700002] Conflicting evidence',
            'from_email' => 'private-customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'This reply must wait for a decision.',
        ]);
        $this->activeProviderOccurrence($email);

        app()->call([new ProcessInboundRules($email->id), 'handle']);
        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $conflict = EmailTicketCorrelationConflict::query()->sole();

        $this->assertNull($email->fresh()->ticket_id);
        $this->assertSame('untriaged', $email->fresh()->state);
        $this->assertSame(EmailTicketCorrelationConflict::STATUS_PENDING, $conflict->status);
        $this->assertEqualsCanonicalizing(
            [$headerTicket->id, $subjectTicket->id],
            $conflict->candidate_ticket_ids,
        );
        $this->assertSame([$headerTicket->id], $conflict->evidence['rfc_headers']);
        $this->assertSame([$subjectTicket->id], $conflict->evidence['subject_key']);
        $this->assertStringNotContainsString('private-customer@example.test', (string) $conflict->getRawOriginal('evidence'));
        $this->assertDatabaseCount('ticket_messages', 1);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.ticket-correlation-conflicts.index'))
            ->assertOk()
            ->assertSee('TD-2026-700001')
            ->assertSee('TD-2026-700002')
            ->assertSee('Needs decision');

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.ticket-correlation-conflicts.resolve', $conflict), [
                'ticket_id' => $subjectTicket->id,
                'reason' => 'The customer intentionally retained the current Ticket key.',
            ])
            ->assertRedirect(route('tech.admin.settings.email.ticket-correlation-conflicts.index'));

        $this->assertSame($subjectTicket->id, $email->fresh()->ticket_id);
        $this->assertSame('linked', $email->fresh()->state);
        $this->assertSame(EmailTicketCorrelationConflict::STATUS_RESOLVED, $conflict->fresh()->status);
        $this->assertSame($this->admin->id, $conflict->fresh()->resolved_by);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $subjectTicket->id,
            'actor_id' => $this->admin->id,
            'type' => 'email_correlation_conflict_resolved',
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $this->assertSame(1, TicketMessage::where('ticket_id', $subjectTicket->id)->count());
        $this->assertDatabaseCount('email_ticket_correlation_conflicts', 1);
    }

    #[Test]
    public function references_to_two_tickets_are_not_silently_routed_to_the_latest_log(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $tickets = collect([1, 2])->map(function (int $index) use ($defaults): Ticket {
            return Ticket::create([
                'ticket_key' => 'TD-2026-71000'.$index,
                'queue_id' => $defaults['queue']->id,
                'status_id' => $defaults['status']->id,
                'priority_id' => $defaults['priority']->id,
                'owner_id' => $this->tech->id,
                'created_by' => $this->tech->id,
                'updated_by' => $this->tech->id,
                'channel' => 'manual',
                'subject' => 'Header candidate '.$index,
                'is_unread' => false,
            ]);
        });

        foreach ($tickets as $index => $ticket) {
            $outbound = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'author_id' => $this->tech->id,
                'author_type' => 'user',
                'type' => 'customer_reply',
                'visibility' => 'public',
                'body' => 'Previous reply '.$index,
            ]);
            EmailLog::create([
                'direction' => 'outbound',
                'scope' => 'tickets',
                'level' => 'info',
                'code' => 'TICKET_EMAIL_SENT',
                'message' => 'SMTP accepted.',
                'context_json' => ['ticket_message_id' => $outbound->id],
                'rfc_message_id' => '<multi-header-'.$index.'@example.test>',
            ]);
        }

        $account = EmailAccount::create([
            'address' => 'support-headers@example.test',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support-headers@example.test',
            'imap_secret' => 'encrypted',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'support-headers@example.test',
            'smtp_secret' => 'encrypted',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 702,
            'message_id' => '<multi-header-reply@example.test>',
            'references' => '<multi-header-0@example.test> <multi-header-1@example.test>',
            'subject' => 'Re: no Ticket key',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Ambiguous header reply.',
        ]);
        $this->activeProviderOccurrence($email);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $this->assertNull($email->fresh()->ticket_id);
        $this->assertEqualsCanonicalizing(
            $tickets->pluck('id')->all(),
            EmailTicketCorrelationConflict::query()->sole()->candidate_ticket_ids,
        );
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
                ."tor. 14. mai 2026 kl. 13:51 skrev Svein Tore <post@example.com>:\n\n"
            ."> Hello Svein Tore,\n>\n> Hei hvordan går det med deg?",
        ]);
        $this->activeProviderOccurrence($email);

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
                ."--- Please reply above this line ---\n\n"
            ."Hello Customer,\n\nOld technician message.",
        ]);
        $this->activeProviderOccurrence($email);

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
        $this->activeProviderOccurrence($email);
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
        $this->activeProviderOccurrence($email);
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
        $this->activeProviderOccurrence($email);

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
            'work_context_id' => app(ResolveWorkContext::class)->client($client)->id,
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
        $this->activeProviderOccurrence($email);

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
        $this->activeProviderOccurrence($email);

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
        $this->activeProviderOccurrence($email);
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
        $this->activeProviderOccurrence($email);
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
        $this->activeProviderOccurrence($email);

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
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'rules-support@example.test',
        ]));

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.rules'))
            ->assertOk()
            ->assertSee('System rules')
            ->assertSee('Link inbound reply to ticket by subject token')
            ->assertSee('Create ticket from routed inbound email');

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.rules.store'), [
                'intent' => 'publish',
                'name' => 'Archive obvious spam',
                'description' => 'Hide sender domain before ticket routing.',
                'weight' => 1,
                'account_ids' => [$account->id],
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
        $spamRule = EmailRule::query()->where('name', 'Archive obvious spam')->firstOrFail();
        $spamVersion = EmailRuleVersion::query()->where('email_rule_id', $spamRule->id)->firstOrFail();
        $this->assertSame(1, $spamVersion->version_number);
        $this->assertSame(EmailRuleVersion::STATUS_PUBLISHED, $spamVersion->status);
        $this->assertSame($spamVersion->id, $spamRule->fresh()->published_version_id);
        $this->assertSame([$account->id], $spamVersion->account_ids_json);

        $queue = TicketQueue::create([
            'name' => 'Inbound Sales',
            'slug' => 'inbound-sales',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.rules.store'), [
                'intent' => 'publish',
                'name' => 'Create inbound sales ticket',
                'description' => 'Route known sales mailbox into Ticket.',
                'weight' => 20,
                'account_ids' => [$account->id],
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
    public function published_email_rule_execution_is_versioned_and_idempotent(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'rules-versioned@example.test',
        ]));

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.rules.store'), [
                'intent' => 'publish',
                'name' => 'Versioned spam archive',
                'description' => 'Hide repeated spam without replaying side effects.',
                'weight' => 1,
                'account_ids' => [$account->id],
                'is_active' => '1',
                'stop_processing' => '1',
                'conditions' => [
                    ['field' => 'from_domain', 'operator' => 'equals', 'value' => 'spam.test'],
                ],
                'actions' => [
                    ['type' => 'archive', 'value' => ''],
                    ['type' => 'tag', 'value' => 'spam'],
                ],
            ])
            ->assertRedirect(route('tech.admin.settings.email.rules'));

        $rule = EmailRule::query()->where('name', 'Versioned spam archive')->firstOrFail();
        $version = $rule->publishedVersion()->firstOrFail();
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9911,
            'message_id' => '<versioned-rule@example.test>',
            'subject' => 'Versioned rule candidate',
            'from_email' => 'sender@spam.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Rule body.',
        ]);
        $this->activeProviderOccurrence($message);

        app()->call([new ProcessInboundRules($message->id), 'handle']);
        app()->call([new ProcessInboundRules($message->id), 'handle']);

        $message->refresh();
        $this->assertSame('archived', $message->state);
        $this->assertTrue($message->tags()->where('tags.name', 'spam')->exists());
        $this->assertSame(1, $rule->fresh()->hit_count);

        $this->assertSame(1, EmailRuleExecutionAttempt::query()->where('email_rule_id', $rule->id)->count());
        $attempt = EmailRuleExecutionAttempt::query()->where('email_rule_id', $rule->id)->firstOrFail();
        $this->assertSame($version->id, $attempt->email_rule_version_id);
        $this->assertSame($message->id, $attempt->email_message_id);
        $this->assertSame(EmailRuleExecutionAttempt::STATUS_SUCCEEDED, $attempt->status);
        $this->assertTrue($attempt->matched);
        $this->assertTrue($attempt->stop_processing);
        $this->assertCount(2, $attempt->action_results_json);

        $this->assertSame(1, EmailRuleLog::query()->where('email_rule_id', $rule->id)->count());
    }

    #[Test]
    public function email_rules_api_lists_published_versions_and_previews_without_side_effects(): void
    {
        $this->admin->givePermissionTo('email.inbox_view');
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'rules-api@example.test',
        ]));
        $this->grantMailbox($account, $this->admin);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.rules.store'), [
                'intent' => 'publish',
                'name' => 'API preview rule',
                'description' => 'Preview before execution.',
                'weight' => 7,
                'account_ids' => [$account->id],
                'is_active' => '1',
                'stop_processing' => '0',
                'conditions' => [
                    ['field' => 'subject', 'operator' => 'contains', 'value' => 'preview me'],
                ],
                'actions' => [
                    ['type' => 'tag', 'value' => 'previewed'],
                ],
            ])
            ->assertRedirect(route('tech.admin.settings.email.rules'));

        $rule = EmailRule::query()->where('name', 'API preview rule')->firstOrFail();
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9912,
            'message_id' => '<rules-api@example.test>',
            'subject' => 'Please preview me',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'No side effects.',
        ]);
        $this->activeProviderOccurrence($message);

        Sanctum::actingAs($this->admin, ['email.rules.read']);

        $this->getJson(route('api.v1.email.rules.index'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'API preview rule')
            ->assertJsonPath('data.0.published_version.version_number', 1);

        $this->postJson(route('api.v1.email.rules.preview', $rule), [
            'email_message_id' => $message->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.rule_id', $rule->id)
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.account_scope_matched', true)
            ->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.actions.0.status', 'would_run');

        $this->assertSame('untriaged', $message->fresh()->state);
        $this->assertSame(0, EmailRuleExecutionAttempt::query()->count());
        $this->assertFalse($message->tags()->where('tags.name', 'previewed')->exists());
    }

    #[Test]
    public function grouped_email_rules_preview_and_reprocess_use_group_match_semantics(): void
    {
        $this->admin->givePermissionTo('email.inbox_view');
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'rules-grouped@example.test',
        ]));
        $this->grantMailbox($account, $this->admin);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.rules.store'), [
                'intent' => 'publish',
                'name' => 'Grouped inbound rule',
                'description' => 'Any condition group can match.',
                'weight' => 9,
                'account_ids' => [$account->id],
                'is_active' => '1',
                'stop_processing' => '0',
                'condition_match' => 'any',
                'conditions' => [
                    [
                        'group' => 'Sender',
                        'group_match' => 'all',
                        'field' => 'from_domain',
                        'operator' => 'equals',
                        'value' => 'trusted.example',
                    ],
                    [
                        'group' => 'Subject',
                        'group_match' => 'all',
                        'field' => 'subject',
                        'operator' => 'contains',
                        'value' => 'grouped match',
                    ],
                ],
                'actions' => [
                    ['type' => 'tag', 'value' => 'grouped-rule-hit'],
                ],
            ])
            ->assertRedirect(route('tech.admin.settings.email.rules'));

        $rule = EmailRule::query()->where('name', 'Grouped inbound rule')->firstOrFail();
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 99031,
            'message_id' => '<rules-grouped@example.test>',
            'subject' => 'This should grouped match by subject',
            'from_email' => 'sender@other.example',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Grouped body.',
        ]);
        $this->activeProviderOccurrence($message);

        Sanctum::actingAs($this->admin, ['email.rules.read']);

        $this->postJson(route('api.v1.email.rules.preview', $rule), [
            'email_message_id' => $message->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.actions.0.status', 'would_run');

        $this->actingAs($this->admin)
            ->from(route('tech.admin.settings.email.rules'))
            ->post(route('tech.admin.settings.email.rules.reprocess-preview', $rule), [
                'email_message_id' => $message->id,
            ])
            ->assertRedirect(route('tech.admin.settings.email.rules'));
        $run = \App\Modules\Email\Models\EmailRuleReprocessRun::query()->firstOrFail();
        app(\App\Modules\Email\Services\EmailRuleReprocessService::class)->apply($run, $this->admin);
        (new \App\Modules\Email\Jobs\ProcessEmailRuleReprocessRun($run->id))->handle(
            app(\App\Modules\Email\Services\InboundEmailRuleEngine::class),
            app(\App\Modules\Email\Services\EmailRuleReprocessService::class),
        );

        $this->assertTrue($message->fresh()->tags()->where('tags.name', 'grouped-rule-hit')->exists());
        $this->assertDatabaseHas('email_rule_execution_attempts', [
            'email_rule_id' => $rule->id,
            'email_message_id' => $message->id,
            'matched' => true,
            'status' => EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
        ]);
    }

    #[Test]
    public function admin_can_create_inbound_email_rule_that_emits_signal(): void
    {
        $account = EmailAccount::create($this->emailAccountPayload([
            'address' => 'rules-signal@example.test',
        ]));

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.rules.store'), [
                'intent' => 'publish',
                'name' => 'Emit vendor signal',
                'description' => 'Hand selected inbound mail to Signal.',
                'weight' => 5,
                'account_ids' => [$account->id],
                'is_active' => '1',
                'stop_processing' => '1',
                'conditions' => [
                    ['field' => 'from_domain', 'operator' => 'equals', 'value' => 'vendor.example'],
                ],
                'actions' => [
                    ['type' => 'emit_signal', 'value' => 'Vendor Notice'],
                ],
            ])
            ->assertRedirect(route('tech.admin.settings.email.rules'));

        $rule = EmailRule::query()->where('name', 'Emit vendor signal')->firstOrFail();

        $this->assertSame('emit_signal', $rule->actions_json[0]['type']);
        $this->assertSame('vendor_notice', $rule->actions_json[0]['signal_type']);
        $this->assertSame('vendor_notice', $rule->actions_json[0]['value']);
    }

    #[Test]
    public function inbound_email_rule_emit_signal_records_one_signal_and_runs_signal_rules(): void
    {
        $client = Client::factory()->create(['name' => 'Signal Client']);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Signal Sender',
        ]);
        ContactEmail::query()->create([
            'contact_id' => $contact->id,
            'label' => 'work',
            'email' => 'sender@vendor.example',
            'is_primary' => true,
        ]);
        ContactRelation::query()->create([
            'contact_id' => $contact->id,
            'related_type' => Client::class,
            'related_id' => $client->id,
            'relation_type' => 'customer',
            'is_primary' => true,
        ]);
        SignalRule::query()->create([
            'name' => 'Tag vendor notices',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [
                'source_domain' => ['email'],
                'signal_type' => ['vendor_notice'],
            ],
            'actions' => [
                ['type' => 'tag_contact', 'tag' => 'Vendor notice'],
            ],
        ]);
        $account = EmailAccount::create([
            'address' => 'post@example.test',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'post@example.test',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'post@example.test',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 505,
            'message_id' => '<vendor-notice@example.test>',
            'subject' => 'Vendor maintenance notice',
            'from_email' => 'sender@vendor.example',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Planned maintenance notice.',
        ]);
        $this->activeProviderOccurrence($email);
        EmailRule::create([
            'name' => 'Emit vendor notice',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => [
                ['field' => 'from_domain', 'operator' => 'equals', 'value' => 'vendor.example'],
            ],
            'actions_json' => [
                ['type' => 'emit_signal', 'value' => 'vendor_notice'],
                ['type' => 'archive', 'value' => ''],
            ],
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);
        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $signal = Signal::query()
            ->where('source_domain', 'email')
            ->where('source_type', EmailMessage::class)
            ->where('source_id', $email->id)
            ->firstOrFail();

        $this->assertSame('vendor_notice', $signal->signal_type);
        $this->assertSame($contact->id, $signal->contact_id);
        $this->assertSame($client->id, $signal->client_id);
        $this->assertSame('Emit vendor notice', $signal->payload['email_rule_name']);
        $this->assertSame('archived', $email->fresh()->state);
        $this->assertSame(1, $signal->executions()->count());
        $this->assertSame(1, Signal::query()->where('source_domain', 'email')->where('source_id', $email->id)->count());
        $this->assertSame(1, $contact->fresh()->tags()->where('slug', 'vendor-notice')->count());
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
        $this->activeProviderOccurrence($email);
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

    private function readyMailAiWorkload(): AiWorkloadProfile
    {
        $policy = AiDataEgressPolicy::installation();
        $policy->update([
            'ai_enabled' => true,
            'allowed_processing_modes' => ['local_only'],
            'maximum_data_profile' => 'full_context',
            'expires_at' => now()->addMonth(),
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'updated_by' => $this->admin->id,
        ]);

        $provider = AiProvider::create([
            'name' => 'Local Mail AI',
            'provider_key' => 'ollama',
            'base_url' => 'http://ollama.example.test',
            'default_model' => 'mail-ai-test',
            'status' => 'active',
            'config' => [],
            'secrets' => [],
            'is_healthy' => true,
        ]);
        $agent = AiAgent::create([
            'ai_provider_id' => $provider->id,
            'name' => 'Mail Summary Agent',
            'slug' => 'mail-summary-agent-'.Str::lower(Str::random(8)),
            'model' => 'mail-ai-test',
            'instructions' => 'Return only the approved Mail summary schema.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => [],
            'can_execute_actions' => false,
            'default_domains' => ['email'],
            'is_active' => true,
        ]);
        $workload = AiWorkloadProfile::create([
            'name' => 'Mail AI Summary',
            'slug' => 'mail-ai-summary-'.Str::lower(Str::random(8)),
            'workload_type' => AiWorkloadProfile::TYPE_INTERNAL_MODEL,
            'purpose' => 'Read-only Mail summary and action extraction for authorized mailbox views.',
            'ai_provider_id' => $provider->id,
            'ai_agent_id' => $agent->id,
            'model' => 'mail-ai-test',
            'processing_mode' => 'local_only',
            'maximum_data_profile' => 'full_context',
            'abilities' => [],
            'allowed_client_ids' => [],
            'allowed_work_context_ids' => [],
            'employee_identification_requested' => false,
            'is_approved' => true,
            'is_active' => true,
            'expires_at' => now()->addMonth(),
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        CommonSetting::query()->updateOrCreate(
            ['type' => 'emailhub', 'name' => self::LEGACY_MAIL_AI_WORKLOAD_SETTING],
            ['value' => (string) $workload->id],
        );

        return $workload;
    }

    private function readyDefaultMailAgent(array $overrides = []): AiAgent
    {
        $policy = AiDataEgressPolicy::installation();
        $policy->update([
            'ai_enabled' => true,
            'allowed_processing_modes' => ['local_only'],
            'maximum_data_profile' => 'full_context',
            'expires_at' => now()->addMonth(),
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'updated_by' => $this->admin->id,
        ]);

        $provider = AiProvider::create([
            'name' => 'Fallback Mail AI',
            'provider_key' => 'ollama',
            'base_url' => 'http://ollama-mail-ai.test',
            'default_model' => 'mail-fallback-test',
            'status' => 'active',
            'config' => [],
            'secrets' => [],
            'is_healthy' => true,
        ]);

        return AiAgent::create(array_merge([
            'ai_provider_id' => $provider->id,
            'name' => 'Datanora Default Mail Agent',
            'slug' => 'datanora-default-mail-agent-'.Str::lower(Str::random(8)),
            'model' => 'mail-fallback-test',
            'instructions' => 'Assist with Mail without executing writes unless a separate workflow explicitly calls one.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => [],
            'can_execute_actions' => false,
            'is_default' => true,
            'default_domains' => ['email'],
            'is_active' => true,
        ], $overrides));
    }

    private function grantMailbox(
        EmailAccount $account,
        User $user,
        bool $canView = true,
        bool $canOrganize = true,
        bool $canSend = false,
    ): void {
        $epochs = app(EmailUnreadAccessEpochService::class);
        $wasEntitled = $epochs->captureEntitlement($account, $user);
        $grant = EmailAccountUserGrant::query()->updateOrCreate(
            [
                'email_account_id' => $account->id,
                'user_id' => $user->id,
            ],
            [
                'can_view' => $canView,
                'can_organize' => $canOrganize,
                'can_send' => $canSend,
                'granted_at' => now(),
            ],
        );
        $isPersonalOwner = $account->isPersonal()
            && (int) $account->owner_id === (int) $user->id;
        $epochs->reconcileAfterMutation(
            $account,
            $user,
            $wasEntitled,
            $isPersonalOwner
                ? EmailUnreadAccessEpochService::SOURCE_PERSONAL_OWNER
                : EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            $isPersonalOwner ? 'owner:'.$user->id : 'grant:'.$grant->id,
            $this->admin,
        );
    }

    private function activeProviderOccurrence(
        EmailMessage $message,
        string $folderPath = 'INBOX',
        string $folderRole = EmailFolder::ROLE_INBOX,
    ): EmailMailboxPlacement {
        $folder = EmailFolder::query()->firstOrCreate(
            [
                'account_id' => $message->account_id,
                'path' => $folderPath,
            ],
            [
                'name' => $folderPath,
                'role' => $folderRole,
                'is_selectable' => true,
                'sync_enabled' => true,
                'uid_validity' => 1,
            ],
        );

        return EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $message->account_id,
            'email_folder_id' => $folder->id,
            'provider' => 'imap',
            'folder_path' => $folderPath,
            'imap_uid_validity' => (int) ($folder->uid_validity ?: 1),
            'imap_uid' => $message->imap_uid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
            'provider_missing_at' => null,
        ]);
    }

    private function activeUidNamespace(EmailFolder $folder): EmailFolderUidNamespace
    {
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $folder->account_id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => $folder->uid_validity,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test_provider_uid_namespace',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();

        return $namespace;
    }

    private function activeEmailProvider(string $name): EmailProviderConnection
    {
        $id = (string) Str::uuid();
        Integration::query()->create([
            'id' => $id,
            'name' => $name,
            'type' => 'email_provider',
            'status' => 'active',
            'config' => ['provider_status' => 'active'],
            'secrets' => null,
            'is_healthy' => true,
        ]);
        $connection = EmailProviderConnection::query()->create([
            'integration_id' => $id,
            'status' => 'active',
            'configuration_version' => 1,
            'verified_configuration_version' => 1,
            'verified_credential_version' => 1,
            'imap_host' => 'imap.provider-fixture.example',
            'imap_port' => 993,
            'imap_transport' => 'implicit_tls',
            'imap_endpoint_policy_id' => 'standard.imap.993.implicit_tls',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.provider-fixture.example',
            'smtp_port' => 465,
            'smtp_transport' => 'implicit_tls',
            'smtp_endpoint_policy_id' => 'standard.smtp.465.implicit_tls',
            'smtp_auth_type' => 'password',
            'trust_mode' => 'public',
            'capabilities' => ['imap' => true, 'smtp' => true],
            'last_verification_code' => 'verified',
            'last_verified_at' => now(),
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $ciphertext = app(EmailProviderCredentialCipher::class)->encrypt([
            'imap_username' => 'provider-fixture@example.test',
            'imap_secret' => 'provider-fixture-imap-secret',
            'smtp_username' => 'provider-fixture@example.test',
            'smtp_secret' => 'provider-fixture-smtp-secret',
        ]);
        $version = EmailProviderCredentialVersion::query()->create([
            'provider_integration_id' => $id,
            'version' => 1,
            'state' => EmailProviderCredentialVersion::STATE_ACTIVE,
            ...$ciphertext,
            'credential_fingerprint' => hash('sha256', $id),
            'verified_configuration_version' => 1,
            'verification_code' => 'verified',
            'staged_by' => $this->admin->id,
            'verified_by' => $this->admin->id,
            'activated_by' => $this->admin->id,
            'staged_at' => now(),
            'verified_at' => now(),
            'activated_at' => now(),
        ]);
        $connection->forceFill(['active_credential_version_id' => $version->id])->save();

        return $connection->refresh();
    }

    /** @return array<string, mixed> */
    private function emailAccountFormPayload(
        ?EmailProviderConnection $provider,
        array $overrides = [],
    ): array {
        $payload = array_merge([
            'address' => 'account@example.test',
            'description' => 'Test account',
            'from_name' => 'Test Account',
            'account_kind' => EmailAccount::KIND_SHARED,
            'owner_id' => null,
            'is_active' => '1',
            'is_global_default' => '0',
            'defaults_for' => [],
            'ticket_ingress_enabled' => '1',
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'implicit_tls',
            'imap_username' => 'account@example.test',
            'imap_secret' => 'secret',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'starttls',
            'smtp_username' => 'account@example.test',
            'smtp_secret' => 'secret',
        ], $overrides);

        if ($provider !== null) {
            $payload['provider_integration_id'] = $provider->getKey();
        }

        return $payload;
    }

    private function emailAccountPayload(array $overrides = []): array
    {
        return array_merge([
            'address' => 'account@example.test',
            'description' => 'Test account',
            'from_name' => 'Test Account',
            'account_kind' => EmailAccount::KIND_SHARED,
            'owner_id' => null,
            'is_active' => '1',
            'is_global_default' => '0',
            'defaults_for' => [],
            'ticket_ingress_enabled' => '1',
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
