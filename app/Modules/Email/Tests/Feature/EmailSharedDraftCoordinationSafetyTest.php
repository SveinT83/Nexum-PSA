<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Livewire\Tech\MailWorkspace;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailOutboundSubmission;
use App\Modules\Email\Services\EmailCollaborationGate;
use App\Modules\Email\Services\EmailLiveRuntimeReadiness;
use App\Modules\Email\Services\EmailPrivateStorage;
use App\Modules\Email\Services\EmailSharedDraftLeaseContext;
use App\Modules\Email\Services\EmailSharedDraftService;
use App\Modules\Email\Services\SmtpAccountMailer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailSharedDraftCoordinationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $peer;

    private User $viewer;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('email.inbox_view', 'web');
        Permission::findOrCreate('email.inbox_manage', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Order 9 Actor']);
        $this->peer = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Order 9 Peer']);
        $this->viewer = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Order 9 Viewer']);
        $this->outsider = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Order 9 Outsider']);
        $this->actor->givePermissionTo(['email.inbox_view', 'email.inbox_manage']);
        $this->peer->givePermissionTo(['email.inbox_view', 'email.inbox_manage']);
        $this->viewer->givePermissionTo('email.inbox_view');
        $this->outsider->givePermissionTo(['email.inbox_view', 'email.inbox_manage']);
        Storage::fake(EmailPrivateStorage::DISK);
        config()->set('email_live.presence_store', 'array');
    }

    #[Test]
    public function collaboration_gate_is_config_first_and_requires_ready_private_live_runtime(): void
    {
        $readiness = new class extends EmailLiveRuntimeReadiness
        {
            public int $calls = 0;

            public function ready(): bool
            {
                $this->calls++;

                return true;
            }
        };
        $this->app->instance(EmailLiveRuntimeReadiness::class, $readiness);
        Sanctum::actingAs($this->actor, ['email.drafts.read']);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        config()->set('email_live.enabled', false);
        config()->set('email_live.collaboration_enabled', true);
        $this->assertFalse(app(EmailCollaborationGate::class)->available());
        $this->assertSame(0, $readiness->calls);
        $this->assertSame(0, $queries);

        config()->set('email_live.enabled', true);
        config()->set('email_live.collaboration_enabled', false);
        $this->assertFalse(app(EmailCollaborationGate::class)->available());
        $this->assertSame(0, $readiness->calls);
        $this->assertSame(0, $queries);

        $this->getJson(route('api.v1.email.mailbox.conversations.presence.show', 999).'?source_placement_id=999')
            ->assertStatus(503);
    }

    #[Test]
    public function mail_workspace_requires_the_separate_ui_gate_and_ignores_a_legacy_lock_table(): void
    {
        Schema::create('email_mail_draft_locks', function (Blueprint $table): void {
            $table->id();
        });
        config()->set('email_live.enabled', true);
        config()->set('email_live.collaboration_enabled', true);
        config()->set('email_live.collaboration_ui_enabled', false);
        $readiness = new class extends EmailLiveRuntimeReadiness
        {
            public function ready(): bool
            {
                return true;
            }
        };
        $gate = new class($readiness) extends EmailCollaborationGate
        {
            public int $calls = 0;

            public bool $available = true;

            public function available(): bool
            {
                $this->calls++;

                return $this->available;
            }
        };
        $this->app->instance(EmailLiveRuntimeReadiness::class, $readiness);
        $this->app->instance(EmailCollaborationGate::class, $gate);
        $this->actingAs($this->actor);

        $gatedWorkspace = app(MailWorkspace::class);
        $gatedWorkspace->mount();

        $this->assertTrue($gatedWorkspace->liveEnabled);
        $this->assertFalse($gatedWorkspace->collaborationEnabled);
        $this->assertSame(0, $gate->calls);
        $this->assertTrue(Schema::hasTable('email_mail_draft_locks'));

        config()->set('email_live.collaboration_ui_enabled', true);
        $gate->available = false;
        $backendGatedWorkspace = app(MailWorkspace::class);
        $backendGatedWorkspace->mount();

        $this->assertFalse($backendGatedWorkspace->collaborationEnabled);
        $this->assertSame(1, $gate->calls);

        $gate->available = true;
        $enabledWorkspace = app(MailWorkspace::class);
        $enabledWorkspace->mount();

        $this->assertTrue($enabledWorkspace->collaborationEnabled);
        $this->assertSame(2, $gate->calls);
    }

    #[Test]
    public function mail_workspace_contains_no_quarantined_sql_lock_or_whisper_fallback(): void
    {
        $workspace = file_get_contents(base_path('app/Modules/Email/Livewire/Tech/MailWorkspace.php'));
        $view = file_get_contents(base_path('app/Modules/Email/Views/Livewire/Tech/mail-workspace.blade.php'));
        $composer = file_get_contents(base_path(
            'app/Modules/Email/Views/Livewire/Tech/partials/mail-composer-form.blade.php',
        ));
        $client = file_get_contents(base_path('resources/js/email-mail-live.js'));

        foreach ([$workspace, $view, $composer, $client] as $source) {
            $this->assertIsString($source);
        }
        foreach (['EmailMailDraftLock', 'EmailPresenceService', 'email_mail_draft_locks'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $workspace);
        }
        foreach (['email-presence-event', 'presenceIndicators'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $view);
        }
        foreach (['startTyping', 'stopTyping'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $composer);
        }
        foreach (['listenForWhisper', '.whisper(', 'onPresence'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $client);
        }
    }

    #[Test]
    public function presence_is_expiring_multi_tab_permission_filtered_and_never_persisted_in_sql(): void
    {
        $this->enableCollaboration();
        $mailbox = $this->mailboxFixture();
        $route = route('api.v1.email.mailbox.conversations.presence.heartbeat', $mailbox['conversation']->id);
        $payload = [
            'source_placement_id' => $mailbox['placement']->id,
            'activity' => 'reading',
            'tab_token' => str_repeat('a', 32),
        ];

        Sanctum::actingAs($this->actor, ['email.drafts.read', 'email.drafts.write']);
        $firstExpiry = $this->postJson($route, $payload)
            ->assertOk()
            ->assertJsonPath('data.accepted', true)
            ->json('data.expires_at');
        $this->postJson($route, $payload)
            ->assertOk()
            ->assertJsonPath('data.expires_at', $firstExpiry);
        $this->postJson($route, [...$payload, 'tab_token' => str_repeat('b', 32)])->assertOk();

        Sanctum::actingAs($this->peer, ['email.drafts.write']);
        $this->postJson($route, [
            'source_placement_id' => $mailbox['placement']->id,
            'activity' => 'typing',
            'tab_token' => str_repeat('c', 32),
        ])->assertOk();

        Sanctum::actingAs($this->viewer, ['email.drafts.read', 'email.drafts.write']);
        $this->postJson($route, [
            'source_placement_id' => $mailbox['placement']->id,
            'activity' => 'typing',
            'tab_token' => str_repeat('d', 32),
        ])->assertNotFound();
        $snapshot = $this->getJson(route(
            'api.v1.email.mailbox.conversations.presence.show',
            $mailbox['conversation']->id,
        ).'?source_placement_id='.$mailbox['placement']->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->assertSame(
            ['Order 9 Actor', 'Order 9 Peer'],
            collect($snapshot->json('data'))->pluck('user_name')->sort()->values()->all(),
        );

        EmailAccountUserGrant::query()
            ->where('email_account_id', $mailbox['account']->id)
            ->where('user_id', $this->peer->id)
            ->update(['can_send' => false]);
        $this->getJson(route(
            'api.v1.email.mailbox.conversations.presence.show',
            $mailbox['conversation']->id,
        ).'?source_placement_id='.$mailbox['placement']->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_name', 'Order 9 Actor');

        Sanctum::actingAs($this->outsider, ['email.drafts.read']);
        $this->getJson(route(
            'api.v1.email.mailbox.conversations.presence.show',
            $mailbox['conversation']->id,
        ).'?source_placement_id='.$mailbox['placement']->id)->assertNotFound();
        $this->assertDatabaseCount('email_shared_draft_events', 0);
        $this->assertDatabaseCount('email_shared_draft_locks', 0);
    }

    #[Test]
    public function explicit_share_is_idempotent_private_to_shared_and_exactly_authorized(): void
    {
        $this->enableCollaboration();
        $mailbox = $this->mailboxFixture();
        $private = $this->createReplyDraft($mailbox);
        $draftId = (string) $private->json('data.id');
        $request = [
            'version' => $private->json('data.version'),
            'idempotency_key' => 'share-order9-one',
        ];

        $shared = $this->postJson(route('api.v1.email.mailbox.drafts.share', $draftId), $request)
            ->assertCreated()
            ->assertJsonPath('data.id', $draftId)
            ->assertJsonPath('data.scope', EmailComposerDraft::SCOPE_SHARED);
        $this->postJson(route('api.v1.email.mailbox.drafts.share', $draftId), $request)
            ->assertOk()
            ->assertJsonPath('data.id', $draftId);
        $this->postJson(route('api.v1.email.mailbox.drafts.share', $draftId), [
            'version' => $request['version'],
            'idempotency_key' => 'different-share-request',
        ])->assertStatus(409);
        $this->getJson(route('api.v1.email.mailbox.drafts.show', $draftId))->assertNotFound();

        foreach (['generation_id', 'lease_token_hash', 'lease_token', 'source_context_fingerprint', '"path"'] as $secret) {
            $this->assertStringNotContainsString($secret, $shared->getContent());
        }

        Sanctum::actingAs($this->peer, ['email.drafts.read']);
        $this->getJson(route('api.v1.email.mailbox.shared-drafts.show', $draftId))
            ->assertOk()
            ->assertJsonPath('data.id', $draftId);

        Sanctum::actingAs($this->viewer, ['email.drafts.read', 'email.drafts.write']);
        $readable = $this->getJson(route('api.v1.email.mailbox.shared-drafts.show', $draftId))->assertOk();
        $this->postJson(route('api.v1.email.mailbox.shared-drafts.lease.acquire', $draftId), [
            'version' => $readable->json('data.version'),
            'content_version' => $readable->json('data.collaboration.content_version'),
            'source_version' => $readable->json('data.collaboration.source_version'),
            'idempotency_key' => 'viewer-cannot-edit',
        ])->assertNotFound();

        Sanctum::actingAs($this->outsider, ['email.drafts.read']);
        $this->getJson(route('api.v1.email.mailbox.shared-drafts.show', $draftId))->assertNotFound();
        $this->assertDatabaseCount('email_shared_draft_locks', 1);
        $this->assertDatabaseCount('email_shared_draft_events', 1);
    }

    #[Test]
    public function leases_are_monotonic_and_stale_composers_cannot_mutate_after_takeover(): void
    {
        $this->enableCollaboration();
        $mailbox = $this->mailboxFixture();
        $shared = $this->shareReplyDraft($mailbox, 'lease-share');
        $draftId = (string) $shared->json('data.id');
        $open = $this->openPayload($shared);
        $acquireRoute = route('api.v1.email.mailbox.shared-drafts.lease.acquire', $draftId);

        $first = $this->postJson($acquireRoute, $open + ['idempotency_key' => 'actor-acquire'])
            ->assertOk()
            ->assertJsonPath('data.fencing_token', 1);
        $firstToken = (string) $first->json('data.lease_token');
        $this->postJson($acquireRoute, $open + ['idempotency_key' => 'actor-acquire'])
            ->assertOk()
            ->assertJsonPath('data.lease_token', $firstToken)
            ->assertJsonPath('data.fencing_token', 1);

        Sanctum::actingAs($this->peer, ['email.drafts.read', 'email.drafts.write']);
        $this->postJson($acquireRoute, $open + ['idempotency_key' => 'peer-blocked'])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'email_shared_draft_locked');

        $this->travel(61)->seconds();
        $takeover = $this->postJson($acquireRoute, $open + ['idempotency_key' => 'peer-expired-takeover'])
            ->assertOk()
            ->assertJsonPath('data.fencing_token', 2);

        Sanctum::actingAs($this->actor, ['email.drafts.read', 'email.drafts.write']);
        $this->patchJson(route('api.v1.email.mailbox.shared-drafts.update', $draftId), [
            ...$open,
            'lease_token' => $firstToken,
            'fencing_token' => 1,
            'subject' => 'Stale actor overwrite',
        ])->assertStatus(423)
            ->assertJsonPath('error.code', 'email_shared_draft_locked');

        Sanctum::actingAs($this->peer, ['email.drafts.read', 'email.drafts.write']);
        $updated = $this->patchJson(route('api.v1.email.mailbox.shared-drafts.update', $draftId), [
            ...$open,
            'lease_token' => $takeover->json('data.lease_token'),
            'fencing_token' => 2,
            'subject' => 'Current peer update',
        ])->assertOk()
            ->assertJsonPath('data.subject', 'Current peer update');
        $this->assertGreaterThan(
            (int) $open['content_version'],
            (int) $updated->json('data.collaboration.content_version'),
        );
        $this->assertDatabaseMissing('email_composer_drafts', [
            'public_id' => $draftId,
            'subject' => 'Stale actor overwrite',
        ]);
    }

    #[Test]
    public function stale_source_rebase_preserves_authored_content_and_shared_send_uses_one_submission(): void
    {
        $this->enableCollaboration();
        $mailbox = $this->mailboxFixture();
        $mailer = new class extends SmtpAccountMailer
        {
            public int $calls = 0;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;

                return (string) $options['message_id'];
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        $shared = $this->shareReplyDraft($mailbox, 'stale-share', '<p>Authored shared body.</p>');
        $draftId = (string) $shared->json('data.id');
        $open = $this->openPayload($shared);
        $lease = $this->postJson(
            route('api.v1.email.mailbox.shared-drafts.lease.acquire', $draftId),
            $open + ['idempotency_key' => 'stale-lease'],
        )->assertOk();
        $context = $open + [
            'lease_token' => $lease->json('data.lease_token'),
            'fencing_token' => $lease->json('data.fencing_token'),
        ];
        $attached = $this->post(route('api.v1.email.mailbox.shared-drafts.attachments.store', $draftId), [
            ...$context,
            'attachments' => [UploadedFile::fake()->createWithContent('keep.txt', 'keep after rebase')],
        ])->assertOk()
            ->assertJsonPath('data.attachments.0.filename', 'keep.txt');
        $context = $this->mutationPayload($attached, $lease);

        $newSource = $this->addConversationMessage($mailbox, 'Newest customer response');
        $this->patchJson(route('api.v1.email.mailbox.shared-drafts.update', $draftId), [
            ...$context,
            'subject' => 'Must not overwrite stale source',
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'email_shared_draft_source_stale');
        $this->assertSame(0, $mailer->calls);
        $this->assertDatabaseCount('email_outbound_submissions', 0);

        $preview = $this->postJson(
            route('api.v1.email.mailbox.shared-drafts.rebase.preview', $draftId),
            $context,
        )->assertOk()
            ->assertJsonPath('data.source_placement_id', $newSource['placement']->id)
            ->assertJsonPath('data.to', 'newest-customer@example.test');
        $rebased = $this->postJson(route('api.v1.email.mailbox.shared-drafts.rebase', $draftId), [
            ...$context,
            'rebase_token' => $preview->json('data.rebase_token'),
            'idempotency_key' => 'confirm-rebase',
        ])->assertOk()
            ->assertJsonPath('data.body_html', '<p>Authored shared body.</p>')
            ->assertJsonPath('data.attachments.0.filename', 'keep.txt')
            ->assertJsonPath('data.source_placement_id', $newSource['placement']->id)
            ->assertJsonPath('data.collaboration.stale', false);
        $sendContext = $this->mutationPayload($rebased, $lease);

        $sent = $this->postJson(route('api.v1.email.mailbox.shared-drafts.send', $draftId), [
            ...$sendContext,
            'idempotency_key' => 'shared-send-once',
        ])->assertCreated()
            ->assertJsonPath('data.status', EmailOutboundSubmission::STATUS_ACCEPTED);
        $submissionId = $sent->json('data.id');
        $this->postJson(route('api.v1.email.mailbox.shared-drafts.send', $draftId), [
            ...$sendContext,
            'idempotency_key' => 'shared-send-once',
        ])->assertOk()
            ->assertJsonPath('data.id', $submissionId);
        $this->assertSame(1, $mailer->calls);
        $this->assertDatabaseCount('email_outbound_submissions', 1);
        $this->assertDatabaseMissing('email_composer_draft_attachments', [
            'email_composer_draft_id' => EmailComposerDraft::query()->where('public_id', $draftId)->value('id'),
        ]);
    }

    #[Test]
    public function a_stale_shared_draft_can_be_discarded_without_provider_access(): void
    {
        $this->enableCollaboration();
        $mailbox = $this->mailboxFixture();
        $shared = $this->shareReplyDraft($mailbox, 'discard-share');
        $draftId = (string) $shared->json('data.id');
        $open = $this->openPayload($shared);
        $lease = $this->postJson(
            route('api.v1.email.mailbox.shared-drafts.lease.acquire', $draftId),
            $open + ['idempotency_key' => 'discard-lease'],
        )->assertOk();
        $context = $this->mutationPayload($shared, $lease);
        $mailbox['conversation']->forceFill([
            'message_count' => 2,
            'last_message_at' => now()->addSecond(),
        ])->save();

        $this->deleteJson(route('api.v1.email.mailbox.shared-drafts.discard', $draftId), [
            ...$context,
            'idempotency_key' => 'discard-stale-draft',
        ])->assertOk()
            ->assertJsonPath('data.status', EmailComposerDraft::STATUS_DISCARDED);
        $this->assertDatabaseHas('email_composer_drafts', [
            'public_id' => $draftId,
            'status' => EmailComposerDraft::STATUS_DISCARDED,
        ]);
        $this->assertDatabaseCount('email_outbound_submissions', 0);
    }

    #[Test]
    public function attachment_files_are_not_deleted_when_discard_evidence_rolls_back(): void
    {
        $this->enableCollaboration();
        $mailbox = $this->mailboxFixture();
        $shared = $this->shareReplyDraft($mailbox, 'rollback-share');
        $draftId = (string) $shared->json('data.id');
        $open = $this->openPayload($shared);
        $lease = $this->postJson(
            route('api.v1.email.mailbox.shared-drafts.lease.acquire', $draftId),
            $open + ['idempotency_key' => 'rollback-lease'],
        )->assertOk();
        $attached = $this->post(route('api.v1.email.mailbox.shared-drafts.attachments.store', $draftId), [
            ...$this->mutationPayload($shared, $lease),
            'attachments' => [UploadedFile::fake()->createWithContent('rollback.txt', 'must survive rollback')],
        ])->assertOk();
        $attachment = EmailComposerDraft::query()
            ->where('public_id', $draftId)
            ->sole()
            ->attachments()
            ->sole();
        Storage::disk($attachment->disk)->assertExists($attachment->path);
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_order9_discard_event
            BEFORE INSERT ON email_shared_draft_events
            WHEN NEW.event_type = 'discarded'
            BEGIN
                SELECT RAISE(ABORT, 'forced discard event failure');
            END
        SQL);
        $context = new EmailSharedDraftLeaseContext(
            (string) $lease->json('data.lease_token'),
            (int) $lease->json('data.fencing_token'),
            (int) $attached->json('data.collaboration.content_version'),
            (string) $attached->json('data.collaboration.source_version'),
        );

        try {
            app(EmailSharedDraftService::class)->discard(
                EmailComposerDraft::query()->where('public_id', $draftId)->sole(),
                $this->actor,
                $context,
                'forced-rollback-discard',
            );
            $this->fail('The forced evidence failure must roll back the discard.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('forced discard event failure', $exception->getMessage());
        }

        $this->assertDatabaseHas('email_composer_drafts', [
            'public_id' => $draftId,
            'status' => EmailComposerDraft::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('email_composer_draft_attachments', ['id' => $attachment->id]);
        Storage::disk($attachment->disk)->assertExists($attachment->path);
    }

    private function enableCollaboration(): void
    {
        config()->set('email_live.enabled', true);
        config()->set('email_live.collaboration_enabled', true);
        $this->app->instance(EmailLiveRuntimeReadiness::class, new class extends EmailLiveRuntimeReadiness
        {
            public function ready(): bool
            {
                return true;
            }
        });
    }

    /** @param array<string, mixed> $mailbox */
    private function createReplyDraft(array $mailbox, string $body = '<p>Shared reply body.</p>')
    {
        Sanctum::actingAs($this->actor, ['email.drafts.read', 'email.drafts.write', 'email.send']);

        return $this->postJson(route('api.v1.email.mailbox.drafts.store'), [
            'account_id' => $mailbox['account']->id,
            'source_placement_id' => $mailbox['placement']->id,
            'mode' => 'reply',
            'body_html' => $body,
        ])->assertCreated();
    }

    /** @param array<string, mixed> $mailbox */
    private function shareReplyDraft(array $mailbox, string $key, string $body = '<p>Shared reply body.</p>')
    {
        $private = $this->createReplyDraft($mailbox, $body);

        return $this->postJson(route('api.v1.email.mailbox.drafts.share', $private->json('data.id')), [
            'version' => $private->json('data.version'),
            'idempotency_key' => $key,
        ])->assertCreated();
    }

    /** @return array<string, mixed> */
    private function openPayload($response): array
    {
        return [
            'version' => $response->json('data.version'),
            'content_version' => $response->json('data.collaboration.content_version'),
            'source_version' => $response->json('data.collaboration.source_version'),
        ];
    }

    /** @return array<string, mixed> */
    private function mutationPayload($draft, $lease): array
    {
        return $this->openPayload($draft) + [
            'lease_token' => $lease->json('data.lease_token'),
            'fencing_token' => $lease->json('data.fencing_token'),
        ];
    }

    /** @return array<string, mixed> */
    private function mailboxFixture(): array
    {
        $address = 'order9-'.Str::uuid().'@example.test';
        $secret = Crypt::encryptString('isolated-order9-secret');
        $account = EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Order 9 isolated shared mailbox',
            'from_name' => 'Order 9',
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
            'provider_binding_version' => 1,
        ]);

        foreach ([
            [$this->actor, true],
            [$this->peer, true],
            [$this->viewer, false],
        ] as [$user, $canSend]) {
            EmailAccountUserGrant::query()->create([
                'email_account_id' => $account->id,
                'user_id' => $user->id,
                'can_view' => true,
                'can_organize' => false,
                'can_send' => $canSend,
                'granted_at' => now(),
            ]);
        }

        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 909,
        ]);
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => 909,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'order9_test',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid_validity' => 909,
            'imap_uid' => 1,
            'message_id' => '<order9-source@example.test>',
            'subject' => 'Order 9 source',
            'from_name' => 'First Customer',
            'from_email' => 'first-customer@example.test',
            'to_json' => [['name' => 'Order 9', 'email' => $address]],
            'cc_json' => [],
            'received_at' => now(),
            'state' => 'untriaged',
            'references' => '<order9-root@example.test>',
            'body_text' => 'Initial source body.',
        ]);
        $conversation = EmailConversation::query()->create([
            'account_id' => $account->id,
            'conversation_key' => 'order9:'.Str::uuid(),
            'status' => EmailConversation::STATUS_ACTIVE,
            'subject' => $message->subject,
            'first_email_message_id' => $message->id,
            'latest_email_message_id' => $message->id,
            'message_count' => 1,
            'active_placement_count' => 1,
            'provider_unread_count' => 1,
            'first_message_at' => $message->received_at,
            'last_message_at' => $message->received_at,
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversation->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'provider' => 'imap',
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 909,
            'imap_uid' => 1,
            'provider_seen' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);
        $conversation->forceFill(['latest_email_mailbox_placement_id' => $placement->id])->save();

        return compact('account', 'folder', 'namespace', 'message', 'conversation', 'placement');
    }

    /** @param array<string, mixed> $mailbox
     * @return array<string, mixed>
     */
    private function addConversationMessage(array $mailbox, string $subject): array
    {
        $message = EmailMessage::query()->create([
            'account_id' => $mailbox['account']->id,
            'mailbox' => 'INBOX',
            'imap_uid_validity' => 909,
            'imap_uid' => 2,
            'message_id' => '<order9-newest@example.test>',
            'in_reply_to' => '<order9-source@example.test>',
            'references' => '<order9-root@example.test> <order9-source@example.test>',
            'subject' => $subject,
            'from_name' => 'Newest Customer',
            'from_email' => 'newest-customer@example.test',
            'to_json' => [['name' => 'Order 9', 'email' => $mailbox['account']->address]],
            'cc_json' => [],
            'received_at' => now()->addSecond(),
            'state' => 'untriaged',
            'body_text' => 'Newest source body.',
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $mailbox['conversation']->id,
            'account_id' => $mailbox['account']->id,
            'email_folder_id' => $mailbox['folder']->id,
            'uid_namespace_id' => $mailbox['namespace']->id,
            'provider' => 'imap',
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 909,
            'imap_uid' => 2,
            'provider_seen' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);
        $mailbox['conversation']->forceFill([
            'latest_email_message_id' => $message->id,
            'latest_email_mailbox_placement_id' => $placement->id,
            'message_count' => 2,
            'active_placement_count' => 2,
            'provider_unread_count' => 2,
            'last_message_at' => $message->received_at,
        ])->save();

        return compact('message', 'placement');
    }
}
