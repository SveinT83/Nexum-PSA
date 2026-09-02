<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Jobs\AppendEmailProviderSentCopy;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailComposerDraftAttachment;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailOutboundSubmission;
use App\Modules\Email\Models\EmailSentReconciliation;
use App\Modules\Email\Services\EmailPrivateStorage;
use App\Modules\Email\Services\EmailSentReconciliationService;
use App\Modules\Email\Services\SmtpAccountMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailComposeDraftSendApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $peer;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('email.inbox_view', 'web');
        Permission::findOrCreate('email.inbox_manage', 'web');

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->peer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo(['email.inbox_view', 'email.inbox_manage']);
        $this->peer->givePermissionTo(['email.inbox_view', 'email.inbox_manage']);
        Storage::fake(EmailPrivateStorage::DISK);
    }

    #[Test]
    public function private_draft_scope_and_opaque_versions_isolate_users_and_reject_stale_writes(): void
    {
        $account = $this->sendableAccount([$this->actor, $this->peer]);
        Sanctum::actingAs($this->actor, ['email.drafts.read', 'email.drafts.write']);

        $created = $this->postJson(route('api.v1.email.mailbox.drafts.store'), [
            'scope' => EmailComposerDraft::SCOPE_PRIVATE,
            'account_id' => $account->id,
            'mode' => 'compose',
            'to' => 'customer@example.test',
            'subject' => 'Actor draft',
            'body_html' => '<p>Private actor body.</p>',
        ])->assertCreated()
            ->assertJsonPath('data.scope', EmailComposerDraft::SCOPE_PRIVATE)
            ->assertJsonPath('data.status', EmailComposerDraft::STATUS_ACTIVE);
        $actorDraftId = (string) $created->json('data.id');
        $firstVersion = (string) $created->json('data.version');
        $actorDraft = EmailComposerDraft::query()->where('public_id', $actorDraftId)->sole();

        $this->assertNotSame('', $actorDraftId);
        $this->assertNotSame('', $firstVersion);
        $this->assertStringStartsWith('edf1_', $firstVersion);
        $this->assertStringNotContainsString($actorDraftId, $firstVersion);
        $this->assertNotEmpty($actorDraft->generation_id);
        $this->assertSame(EmailComposerDraft::SCOPE_PRIVATE, $actorDraft->scope);
        $this->assertGreaterThanOrEqual(1, $actorDraft->version);
        $this->assertStringNotContainsString('compose:account:', $created->getContent());
        $this->assertStringNotContainsString('generation_id', $created->getContent());
        $this->assertStringNotContainsString('idempotency_key', $created->getContent());

        Sanctum::actingAs($this->peer, ['email.drafts.read', 'email.drafts.write']);
        $peer = $this->postJson(route('api.v1.email.mailbox.drafts.store'), [
            'account_id' => $account->id,
            'mode' => 'compose',
            'to' => 'peer@example.test',
            'subject' => 'Peer draft',
            'body_html' => '<p>Private peer body.</p>',
        ])->assertCreated();
        $this->assertNotSame($actorDraftId, $peer->json('data.id'));
        $this->getJson(route('api.v1.email.mailbox.drafts.show', $actorDraftId))->assertNotFound();

        Sanctum::actingAs($this->actor, ['email.drafts.read', 'email.drafts.write']);
        $updated = $this->patchJson(route('api.v1.email.mailbox.drafts.update', $actorDraftId), [
            'version' => $firstVersion,
            'subject' => 'Current actor draft',
        ])->assertOk()
            ->assertJsonPath('data.subject', 'Current actor draft');
        $currentVersion = (string) $updated->json('data.version');

        $this->patchJson(route('api.v1.email.mailbox.drafts.update', $actorDraftId), [
            'version' => $firstVersion,
            'subject' => 'Stale overwrite must fail',
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'email_draft_version_conflict')
            ->assertJsonPath('data.subject', 'Current actor draft')
            ->assertJsonPath('data.version', $currentVersion);

        $this->assertDatabaseMissing('email_composer_drafts', [
            'public_id' => $actorDraftId,
            'subject' => 'Stale overwrite must fail',
        ]);
        $this->postJson(route('api.v1.email.mailbox.drafts.store'), [
            'scope' => 'shared',
            'account_id' => $account->id,
            'mode' => 'compose',
        ])->assertUnprocessable();

        Sanctum::actingAs($this->actor, ['email.drafts.read']);
        $this->patchJson(route('api.v1.email.mailbox.drafts.update', $actorDraftId), [
            'version' => $currentVersion,
        ])->assertForbidden();
    }

    #[Test]
    public function attachment_api_keeps_storage_evidence_private_and_fences_each_mutation(): void
    {
        $account = $this->sendableAccount([$this->actor, $this->peer]);
        Sanctum::actingAs($this->actor, ['email.drafts.read', 'email.drafts.write']);
        $created = $this->createDraft($account, 'Attachment draft');
        $draftId = (string) $created->json('data.id');
        $initialVersion = (string) $created->json('data.version');

        $uploaded = $this->post(route('api.v1.email.mailbox.drafts.attachments.store', $draftId), [
            'version' => $initialVersion,
            'attachments' => [UploadedFile::fake()->createWithContent('evidence.txt', 'private evidence')],
        ])->assertOk()
            ->assertJsonPath('data.attachments.0.filename', 'evidence.txt')
            ->assertJsonPath('data.attachments.0.size_bytes', strlen('private evidence'));
        $attachmentId = (string) $uploaded->json('data.attachments.0.id');
        $currentVersion = (string) $uploaded->json('data.version');
        $json = $uploaded->getContent();
        $attachment = EmailComposerDraftAttachment::query()
            ->where('public_id', $attachmentId)
            ->sole();

        $this->assertNotEmpty($attachment->draft_generation_id);

        foreach (['"disk"', '"path"', 'checksum_sha1', 'draft_generation_id', 'user_id'] as $secretField) {
            $this->assertStringNotContainsString($secretField, $json);
        }

        $this->post(route('api.v1.email.mailbox.drafts.attachments.store', $draftId), [
            'version' => $initialVersion,
            'attachments' => [UploadedFile::fake()->createWithContent('stale.txt', 'stale')],
        ])->assertStatus(409);

        Sanctum::actingAs($this->peer, ['email.drafts.write']);
        $this->deleteJson(
            route('api.v1.email.mailbox.drafts.attachments.destroy', [$draftId, $attachmentId]),
            ['version' => $currentVersion],
        )->assertNotFound();

        Sanctum::actingAs($this->actor, ['email.drafts.write']);
        $this->deleteJson(
            route('api.v1.email.mailbox.drafts.attachments.destroy', [$draftId, $attachmentId]),
            ['version' => $currentVersion],
        )->assertOk()
            ->assertJsonCount(0, 'data.attachments');

        $this->assertDatabaseMissing('email_composer_draft_attachments', ['public_id' => $attachmentId]);
    }

    #[Test]
    public function preview_send_idempotency_and_sent_reconciliation_use_one_safe_api_contract(): void
    {
        Queue::fake();

        $account = $this->sendableAccount([$this->actor]);
        $mailer = new class extends SmtpAccountMailer
        {
            public int $calls = 0;

            /** @var array<string, mixed> */
            public array $captured = [];

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;
                $this->captured = compact('toRecipients', 'ccRecipients', 'subject', 'html', 'text', 'attachments', 'options');

                return (string) $options['message_id'];
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        Sanctum::actingAs($this->actor, ['email.drafts.read', 'email.drafts.write', 'email.send']);
        $created = $this->createDraft($account, 'API parity', '<p>Hello <strong>API</strong>.</p>');
        $draftId = (string) $created->json('data.id');
        $version = (string) $created->json('data.version');

        $preview = $this->postJson(route('api.v1.email.mailbox.drafts.preview', $draftId), [
            'version' => $version,
        ])->assertOk()
            ->assertJsonPath('data.to.0', 'customer@example.test')
            ->assertJsonPath('data.subject', 'API parity')
            ->assertJsonPath('data.signature.applied', true);
        $this->assertStringContainsString(
            '<p>Hello <strong>API</strong>.</p>',
            (string) $preview->json('data.body_html'),
        );
        $this->assertStringStartsWith('Hello API.', (string) $preview->json('data.body_text'));

        $sent = $this->postJson(route('api.v1.email.mailbox.drafts.send', $draftId), [
            'version' => $version,
            'idempotency_key' => 'api-parity-send-1',
        ])->assertCreated()
            ->assertJsonPath('data.status', EmailOutboundSubmission::STATUS_ACCEPTED)
            ->assertJsonPath('data.email_log.code', 'MAIL_COMPOSE_SENT')
            ->assertJsonPath('data.email_log.delivery_status', 'accepted');
        $submissionId = (string) $sent->json('data.id');

        $this->assertSame(1, $mailer->calls);
        $this->assertSame('API parity', $mailer->captured['subject']);
        $this->assertSame($preview->json('data.body_html'), $mailer->captured['html']);
        $this->assertSame($preview->json('data.body_text'), $mailer->captured['text']);
        $this->assertSame(EmailComposerDraft::STATUS_SENT, EmailComposerDraft::query()->where('public_id', $draftId)->value('status'));

        // The original signed version and exact client key recover the same
        // accepted submission even though the draft is now terminal.
        $this->postJson(route('api.v1.email.mailbox.drafts.send', $draftId), [
            'version' => $version,
            'idempotency_key' => 'api-parity-send-1',
        ])->assertOk()
            ->assertJsonPath('data.id', $submissionId)
            ->assertJsonPath('data.status', EmailOutboundSubmission::STATUS_ACCEPTED);
        $this->assertSame(1, $mailer->calls);

        $this->getJson(route('api.v1.email.mailbox.submissions.show', $submissionId))
            ->assertOk()
            ->assertJsonPath('data.id', $submissionId)
            ->assertJsonPath('data.sent_reconciliation.status', EmailSentReconciliation::STATUS_PENDING);
        Queue::assertPushed(AppendEmailProviderSentCopy::class, 1);

        $submission = EmailOutboundSubmission::query()->where('public_id', $submissionId)->sole();
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Sent',
            'name' => 'Sent',
            'role' => EmailFolder::ROLE_SENT,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 612,
        ]);
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'Sent',
            'imap_uid' => 881,
            'message_id' => trim((string) $submission->reserved_message_id, '<>'),
            'subject' => 'API parity',
            'from_email' => $account->address,
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'Sent',
            'imap_uid_validity' => 612,
            'imap_uid' => 881,
            'provider_seen' => true,
        ]);
        app(EmailSentReconciliationService::class)->reconcilePlacement($placement);

        $this->getJson(route('api.v1.email.mailbox.submissions.sent-reconciliation.show', $submissionId))
            ->assertOk()
            ->assertJsonPath('data.status', EmailOutboundSubmission::STATUS_SENT_RECONCILED)
            ->assertJsonPath('data.sent_reconciliation.status', EmailSentReconciliation::STATUS_RECONCILED);
        $this->assertSame(1, $mailer->calls);
    }

    #[Test]
    public function unresolved_provider_outcome_is_durable_and_never_blindly_retried(): void
    {
        $account = $this->sendableAccount([$this->actor]);
        $mailer = new class extends SmtpAccountMailer
        {
            public int $calls = 0;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;

                throw new RuntimeException('provider response must never reach the API');
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        Sanctum::actingAs($this->actor, ['email.drafts.write', 'email.send']);
        $created = $this->createDraft($account, 'Unresolved API send');
        $draftId = (string) $created->json('data.id');
        $version = (string) $created->json('data.version');
        $route = route('api.v1.email.mailbox.drafts.send', $draftId);

        $first = $this->postJson($route, [
            'version' => $version,
            'idempotency_key' => 'unresolved-api-send-1',
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'email_submission_conflict')
            ->assertJsonPath('data.status', EmailOutboundSubmission::STATUS_OUTCOME_UNRESOLVED);
        $submissionId = (string) $first->json('data.id');
        $this->assertStringNotContainsString('provider response', $first->getContent());

        $this->postJson($route, [
            'version' => $version,
            'idempotency_key' => 'unresolved-api-send-1',
        ])->assertStatus(409)
            ->assertJsonPath('data.id', $submissionId)
            ->assertJsonPath('data.status', EmailOutboundSubmission::STATUS_OUTCOME_UNRESOLVED);
        $this->postJson($route, [
            'version' => $version,
            'idempotency_key' => 'unresolved-api-send-2',
        ])->assertStatus(409);

        $this->assertSame(1, $mailer->calls);
        $this->assertSame(
            EmailComposerDraft::STATUS_SEND_RESERVED,
            EmailComposerDraft::query()->where('public_id', $draftId)->value('status'),
        );
        $this->assertDatabaseCount('email_outbound_submissions', 1);

        $migration = require base_path(
            'database/migrations/2026_08_24_110000_add_private_draft_fencing_and_outbound_submissions.php',
        );
        try {
            $migration->down();
            $this->fail('Rollback must refuse to erase outbound submission evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('must be preserved', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('email_outbound_submissions'));
    }

    #[Test]
    public function provider_binding_drift_and_revoked_send_scope_fail_before_provider_access(): void
    {
        $account = $this->sendableAccount([$this->actor]);
        $mailer = new class extends SmtpAccountMailer
        {
            public int $calls = 0;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;

                return '<must-not-send@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        Sanctum::actingAs($this->actor, ['email.drafts.write', 'email.send']);
        $created = $this->createDraft($account, 'Binding fence');
        $draftId = (string) $created->json('data.id');
        $version = (string) $created->json('data.version');

        DB::table('email_accounts')->where('id', $account->id)->increment('provider_binding_version');
        $this->postJson(route('api.v1.email.mailbox.drafts.send', $draftId), [
            'version' => $version,
            'idempotency_key' => 'binding-drift-send',
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'email_draft_version_conflict');

        $this->assertSame(0, $mailer->calls);
        $this->assertDatabaseCount('email_outbound_submissions', 0);
        $this->assertSame(
            EmailComposerDraft::STATUS_ACTIVE,
            EmailComposerDraft::query()->where('public_id', $draftId)->value('status'),
        );

        EmailAccountUserGrant::query()
            ->where('email_account_id', $account->id)
            ->where('user_id', $this->actor->id)
            ->update(['can_send' => false]);
        $this->postJson(route('api.v1.email.mailbox.drafts.send', $draftId), [
            'version' => $version,
            'idempotency_key' => 'revoked-send',
        ])->assertNotFound();
        $this->assertSame(0, $mailer->calls);
    }

    private function createDraft(
        EmailAccount $account,
        string $subject,
        string $bodyHtml = '<p>API private draft body.</p>',
    ) {
        return $this->postJson(route('api.v1.email.mailbox.drafts.store'), [
            'account_id' => $account->id,
            'mode' => 'compose',
            'to' => 'customer@example.test',
            'subject' => $subject,
            'body_html' => $bodyHtml,
        ])->assertCreated();
    }

    /** @param array<int, User> $users */
    private function sendableAccount(array $users): EmailAccount
    {
        $address = 'order11-'.uniqid().'@example.test';
        $secret = Crypt::encryptString('isolated-order11-secret');
        $account = EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Order 11 isolated test account',
            'from_name' => 'Order 11',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => '8.8.8.8',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => $secret,
            'imap_auth_type' => 'password',
            'smtp_host' => '1.1.1.1',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => $secret,
            'smtp_auth_type' => 'password',
        ]);

        foreach ($users as $user) {
            EmailAccountUserGrant::query()->create([
                'email_account_id' => $account->id,
                'user_id' => $user->id,
                'can_view' => true,
                'can_organize' => false,
                'can_send' => true,
                'granted_at' => now(),
            ]);
        }

        return $account;
    }
}
