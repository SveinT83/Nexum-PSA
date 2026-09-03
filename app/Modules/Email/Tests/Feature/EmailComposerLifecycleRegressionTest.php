<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Livewire\Tech\MailWorkspace;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailComposerDraftAttachment;
use App\Modules\Email\Services\EmailComposerDraftService;
use App\Modules\Email\Services\SmtpAccountMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailComposerLifecycleRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $view = Permission::findOrCreate('email.inbox_view', 'web');
        $manage = Permission::findOrCreate('email.inbox_manage', 'web');
        Role::create(['name' => 'Mail composer lifecycle tech'])->givePermissionTo([$view, $manage]);

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Mail composer lifecycle tech');
    }

    #[Test]
    public function composer_uses_supported_livewire_three_entanglement_and_syncs_before_actions(): void
    {
        $source = file_get_contents(base_path(
            'app/Modules/Email/Views/Livewire/Tech/partials/mail-composer-form.blade.php',
        ));

        $this->assertIsString($source);
        $this->assertStringContainsString("value: @entangle('composerBodyHtml'),", $source);
        $this->assertStringNotContainsString("@entangle('composerBodyHtml').defer", $source);
        $this->assertStringContainsString(
            "\$wire.\$set('composerBodyHtml', this.value || '', false);",
            $source,
        );
        $this->assertStringContainsString(
            "\$wire.\$set('composerTo', this.\$refs.to.value || '', false);",
            $source,
        );
        $this->assertStringContainsString(
            "\$wire.\$set('composerCc', this.\$refs.cc.value || '', false);",
            $source,
        );
        $this->assertStringContainsString(
            "\$wire.\$set('composerSubject', this.\$refs.subject.value || '', false);",
            $source,
        );
        $this->assertStringContainsString('x-on:submit.capture="sync()"', $source);
        $this->assertStringContainsString('x-ref="to"', $source);
        $this->assertStringContainsString('x-ref="cc"', $source);
        $this->assertStringContainsString('x-ref="subject"', $source);
        $this->assertStringContainsString('x-ref="editor"', $source);
        $this->assertStringContainsString('wire:ignore', $source);
        $this->assertStringContainsString('contenteditable="{{ $composerShared', $source);
        $this->assertStringContainsString("composerSharedEditable() ? 'false' : 'true' }}\"", $source);
    }

    #[Test]
    public function html_body_survives_autosave_and_a_failed_send_request(): void
    {
        $account = $this->sendableAccount('composer-lifecycle@example.test');
        $bodyHtml = '<p>Keep this <strong>HTML source</strong> after every Livewire request.</p>';

        Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->set('composerTo', 'not-an-email-address')
            ->set('composerSubject', 'Composer lifecycle regression')
            ->set('composerBodyHtml', $bodyHtml)
            ->call('saveComposerDraft', false)
            ->assertSet('composerOpen', true)
            ->assertSet('composerBodyHtml', $bodyHtml)
            ->call('sendComposer')
            ->assertHasErrors(['to'])
            ->assertSet('composerOpen', true)
            ->assertSet('composerBodyHtml', $bodyHtml);

        $draft = EmailComposerDraft::query()
            ->where('user_id', $this->actor->id)
            ->where('email_account_id', $account->id)
            ->sole();

        $this->assertSame($bodyHtml, $draft->body_html);
        $this->assertSame('Keep this HTML source after every Livewire request.', $draft->body_text);
    }

    #[Test]
    public function markup_without_message_text_is_rejected_without_closing_the_composer(): void
    {
        $this->sendableAccount('composer-empty@example.test');
        $emptyHtml = '<p><br></p>';

        Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->set('composerTo', 'customer@example.test')
            ->set('composerSubject', 'Empty HTML regression')
            ->set('composerBodyHtml', $emptyHtml)
            ->call('sendComposer')
            ->assertHasErrors(['body_html'])
            ->assertSet('composerOpen', true)
            ->assertSet('composerBodyHtml', $emptyHtml);
    }

    #[Test]
    public function client_supplied_attachment_ids_cannot_cross_the_active_draft_or_mailbox_context(): void
    {
        Storage::fake('local');

        $activeAccount = $this->sendableAccount('composer-active@example.test');
        $otherAccount = $this->sendableAccount('composer-other@example.test');
        $drafts = app(EmailComposerDraftService::class);
        $activeDraft = $drafts->save($this->actor, 'compose', $activeAccount, null, [
            'to' => 'customer@example.test',
            'subject' => 'Active draft',
            'body_html' => '<p>Send only the active draft.</p>',
            'idempotency_key' => 'active-draft-key',
        ]);
        $otherDraft = $drafts->save($this->actor, 'compose', $otherAccount, null, [
            'to' => 'other@example.test',
            'subject' => 'Other mailbox draft',
            'body_html' => '<p>Private attachment owner.</p>',
            'idempotency_key' => 'other-draft-key',
        ]);
        $otherPath = 'email/drafts/'.$this->actor->id.'/'.$otherDraft->id.'/private.txt';
        Storage::disk('local')->put($otherPath, 'must not be attached');
        $otherAttachment = EmailComposerDraftAttachment::query()->create([
            'email_composer_draft_id' => $otherDraft->id,
            'user_id' => $this->actor->id,
            'position' => 1,
            'filename' => 'private.txt',
            'content_type' => 'text/plain',
            'size_bytes' => strlen('must not be attached'),
            'disk' => 'local',
            'path' => $otherPath,
            'checksum_sha1' => sha1('must not be attached'),
        ]);

        $mailer = new class extends SmtpAccountMailer
        {
            public array $attachments = [];

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->attachments = $attachments;

                return '<active-draft-only@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->assertSet('composerAccountId', $activeAccount->id)
            ->assertSet('composerDraftId', $activeDraft->id)
            ->set('composerDraftId', $otherDraft->id)
            ->set('composerDraftAttachments', [[
                'id' => $otherAttachment->id,
                'filename' => $otherAttachment->filename,
                'size_bytes' => $otherAttachment->size_bytes,
                'content_type' => $otherAttachment->content_type,
            ]])
            ->call('sendComposer')
            ->assertSet('composerOpen', false);

        $this->assertSame([], $mailer->attachments);
        $this->assertSame(EmailComposerDraft::STATUS_ACTIVE, $otherDraft->fresh()->status);
        $this->assertDatabaseHas('email_composer_draft_attachments', [
            'id' => $otherAttachment->id,
            'email_composer_draft_id' => $otherDraft->id,
        ]);
    }

    #[Test]
    public function autosave_preserves_an_unresolved_provider_append_guard(): void
    {
        $account = $this->sendableAccount('composer-unresolved-draft@example.test');
        $drafts = app(EmailComposerDraftService::class);
        $draft = $drafts->save($this->actor, 'compose', $account, null, [
            'to' => 'customer@example.test',
            'subject' => 'Unresolved provider append',
            'body_html' => '<p>Original body.</p>',
            'idempotency_key' => 'unresolved-provider-append',
        ]);
        $draft->forceFill([
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_ERROR,
            'provider_draft_folder_path' => 'INBOX.Drafts',
            'provider_draft_uid_validity' => null,
            'provider_draft_uid' => null,
            'provider_draft_message_id' => '<unresolved-provider-append@example.test>',
            'provider_draft_normalized_message_id' => 'unresolved-provider-append@example.test',
            'provider_draft_error_code' => EmailComposerDraft::PROVIDER_DRAFT_APPEND_OUTCOME_UNRESOLVED,
            'provider_draft_error_message' => 'The provider append outcome is unresolved.',
        ])->save();

        $saved = $drafts->save($this->actor, 'compose', $account, null, [
            'to' => 'customer@example.test',
            'subject' => 'Unresolved provider append',
            'body_html' => '<p>Locally edited while reconciliation is pending.</p>',
            'idempotency_key' => 'unresolved-provider-append',
        ], false, (int) $draft->version);

        $this->assertSame(
            EmailComposerDraft::PROVIDER_DRAFT_APPEND_OUTCOME_UNRESOLVED,
            $saved->provider_draft_error_code,
        );
        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_ERROR, $saved->provider_draft_status);
        $this->assertSame(
            '<unresolved-provider-append@example.test>',
            $saved->provider_draft_message_id,
        );
        $this->assertSame(
            '<p>Locally edited while reconciliation is pending.</p>',
            $saved->body_html,
        );
    }

    private function sendableAccount(string $address): EmailAccount
    {
        $account = EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Mail composer lifecycle regression account',
            'from_name' => 'Composer Lifecycle',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'composer-lifecycle-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'composer-lifecycle-secret',
            'smtp_auth_type' => 'password',
        ]);

        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $this->actor->id,
            'can_view' => false,
            'can_organize' => false,
            'can_send' => true,
            'granted_at' => now(),
        ]);

        return $account;
    }
}
