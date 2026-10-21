<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailMailboxApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $nextUid = 50000;

    protected function setUp(): void
    {
        parent::setUp();

        $view = Permission::findOrCreate('email.inbox_view', 'web');
        $manage = Permission::findOrCreate('email.inbox_manage', 'web');
        Role::findOrCreate('Mail API parity tech', 'web')->givePermissionTo([$view, $manage]);

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Mail API parity tech');
    }

    #[Test]
    public function reads_share_browser_grouping_without_acknowledgement_and_shared_read_is_local(): void
    {
        [$account, $folder] = $this->mailbox('mail-api@example.test');
        $one = $this->durableConversation($account, $folder, 'Visible conversation', now());
        $id = $one['placement']->id;
        Sanctum::actingAs($this->actor, ['email.mailbox.read', 'email.personal-state.write']);
        $this->getJson('/api/v1/email/mailbox/context')->assertOk()
            ->assertJsonPath('data.accounts.0.id', $account->id)
            ->assertDontSee('query-test-secret')->assertDontSee('imap_secret');
        $this->getJson('/api/v1/email/mailbox/accounts/'.$account->id.'/folders')->assertOk()
            ->assertJsonPath('data.0.path', 'INBOX');
        $this->getJson('/api/v1/email/mailbox/conversations?view=all')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'Visible conversation');
        $this->getJson('/api/v1/email/mailbox/placements/'.$id)->assertOk()
            ->assertJsonPath('data.message.body_text', 'Body for Visible conversation')
            ->assertJsonPath('data.unread_for_me', true);
        $this->assertSame(0, EmailMessageUserState::query()->count());
        $this->postJson('/api/v1/email/mailbox/placements/'.$id.'/opened')->assertOk();
        $this->getJson('/api/v1/email/mailbox/placements/'.$id)->assertJsonPath('data.unread_for_me', true);
        $this->postJson('/api/v1/email/mailbox/placements/'.$id.'/read-state', ['read' => true])
            ->assertOk()->assertJsonPath('data.local_status', 'succeeded')->assertJsonPath('data.provider_status', 'not_requested');
        $this->getJson('/api/v1/email/mailbox/placements/'.$id)->assertJsonPath('data.unread_for_me', false);
        $this->postJson('/api/v1/email/mailbox/placements/'.$id.'/read-state', ['read' => false])->assertOk();
        $this->assertFalse((bool) $one['placement']->fresh()->provider_seen);
        $this->assertSame(0, EmailRemoteOperation::query()->count());
    }

    #[Test]
    public function token_peer_revoked_and_invalid_input_cannot_read_or_write_outside_current_scope(): void
    {
        [$account, $folder] = $this->mailbox('isolated-mail@example.test');
        $one = $this->durableConversation($account, $folder, 'Secret conversation', now());
        $id = $one['placement']->id;
        Sanctum::actingAs($this->actor, ['email.mailbox.read']);
        $this->postJson('/api/v1/email/mailbox/placements/'.$id.'/read-state', ['read' => true])->assertForbidden();
        $this->getJson('/api/v1/email/mailbox/conversations?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/email/mailbox/conversations?account_id=999999')->assertNotFound();
        $peer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $peer->givePermissionTo(['email.inbox_view', 'email.inbox_manage']);
        Sanctum::actingAs($peer, ['email.mailbox.read', 'email.personal-state.write']);
        $this->getJson('/api/v1/email/mailbox/placements/'.$id)->assertNotFound();
        $this->getJson('/api/v1/email/mailbox/conversations?view=all')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($this->actor, ['email.mailbox.read']);
        \Illuminate\Support\Facades\DB::transaction(function () use ($account): void {
            $generation = app(\App\Modules\Email\Services\EmailLiveAuthorityCoordinator::class)
                ->prepareAccountMutation($account, [$this->actor->id]);
            EmailAccountUserGrant::query()->where('email_account_id', $account->id)->firstOrFail()
                ->forceFill(['can_view' => false, 'can_organize' => false, 'can_send' => false,
                    'email_live_enable_generation' => $generation])->save();
        });
        $this->getJson('/api/v1/email/mailbox/placements/'.$id)->assertNotFound();
        $this->assertSame(0, EmailMessageUserState::query()->count());
    }

    #[Test]
    public function signature_read_is_pure_and_update_roundtrips_sanitized_own_content(): void
    {
        Sanctum::actingAs($this->actor, ['email.signature.read', 'email.signature.write']);
        $this->getJson('/api/v1/email/mailbox/signature')->assertOk();
        $this->assertSame(0, \App\Modules\Email\Models\EmailSignature::query()->count());
        $this->patchJson('/api/v1/email/mailbox/signature', [
            'name' => 'API signature', 'body_html' => '<p>Own signature</p><script>alert(1)</script>',
            'use_on_reply' => false,
        ])->assertOk()->assertJsonPath('data.name', 'API signature')->assertJsonPath('data.use_on_reply', false);
        $response = $this->getJson('/api/v1/email/mailbox/signature')->assertOk()
            ->assertJsonPath('data.use_on_compose', true);
        $this->assertStringNotContainsString('<script', (string) $response->json('data.body_html'));
        Sanctum::actingAs($this->actor, ['email.signature.read']);
        $this->patchJson('/api/v1/email/mailbox/signature', ['name' => 'Denied'])->assertForbidden();
    }

    #[Test]
    public function preferences_persist_only_visible_own_folders_and_refresh_is_truthfully_queued(): void
    {
        [$account, $folder] = $this->mailbox('folder-api@example.test');
        Sanctum::actingAs($this->actor, ['email.personal-state.write', 'email.mailbox.read', 'email.sync.execute']);
        $this->patchJson('/api/v1/email/mailbox/preferences', [
            'folder_ids' => [$folder->id], 'expanded' => true,
        ])->assertOk()->assertJsonPath('data.0.email_folder_id', $folder->id)
            ->assertJsonPath('data.0.is_expanded', true);
        $this->patchJson('/api/v1/email/mailbox/preferences', [
            'folder_ids' => [999999], 'expanded' => false,
        ])->assertNotFound();
        \Illuminate\Support\Facades\Queue::fake();
        $this->postJson('/api/v1/email/mailbox/accounts/'.$account->id.'/refresh', [
            'folder_id' => $folder->id,
        ])->assertStatus(202)->assertJsonPath('data.status', 'queued')->assertJsonPath('data.effective_scope', 'account');
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Modules\Email\Jobs\FetchImapAccount::class, 1);
        $this->getJson('/api/v1/email/mailbox/accounts/'.$account->id.'/refresh')->assertOk();
    }

    #[Test]
    public function ordinary_delete_in_api_and_browser_requests_trash_and_preserves_the_source(): void
    {
        [$account, $folder] = $this->mailbox('trash-api@example.test');
        $one = $this->durableConversation($account, $folder, 'Keep source', now());
        $operation = (new EmailRemoteOperation)->forceFill([
            'id' => 901, 'account_id' => $account->id, 'operation_type' => 'trash', 'status' => 'succeeded',
        ]);
        $this->mock(\App\Modules\Email\Actions\PerformEmailRemoteOperation::class, function ($mock) use ($operation): void {
            $mock->shouldReceive('handle')->twice()
                ->withArgs(fn ($placement, $operationType, $actor) => $operationType === 'trash' && $actor->id === $this->actor->id)
                ->andReturn($operation);
        });
        Sanctum::actingAs($this->actor, ['email.update']);
        $this->deleteJson('/api/v1/email/mailbox/placements/'.$one['placement']->id)
            ->assertOk()->assertJsonPath('data.operation_type', 'trash');
        $this->actingAs($this->actor)->delete(route('tech.inbox.delete', $one['message']))->assertRedirect();
        $this->assertNotNull(EmailMessage::query()->find($one['message']->id));
        $this->assertNull($one['message']->fresh()->deleted_at);
    }

    #[Test]
    public function administrator_template_writes_roundtrip_and_account_reads_never_expose_passwords(): void
    {
        [$account] = $this->mailbox('admin-api@example.test');
        Sanctum::actingAs($this->actor, ['*']);
        $this->getJson('/api/v1/email/admin/accounts')->assertForbidden();
        Role::findOrCreate('Admin', 'web');
        $this->actor->assignRole('Admin');
        foreach (['email.account_manage', 'email.mailbox_sync_manage', 'email.template_manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->actor->givePermissionTo($permission);
        }
        Sanctum::actingAs($this->actor->fresh(), ['*']);
        $this->getJson('/api/v1/email/admin/accounts/'.$account->id)->assertOk()
            ->assertJsonPath('data.passwords_configured.imap', true)->assertDontSee('query-test-secret')
            ->assertDontSee('imap_secret')->assertDontSee('smtp_secret');
        $payload = ['scope' => 'tickets', 'key' => 'mail_api_evidence', 'name' => 'API template',
            'subject' => 'First subject', 'body_html' => '<p>Visible template</p>', 'is_active' => true];
        $created = $this->postJson('/api/v1/email/templates', $payload)->assertCreated();
        $id = $created->json('data.id');
        $payload['subject'] = 'Updated subject';
        $this->putJson('/api/v1/email/templates/'.$id, $payload)->assertOk()
            ->assertJsonPath('data.subject', 'Updated subject');
        $this->getJson('/api/v1/email/templates/'.$id)->assertOk()->assertJsonPath('data.subject', 'Updated subject');
        $this->assertDatabaseHas('activity_log', ['event' => 'email.templates.update']);
        Sanctum::actingAs($this->actor, ['email.templates.read']);
        $this->putJson('/api/v1/email/templates/'.$id, $payload)->assertForbidden();
    }

    #[Test]
    public function owner_delegation_write_grants_reads_and_revoke_removes_access(): void
    {
        $this->freezeTime();
        [$account, $folder] = $this->mailbox('delegation-api@example.test', true);
        $one = $this->durableConversation($account, $folder, 'Delegated source', now());
        $peer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $peer->givePermissionTo(['email.inbox_view', 'email.inbox_manage']);
        Sanctum::actingAs($this->actor, ['email.access.manage', 'email.access.read']);
        $created = $this->postJson('/api/v1/email/mailbox/accounts/'.$account->id.'/delegations', [
            'delegate_id' => $peer->id, 'can_view' => true, 'can_organize' => false,
            'reason' => 'Temporary API delegation test', 'starts_at' => now()->toIso8601String(),
            'expires_at' => now()->addHour()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.can_view', true);
        $delegation = $created->json('data.id');
        Sanctum::actingAs($peer, ['email.mailbox.read']);
        $this->getJson('/api/v1/email/mailbox/placements/'.$one['placement']->id)->assertOk();
        Sanctum::actingAs($this->actor, ['email.access.manage']);
        $this->postJson('/api/v1/email/mailbox/accounts/'.$account->id.'/delegations/'.$delegation.'/revoke',
            ['reason' => 'API delegation test completed'])->assertOk();
        Sanctum::actingAs($peer, ['email.mailbox.read']);
        $this->getJson('/api/v1/email/mailbox/placements/'.$one['placement']->id)->assertNotFound();
    }

    #[Test]
    public function handover_preview_apply_and_readback_use_exact_frozen_scope(): void
    {
        [$account, $folder] = $this->mailbox('handover-api@example.test', true);
        $one = $this->durableConversation($account, $folder, 'Handover source', now()->subHour());
        Sanctum::actingAs($this->actor, ['email.handovers.manage']);
        $path = '/api/v1/email/mailbox/accounts/'.$account->id.'/unread-handovers';
        $payload = ['target_user_id' => $this->actor->id, 'folder_ids' => [$folder->id],
            'date_from' => now()->subDay()->toIso8601String(), 'date_to' => now()->toIso8601String(),
            'maximum' => 10, 'reason' => 'Explicit bounded API handover', 'idempotency_key' => (string) Str::uuid()];
        $preview = $this->postJson($path.'/preview', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'previewed')->assertJsonPath('data.selected_count', 1);
        $id = $preview->json('data.id');
        $this->postJson($path.'/'.$id.'/apply')->assertOk()->assertJsonPath('data.status', 'applied');
        $this->getJson($path.'/'.$id)->assertOk()->assertJsonPath('data.already_unread_count', 1);
        $this->postJson($path.'/'.$id.'/apply')->assertOk()->assertJsonPath('data.already_unread_count', 1);
        $this->assertFalse((bool) $one['placement']->fresh()->provider_seen);
        $payload['folder_ids'] = [999999];
        $payload['idempotency_key'] = (string) Str::uuid();
        $this->postJson($path.'/preview', $payload)->assertUnprocessable();
    }

    #[Test]
    public function exact_source_attachment_bytes_require_both_token_and_current_mailbox_authority(): void
    {
        [$account, $folder] = $this->mailbox('binary-api@example.test');
        $one = $this->durableConversation($account, $folder, 'Attachment source', now());
        \Illuminate\Support\Facades\Storage::fake(\App\Modules\Email\Services\EmailPrivateStorage::DISK);
        $disk = \Illuminate\Support\Facades\Storage::disk(\App\Modules\Email\Services\EmailPrivateStorage::DISK);
        $disk->put('email/attachments/api/source.bin', 'Exact API part bytes');
        $attachment = \App\Modules\Email\Models\EmailAttachment::query()->create([
            'message_id' => $one['message']->id, 'filename' => 'source.bin',
            'disk' => \App\Modules\Email\Services\EmailPrivateStorage::DISK,
            'path' => 'email/attachments/api/source.bin', 'size_bytes' => 20, 'mime' => 'application/octet-stream',
        ]);
        $path = '/api/v1/email/mailbox/placements/'.$one['placement']->id.'/attachments/'.$attachment->id.'/download';
        Sanctum::actingAs($this->actor, ['email.mailbox.read']);
        $this->getJson($path)->assertForbidden();
        Sanctum::actingAs($this->actor, ['email.attachments.read']);
        $this->get($path)->assertOk()->assertStreamedContent('Exact API part bytes')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        [$otherAccount, $otherFolder] = $this->mailbox('other-binary-api@example.test');
        $other = $this->durableConversation($otherAccount, $otherFolder, 'Other source', now());
        $this->get('/api/v1/email/mailbox/placements/'.$other['placement']->id.'/attachments/'.$attachment->id.'/download')->assertNotFound();
    }

    #[Test]
    public function admin_account_update_persists_grants_masks_secrets_and_waits_for_connection_test(): void
    {
        [$account] = $this->mailbox('account-write-api@example.test');
        foreach (['email.account_manage', 'email.mailbox_sync_manage'] as $name) {
            $this->actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        \Illuminate\Support\Facades\Queue::fake();
        Sanctum::actingAs($this->actor, ['email.accounts.manage']);
        $payload = ['address' => $account->address, 'description' => 'Updated by API', 'account_kind' => 'shared',
            'is_active' => true, 'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.com', 'imap_port' => 993, 'imap_encryption' => 'implicit_tls',
            'imap_username' => $account->address, 'imap_secret' => 'test-only-incoming',
            'smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_encryption' => 'starttls',
            'smtp_username' => $account->address, 'smtp_secret' => 'test-only-outgoing',
            'grants' => [['user_id' => $this->actor->id, 'can_view' => true, 'can_organize' => true]]];
        $this->putJson('/api/v1/email/admin/accounts/'.$account->id, $payload)->assertOk()
            ->assertJsonPath('data.description', 'Updated by API')->assertJsonPath('data.is_active', false)
            ->assertDontSee('test-only-incoming')->assertDontSee('test-only-outgoing');
        $this->assertSame('Updated by API', $account->fresh()->description);
        $this->assertSame('Testing', $account->fresh()->last_test_result);
        $this->assertSame('test-only-incoming', \Illuminate\Support\Facades\Crypt::decryptString($account->fresh()->imap_secret));
        $this->putJson('/api/v1/email/admin/accounts/'.$account->id.'/activation', ['active' => true])->assertUnprocessable();
        $this->getJson('/api/v1/email/admin/accounts/'.$account->id)->assertOk()->assertJsonPath('data.grants.0.can_view', true);
    }

    #[Test]
    public function maintenance_denies_without_browser_permission_and_live_endpoint_preserves_default_off_gate(): void
    {
        [$account] = $this->mailbox('maintenance-api@example.test');
        Sanctum::actingAs($this->actor, ['email.maintenance.read', 'email.maintenance.execute', 'email.mailbox.read']);
        $path = '/api/v1/email/admin/accounts/'.$account->id.'/maintenance';
        $this->getJson($path)->assertForbidden();
        foreach (['email.account_manage', 'email.mailbox_sync_manage'] as $name) {
            $this->actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        Sanctum::actingAs($this->actor->fresh(), ['email.maintenance.read', 'email.mailbox.read']);
        $this->getJson($path)->assertOk()->assertDontSee('query-test-secret');
        $this->postJson('/api/v1/email/admin/accounts/'.$account->id.'/historical-import/preview', [])->assertForbidden();
        config(['email.live.enabled' => false]);
        $this->getJson('/api/v1/email/mailbox/live?version=0&authorization_epoch=0&global_authorization_generation=0')->assertStatus(503);
    }

    #[Test]
    public function personal_rule_creation_and_history_context_are_available_with_ordinary_mail_authority(): void
    {
        [$account, $folder] = $this->mailbox('personal-rule-api@example.test', true);
        $one = $this->durableConversation($account, $folder, 'Rule source', now());
        $archive = EmailFolder::query()->create(['account_id' => $account->id, 'path' => 'Archive', 'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE, 'is_selectable' => true, 'sync_enabled' => true]);
        Sanctum::actingAs($this->actor, ['email.mailbox.read', 'email.personal-rules.write']);
        $path = '/api/v1/email/mailbox/placements/'.$one['placement']->id;
        $this->getJson($path.'/rule-context')->assertOk()->assertJsonPath('data.personal_rule.available', true)
            ->assertJsonPath('data.personal_rule.target_folders.0.id', $archive->id);
        $this->getJson($path.'/rule-executions')->assertOk()->assertJsonCount(0, 'data');
        $created = $this->postJson($path.'/personal-rule', ['condition_field' => 'from',
            'condition_value' => 'customer@example.test', 'action_type' => 'archive'])
            ->assertCreated();
        $this->assertDatabaseHas('email_rules', ['id' => $created->json('data.id'), 'rule_kind' => EmailRule::KIND_PERSONAL_SIMPLE]);
        $this->assertSame(0, EmailRemoteOperation::query()->count());
    }

    #[Test]
    public function canonical_backfill_api_previews_frozen_scope_and_rejects_unconfirmed_apply(): void
    {
        [$account, $folder] = $this->mailbox('cutover-api@example.test');
        $one = $this->durableConversation($account, $folder, 'Preview only source', now());
        foreach (['email.account_manage', 'email.mailbox_sync_manage', 'email.canonical_cutover_manage'] as $name) {
            $this->actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        Sanctum::actingAs($this->actor, ['email.maintenance.read', 'email.maintenance.execute']);
        $preview = $this->postJson('/api/v1/email/admin/canonical-cutover/backfill', [
            'account_ids' => [$account->id], 'min_message_id' => $one['message']->id,
            'max_message_id' => $one['message']->id, 'item_cap' => 10,
        ])->assertOk()->assertJsonPath('data.run.status', 'previewed');
        $id = $preview->json('data.run.id');
        $this->getJson('/api/v1/email/admin/canonical-cutover/'.$id)->assertOk();
        $this->postJson('/api/v1/email/admin/canonical-cutover/'.$id.'/apply', ['confirmation' => 'WRONG'])
            ->assertUnprocessable();
        $this->assertSame('previewed', \App\Modules\Email\Models\EmailCanonicalCutoverRun::query()->findOrFail($id)->status);
    }

    #[Test]
    public function raw_source_download_checks_raw_permission_and_returns_exact_eml_bytes(): void
    {
        [$account, $folder] = $this->mailbox('raw-api@example.test');
        $one = $this->durableConversation($account, $folder, 'Raw source', now());
        \Illuminate\Support\Facades\Storage::fake(\App\Modules\Email\Services\EmailPrivateStorage::DISK);
        $path = 'email/raw/'.$account->id.'/source.eml';
        $raw = "Message-ID: <raw-api@example.test>\r\nSubject: Exact source\r\n\r\nRaw payload";
        \Illuminate\Support\Facades\Storage::disk(\App\Modules\Email\Services\EmailPrivateStorage::DISK)->put($path, $raw);
        $one['message']->forceFill(['raw_path' => $path])->save();
        Sanctum::actingAs($this->actor, ['email.raw.read']);
        $url = '/api/v1/email/mailbox/placements/'.$one['placement']->id.'/raw-source';
        $this->getJson($url)->assertNotFound();
        $this->actor->givePermissionTo(Permission::findOrCreate('email.raw_source_view', 'web'));
        Sanctum::actingAs($this->actor->fresh(), ['email.raw.read']);
        $this->get($url)->assertOk()->assertStreamedContent($raw)->assertHeader('Content-Type', 'message/rfc822');
    }

    #[Test]
    public function personal_read_success_is_preserved_when_provider_mirroring_is_denied(): void
    {
        [$account, $folder] = $this->mailbox('read-partial-api@example.test', true);
        $one = $this->durableConversation($account, $folder, 'Partial provider outcome', now());
        $this->mock(\App\Modules\Email\Actions\PerformEmailRemoteOperation::class, function ($mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(new \Illuminate\Auth\Access\AuthorizationException);
        });
        Sanctum::actingAs($this->actor, ['email.personal-state.write', 'email.mailbox.read']);
        $path = '/api/v1/email/mailbox/placements/'.$one['placement']->id;
        $this->postJson($path.'/read-state', ['read' => true])->assertOk()
            ->assertJsonPath('data.local_status', 'succeeded')->assertJsonPath('data.provider_status', 'failed');
        $this->getJson($path)->assertOk()->assertJsonPath('data.unread_for_me', false);
        $this->assertFalse((bool) $one['placement']->fresh()->provider_seen);
    }

    #[Test]
    public function manual_ticket_creation_retries_return_one_atomic_ticket_and_preserve_mail(): void
    {
        [$account, $folder] = $this->mailbox('ticket-create-api@example.test');
        $one = $this->durableConversation($account, $folder, 'Manual API Ticket', now());
        $this->actor->givePermissionTo(Permission::findOrCreate('ticket.create', 'web'));
        \Illuminate\Support\Facades\Queue::fake();
        Sanctum::actingAs($this->actor, ['email.tickets.write', 'tickets.create']);
        $path = '/api/v1/email/mailbox/placements/'.$one['placement']->id.'/tickets';
        $created = $this->postJson($path)->assertCreated();
        $id = $created->json('data.ticket_id');
        $this->postJson($path)->assertCreated()->assertJsonPath('data.ticket_id', $id);
        $this->assertSame(1, \App\Modules\Ticket\Models\Ticket::query()->count());
        $this->assertNotNull($one['message']->fresh());
        $this->assertSame($id, $one['message']->fresh()->ticket_id);
    }

    #[Test]
    public function mailbox_spam_supports_selected_non_inbox_source_and_preserves_separate_rule_provider_outcomes(): void
    {
        [$account] = $this->mailbox('spam-api@example.test');
        $folder = EmailFolder::query()->create(['account_id' => $account->id, 'path' => 'Work', 'name' => 'Work',
            'role' => EmailFolder::ROLE_CUSTOM, 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 500]);
        $one = $this->durableConversation($account, $folder, 'Selected non Inbox spam', now());
        Sanctum::actingAs($this->actor, ['email.update']);
        $path = '/api/v1/email/mailbox/placements/'.$one['placement']->id.'/spam';
        $this->postJson($path)->assertOk()->assertJsonPath('data.rule_status', 'succeeded')
            ->assertJsonPath('data.archive_status', 'not_requested');
        $this->postJson($path)->assertOk();
        $this->assertSame(1, EmailRule::query()->count());
        $this->assertSame('archived', $one['message']->fresh()->state);
        $this->assertSame($folder->id, $one['placement']->fresh()->email_folder_id);
    }

    #[Test]
    public function inspection_projection_uses_current_full_account_when_display_relation_is_partial(): void
    {
        [$account] = $this->mailbox('partial-account-api@example.test');
        $message = $this->message($account, 'Authorized inspection', now());
        $message->setRelation('account', new EmailAccount(['id' => $account->id, 'address' => $account->address]));
        Sanctum::actingAs($this->actor, ['email.maintenance.read']);
        $project = app(\App\Modules\Email\Support\EmailApiProjection::class);
        $this->assertSame('Body for Authorized inspection', $project->value($message)['body_text']);
        $peer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $peer->givePermissionTo('email.inbox_view');
        Sanctum::actingAs($peer, ['email.maintenance.read']);
        $this->assertNull($project->value($message));
    }

    private function mailbox(string $address, bool $personal = false): array
    {
        $account = EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Mail conversation query test account',
            'from_name' => 'Mail Query Test',
            'account_kind' => $personal ? EmailAccount::KIND_PERSONAL : EmailAccount::KIND_SHARED,
            'owner_id' => $personal ? $this->actor->id : null,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'query-test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'query-test-secret',
            'smtp_auth_type' => 'password',
        ]);
        $grant = EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $this->actor->id,
            'can_view' => true,
            'can_organize' => true,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        app(EmailUnreadAccessEpochService::class)->reconcileAfterMutation(
            $account,
            $this->actor,
            false,
            $personal
                ? EmailUnreadAccessEpochService::SOURCE_PERSONAL_OWNER
                : EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            $personal ? 'owner:'.$this->actor->id : 'grant:'.$grant->id,
            $this->actor,
        );
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => ++$this->nextUid,
        ]);

        return [$account, $folder];
    }

    private function durableConversation(
        EmailAccount $account,
        EmailFolder $folder,
        string $subject,
        Carbon $receivedAt,
        bool $providerSeen = false,
    ): array {
        $message = $this->message($account, $subject, $receivedAt);
        $conversation = EmailConversation::query()->create([
            'account_id' => $account->id,
            'conversation_key' => 'message:'.$message->id,
            'status' => EmailConversation::STATUS_ACTIVE,
            'subject' => $subject,
            'first_email_message_id' => $message->id,
            'latest_email_message_id' => $message->id,
            'message_count' => 1,
            'active_placement_count' => 1,
            'provider_unread_count' => $providerSeen ? 0 : 1,
            'has_attachments' => false,
            'first_message_at' => $receivedAt,
            'last_message_at' => $receivedAt,
        ]);
        $placement = $this->placement($account, $folder, $message, $conversation, $providerSeen);
        $conversation->forceFill(['latest_email_mailbox_placement_id' => $placement->id])->save();

        return compact('conversation', 'message', 'placement');
    }

    private function message(
        EmailAccount $account,
        string $subject,
        Carbon $receivedAt,
        ?string $messageId = null,
        ?string $inReplyTo = null,
    ): EmailMessage {
        $uid = ++$this->nextUid;

        return EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => $messageId ?? '<query-'.$uid.'@example.test>',
            'in_reply_to' => $inReplyTo,
            'references' => $inReplyTo,
            'subject' => $subject,
            'from_name' => 'Customer',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => $account->address]],
            'received_at' => $receivedAt,
            'state' => 'untriaged',
            'body_text' => 'Body for '.$subject,
            'checksum_sha1' => sha1($subject.'|'.$uid),
        ]);
    }

    private function placement(
        EmailAccount $account,
        EmailFolder $folder,
        EmailMessage $message,
        ?EmailConversation $conversation,
        bool $providerSeen,
    ): EmailMailboxPlacement {
        return EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversation?->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => $folder->path,
            'imap_uid_validity' => $folder->uid_validity,
            'imap_uid' => $message->imap_uid,
            'provider_seen' => $providerSeen,
            'provider_flagged' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
    }
}
