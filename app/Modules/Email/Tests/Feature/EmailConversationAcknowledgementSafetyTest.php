<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\AcknowledgeEmailConversation;
use App\Modules\Email\Actions\ApplyEmailConversationAcknowledgement;
use App\Modules\Email\Actions\PreviewEmailConversationAcknowledgement;
use App\Modules\Email\Actions\RecordEmailRemoteOperation;
use App\Modules\Email\Actions\SetEmailUnreadForMe;
use App\Modules\Email\Jobs\ProcessEmailConversationAcknowledgementRun;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailConversationActionItem;
use App\Modules\Email\Models\EmailConversationActionRun;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Services\EmailConversationAcknowledgementUnavailable;
use App\Modules\Email\Services\EmailUnreadForMeResolver;
use App\Modules\Email\Services\ImapClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailConversationAcknowledgementSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $nextUid = 91000;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'email.inbox_view',
            'email.inbox_manage',
            'email.break_glass_activate',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->actor = $this->viewer(manage: true);
    }

    #[Test]
    public function rollout_defaults_off_and_the_new_migration_has_a_strict_evidence_guard(): void
    {
        $this->assertFalse(config('email_live.conversation_acknowledgement_enabled'));
        $this->assertTrue(Schema::hasTable('email_conversation_action_runs'));
        $this->assertTrue(Schema::hasTable('email_conversation_action_items'));
        $this->assertFalse(Schema::hasTable('email_mail_user_conversation_acknowledgements'));
        $this->assertTrue(Schema::hasColumn('email_conversation_action_items', 'uid_namespace_id'));
        $this->assertTrue(Schema::hasColumn('email_conversation_action_items', 'access_epoch'));
        $this->assertTrue(Schema::hasColumn('email_conversation_action_items', 'provider_binding_version'));
        $this->assertTrue(Schema::hasColumn('email_conversation_action_items', 'personal_status'));
        $this->assertTrue(Schema::hasColumn('email_conversation_action_items', 'provider_status'));

        try {
            app(PreviewEmailConversationAcknowledgement::class)->activeAccountConversation(
                $this->actor,
                new EmailAccount,
                new EmailConversation,
                'disabled-gate',
            );
            $this->fail('The default-off gate allowed an acknowledgement preview.');
        } catch (EmailConversationAcknowledgementUnavailable) {
            $this->assertSame(0, EmailConversationActionRun::query()->count());
        }

        $migration = require database_path(
            'migrations/2026_08_24_140000_create_email_conversation_acknowledgement_action_ledger.php',
        );
        $migration->down();
        $this->assertFalse(Schema::hasTable('email_conversation_action_items'));
        $this->assertFalse(Schema::hasTable('email_conversation_action_runs'));
        $migration->up();

        DB::table('email_conversation_action_runs')->insert([
            'public_id' => (string) Str::uuid(),
            'operation' => EmailConversationActionRun::OPERATION_ACKNOWLEDGE,
            'scope_kind' => EmailConversationActionRun::SCOPE_ACTIVE_ACCOUNT_CONVERSATION,
            'target_personal_unread' => false,
            'provider_seen_requested' => false,
            'status' => EmailConversationActionRun::STATUS_PREVIEWED,
            'item_cap' => 100,
            'request_fingerprint' => str_repeat('a', 64),
            'scope_fingerprint' => str_repeat('b', 64),
            'idempotency_key' => 'rollback-evidence',
            'previewed_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $migration->down();
            $this->fail('Rollback removed durable acknowledgement evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to remove', $exception->getMessage());
            $this->assertSame(1, EmailConversationActionRun::query()->count());
        } finally {
            DB::table('email_conversation_action_runs')->delete();
            $migration->down();
            $migration->up();
        }
    }

    #[Test]
    public function active_account_preview_is_read_only_and_future_or_other_account_members_stay_unread(): void
    {
        $this->enableAcknowledgement();
        $mailbox = $this->mailbox($this->actor, organize: false);
        $second = $this->addMessage($mailbox);
        $otherMailbox = $this->mailbox($this->actor, organize: false);
        $otherUser = $this->viewer();
        $this->grant($mailbox['account'], $otherUser, organize: false);
        $this->baseline($mailbox['account'], $otherUser);
        EmailMessageUserState::query()->create([
            'email_message_id' => $mailbox['message']->id,
            'user_id' => $otherUser->id,
            'access_epoch' => 1,
            'is_unread' => true,
            'opened_count' => 3,
        ]);

        $run = app(PreviewEmailConversationAcknowledgement::class)
            ->activeAccountConversation(
                $this->actor,
                $mailbox['account'],
                $mailbox['conversation'],
                'active-account-preview',
            );

        $this->assertSame(EmailConversationActionRun::STATUS_PREVIEWED, $run->status);
        $this->assertSame(1, $run->account_count);
        $this->assertSame(2, $run->item_count);
        $this->assertSame(
            [$mailbox['placement']->id, $second['placement']->id],
            $run->items->pluck('email_mailbox_placement_id')->all(),
        );
        $this->assertSame(1, EmailMessageUserState::query()->count(), 'Preview changed personal state.');
        $this->assertSame(0, EmailRemoteOperation::query()->count(), 'Preview reserved provider work.');

        $future = $this->addMessage($mailbox);
        $applied = app(ApplyEmailConversationAcknowledgement::class)->handle($run, $this->actor);

        $this->assertSame(EmailConversationActionRun::STATUS_APPLIED, $applied->status);
        foreach ([$mailbox['message'], $second['message']] as $message) {
            $this->assertDatabaseHas('email_message_user_states', [
                'email_message_id' => $message->id,
                'user_id' => $this->actor->id,
                'access_epoch' => 1,
                'is_unread' => false,
            ]);
        }
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $future['message']->id,
            'user_id' => $this->actor->id,
        ]);
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $otherMailbox['message']->id,
            'user_id' => $this->actor->id,
        ]);
        $this->assertTrue(app(EmailUnreadForMeResolver::class)->resolve($future['message'], $this->actor));
        $this->assertTrue(app(EmailUnreadForMeResolver::class)->resolve(
            $otherMailbox['message'],
            $this->actor,
        ));
        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $mailbox['message']->id,
            'user_id' => $otherUser->id,
            'is_unread' => true,
            'opened_count' => 3,
        ]);
        $this->assertSame(0, EmailRemoteOperation::query()->count());
    }

    #[Test]
    public function explicit_multi_account_preview_uses_only_selected_authorized_placements(): void
    {
        $this->enableAcknowledgement();
        $first = $this->mailbox($this->actor, organize: false);
        $unselected = $this->addMessage($first);
        $second = $this->mailbox($this->actor, organize: false);
        $foreignActor = $this->viewer();
        $foreign = $this->mailbox($foreignActor, organize: false);

        $run = app(PreviewEmailConversationAcknowledgement::class)->explicitMultiAccount(
            $this->actor,
            [$second['placement']->id, $first['placement']->id],
            'explicit-two-accounts',
        );

        $this->assertSame(EmailConversationActionRun::SCOPE_EXPLICIT_MULTI_ACCOUNT, $run->scope_kind);
        $this->assertSame(2, $run->account_count);
        $this->assertSame(
            [$first['placement']->id, $second['placement']->id],
            $run->items->pluck('email_mailbox_placement_id')->all(),
        );

        app(ApplyEmailConversationAcknowledgement::class)->handle($run, $this->actor);
        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $first['message']->id,
            'user_id' => $this->actor->id,
            'is_unread' => false,
        ]);
        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $second['message']->id,
            'user_id' => $this->actor->id,
            'is_unread' => false,
        ]);
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $unselected['message']->id,
            'user_id' => $this->actor->id,
        ]);

        $before = EmailConversationActionRun::query()->count();
        try {
            app(PreviewEmailConversationAcknowledgement::class)->explicitMultiAccount(
                $this->actor,
                [$first['placement']->id, $foreign['placement']->id],
                'explicit-inaccessible-account',
            );
            $this->fail('An inaccessible selected account entered the frozen snapshot.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('This mailbox action is not available.', $exception->getMessage());
            $this->assertSame($before, EmailConversationActionRun::query()->count());
        }
    }

    #[Test]
    public function apply_reauthorizes_the_exact_actor_account_epoch_and_placement_snapshot(): void
    {
        $this->enableAcknowledgement();
        $mailbox = $this->mailbox($this->actor, organize: true);
        $otherActor = $this->viewer(manage: true);
        $this->grant($mailbox['account'], $otherActor, organize: true);
        $this->baseline($mailbox['account'], $otherActor);
        $run = app(PreviewEmailConversationAcknowledgement::class)
            ->activeAccountConversation(
                $this->actor,
                $mailbox['account'],
                $mailbox['conversation'],
                'actor-and-placement-snapshot',
                alsoMarkProviderSeen: true,
            );

        try {
            app(ApplyEmailConversationAcknowledgement::class)->handle($run, $otherActor);
            $this->fail('A different authorized user applied another actor\'s snapshot.');
        } catch (AuthorizationException) {
            $this->assertSame(0, EmailMessageUserState::query()->count());
            $this->assertSame(0, EmailRemoteOperation::query()->count());
        }

        $mailbox['placement']->forceFill(['sync_version' => 2])->save();
        $stale = app(ApplyEmailConversationAcknowledgement::class)->handle($run, $this->actor);

        $this->assertSame(EmailConversationActionRun::STATUS_STALE, $stale->status);
        $this->assertSame(1, $stale->stale_count);
        $this->assertSame(EmailConversationActionItem::PERSONAL_STALE, $stale->items->sole()->personal_status);
        $this->assertSame(EmailConversationActionItem::PROVIDER_STALE, $stale->items->sole()->provider_status);
        $this->assertSame(0, EmailMessageUserState::query()->count());
        $this->assertSame(0, EmailRemoteOperation::query()->count());
    }

    #[Test]
    public function one_message_with_two_placements_coalesces_personal_effect_and_keeps_provider_per_placement(): void
    {
        $this->enableAcknowledgement();
        $mailbox = $this->mailbox($this->actor, organize: true);
        $secondPlacement = $this->addPlacementForMessage($mailbox);
        $run = app(PreviewEmailConversationAcknowledgement::class)
            ->activeAccountConversation(
                $this->actor,
                $mailbox['account'],
                $mailbox['conversation'],
                'coalesced-personal-provider-per-placement',
                alsoMarkProviderSeen: true,
            );
        $items = $run->items->sortBy('ordinal')->values();

        $this->assertCount(2, $items);
        $this->assertTrue($items[0]->personal_selected);
        $this->assertSame(EmailConversationActionItem::PERSONAL_PENDING, $items[0]->personal_status);
        $this->assertFalse($items[1]->personal_selected);
        $this->assertSame(EmailConversationActionItem::PERSONAL_COALESCED, $items[1]->personal_status);
        $this->assertSame('personal_effect_coalesced', $items[1]->personal_reason_code);
        $this->assertTrue($items[0]->provider_selected);
        $this->assertTrue($items[1]->provider_selected);

        try {
            $items[0]->forceFill(['personal_selected' => false])->save();
            $this->fail('Frozen personal-effect selection remained mutable.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Email conversation-action item snapshot is immutable.',
                $exception->getMessage(),
            );
            $items[0]->refresh();
        }

        $personalWrites = 0;
        DB::listen(function ($query) use (&$personalWrites): void {
            $sql = strtolower(ltrim($query->sql));
            if (str_contains($sql, 'email_message_user_states')
                && (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update'))) {
                $personalWrites++;
            }
        });

        $pending = app(ApplyEmailConversationAcknowledgement::class)->handle($run, $this->actor);
        $pendingItems = $pending->items->sortBy('ordinal')->values();

        $this->assertSame(1, $personalWrites, 'Personal unread was mutated more than once.');
        $this->assertSame(EmailConversationActionRun::STATUS_PARTIAL, $pending->status);
        $this->assertSame(1, $pending->personal_applied_count);
        $this->assertSame(2, $pending->provider_pending_count);
        $this->assertSame(0, $pending->stale_count);
        $this->assertSame(0, $pending->failed_count);
        $this->assertSame(EmailConversationActionItem::PERSONAL_APPLIED, $pendingItems[0]->personal_status);
        $this->assertSame(EmailConversationActionItem::PERSONAL_COALESCED, $pendingItems[1]->personal_status);
        $this->assertSame(2, EmailRemoteOperation::query()->count());
        $this->assertSame(
            [$mailbox['placement']->id, $secondPlacement->id],
            EmailRemoteOperation::query()
                ->orderBy('email_mailbox_placement_id')
                ->pluck('email_mailbox_placement_id')
                ->all(),
        );

        EmailRemoteOperation::query()->update([
            'status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            'acknowledged_at' => now(),
        ]);
        $completed = app(ApplyEmailConversationAcknowledgement::class)
            ->handle($pending->fresh(), $this->actor);

        $this->assertSame(EmailConversationActionRun::STATUS_APPLIED, $completed->status);
        $this->assertSame(2, $completed->provider_succeeded_count);
        $this->assertSame(0, $completed->stale_count);
        $this->assertSame(0, $completed->failed_count);
        $this->assertSame(2, EmailRemoteOperation::query()->count(), 'Readback duplicated provider work.');
    }

    #[Test]
    public function selected_personal_failure_is_not_masked_by_a_coalesced_placement(): void
    {
        $this->enableAcknowledgement();
        $mailbox = $this->mailbox($this->actor, organize: false);
        $this->addPlacementForMessage($mailbox);
        $run = app(PreviewEmailConversationAcknowledgement::class)
            ->activeAccountConversation(
                $this->actor,
                $mailbox['account'],
                $mailbox['conversation'],
                'coalesced-personal-selected-failure',
            );

        $this->mock(SetEmailUnreadForMe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')
                ->once()
                ->andThrow(new RuntimeException('sensitive personal failure'));
        });

        $result = app(ApplyEmailConversationAcknowledgement::class)->handle($run, $this->actor);
        $items = $result->items->sortBy('ordinal')->values();

        $this->assertSame(EmailConversationActionRun::STATUS_FAILED, $result->status);
        $this->assertSame(1, $result->failed_count);
        $this->assertSame(0, $result->stale_count);
        $this->assertSame(EmailConversationActionItem::PERSONAL_FAILED, $items[0]->personal_status);
        $this->assertSame('personal_apply_failed', $items[0]->personal_reason_code);
        $this->assertSame(EmailConversationActionItem::PERSONAL_COALESCED, $items[1]->personal_status);
        $this->assertSame(0, EmailMessageUserState::query()->count());
        $this->assertSame(0, EmailRemoteOperation::query()->count());
    }

    #[Test]
    public function personal_success_is_committed_when_provider_reservation_fails(): void
    {
        $this->enableAcknowledgement();
        $mailbox = $this->mailbox($this->actor, organize: true);
        $run = app(PreviewEmailConversationAcknowledgement::class)
            ->activeAccountConversation(
                $this->actor,
                $mailbox['account'],
                $mailbox['conversation'],
                'separate-provider-failure',
                alsoMarkProviderSeen: true,
            );

        $this->mock(RecordEmailRemoteOperation::class, function (MockInterface $mock): void {
            $mock->shouldReceive('pending')->once()->andThrow(new RuntimeException('sensitive provider failure'));
        });

        $result = app(ApplyEmailConversationAcknowledgement::class)->handle($run, $this->actor);
        $item = $result->items->sole();

        $this->assertSame(EmailConversationActionRun::STATUS_PARTIAL, $result->status);
        $this->assertSame(EmailConversationActionItem::PERSONAL_APPLIED, $item->personal_status);
        $this->assertSame(EmailConversationActionItem::PROVIDER_FAILED, $item->provider_status);
        $this->assertSame('provider_reservation_failed', $item->provider_reason_code);
        $this->assertStringNotContainsString('sensitive', (string) $item->provider_reason_code);
        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $mailbox['message']->id,
            'user_id' => $this->actor->id,
            'access_epoch' => 1,
            'is_unread' => false,
        ]);
        $this->assertSame(0, EmailRemoteOperation::query()->count());
        $this->assertFalse($mailbox['placement']->fresh()->provider_seen);
    }

    #[Test]
    public function provider_seen_is_authorized_and_reserved_separately_without_provider_io(): void
    {
        $this->enableAcknowledgement();
        $mailbox = $this->mailbox($this->actor, organize: true);
        $run = app(PreviewEmailConversationAcknowledgement::class)
            ->activeAccountConversation(
                $this->actor,
                $mailbox['account'],
                $mailbox['conversation'],
                'provider-seen-reservation',
                alsoMarkProviderSeen: true,
            );
        $providerClientResolutions = 0;
        $this->app->bind(ImapClient::class, function () use (&$providerClientResolutions): never {
            $providerClientResolutions++;

            throw new RuntimeException('Provider I/O is forbidden during acknowledgement apply.');
        });

        $pending = app(ApplyEmailConversationAcknowledgement::class)->handle($run, $this->actor);
        $item = $pending->items->sole();
        $operation = EmailRemoteOperation::query()->sole();

        $this->assertSame(EmailConversationActionRun::STATUS_PARTIAL, $pending->status);
        $this->assertSame(EmailConversationActionItem::PERSONAL_APPLIED, $item->personal_status);
        $this->assertSame(EmailConversationActionItem::PROVIDER_PENDING, $item->provider_status);
        $this->assertSame($operation->id, $item->email_remote_operation_id);
        $this->assertSame('mark_seen', $operation->operation_type);
        $this->assertSame($mailbox['placement']->id, $operation->email_mailbox_placement_id);
        $this->assertSame(EmailRemoteOperation::STATUS_PENDING, $operation->status);
        $this->assertFalse($mailbox['placement']->fresh()->provider_seen);
        $this->assertSame(0, $providerClientResolutions);

        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            'acknowledged_at' => now(),
        ])->save();
        $completed = app(ApplyEmailConversationAcknowledgement::class)
            ->handle($pending->fresh(), $this->actor);

        $this->assertSame(EmailConversationActionRun::STATUS_APPLIED, $completed->status);
        $this->assertSame(1, $completed->provider_succeeded_count);
        $this->assertSame(
            EmailConversationActionItem::PROVIDER_SUCCEEDED,
            $completed->items->sole()->provider_status,
        );
        $this->assertSame(1, EmailRemoteOperation::query()->count(), 'Redelivery duplicated provider work.');
        $this->assertSame(0, $providerClientResolutions);
    }

    #[Test]
    public function provider_authority_revocation_does_not_undo_valid_personal_acknowledgement(): void
    {
        $this->enableAcknowledgement();
        $mailbox = $this->mailbox($this->actor, organize: true);
        $run = app(PreviewEmailConversationAcknowledgement::class)
            ->activeAccountConversation(
                $this->actor,
                $mailbox['account'],
                $mailbox['conversation'],
                'provider-authority-revoked',
                alsoMarkProviderSeen: true,
            );
        $mailbox['grant']->forceFill(['can_organize' => false])->save();

        $result = app(ApplyEmailConversationAcknowledgement::class)->handle($run, $this->actor);
        $item = $result->items->sole();

        $this->assertSame(EmailConversationActionItem::PERSONAL_APPLIED, $item->personal_status);
        $this->assertSame(EmailConversationActionItem::PROVIDER_DENIED, $item->provider_status);
        $this->assertSame(1, $result->denied_count);
        $this->assertSame(0, EmailRemoteOperation::query()->count());
        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $mailbox['message']->id,
            'user_id' => $this->actor->id,
            'is_unread' => false,
        ]);
    }

    #[Test]
    public function break_glass_and_the_legacy_implicit_action_cannot_mutate_a_conversation(): void
    {
        $this->enableAcknowledgement();
        $owner = $this->viewer();
        $mailbox = $this->mailbox($owner, organize: true, accountOverrides: [
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $owner->id,
        ]);
        $this->actor->givePermissionTo('email.break_glass_activate');
        EmailBreakGlassAccess::query()->create([
            'email_account_id' => $mailbox['account']->id,
            'actor_id' => $this->actor->id,
            'can_view_content' => true,
            'reason' => 'Safety test only.',
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(15),
        ]);

        try {
            app(PreviewEmailConversationAcknowledgement::class)->activeAccountConversation(
                $this->actor,
                $mailbox['account'],
                $mailbox['conversation'],
                'break-glass-denied',
            );
            $this->fail('Break-glass created personal acknowledgement authority.');
        } catch (AuthorizationException) {
            $this->assertSame(0, EmailConversationActionRun::query()->count());
        }

        try {
            app(AcknowledgeEmailConversation::class)->handle($mailbox['conversation'], $owner);
            $this->fail('The historical implicit conversation action remained mutable.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'explicit frozen preview',
                $exception->errors()['conversation'][0],
            );
        }

        $this->assertSame(0, EmailMessageUserState::query()->count());
        $this->assertSame(0, EmailRemoteOperation::query()->count());
    }

    #[Test]
    public function api_applies_one_bounded_account_conversation_immediately_without_content_or_worker(): void
    {
        $this->enableAcknowledgement();
        Queue::fake();
        $mailbox = $this->mailbox($this->actor, organize: true);
        $this->addMessage($mailbox);
        Sanctum::actingAs($this->actor, ['email.read', 'email.update']);

        $preview = $this->postJson(route('api.v1.email.mailbox.conversation-actions.preview'), [
            'scope_kind' => EmailConversationActionRun::SCOPE_ACTIVE_ACCOUNT_CONVERSATION,
            'account_id' => $mailbox['account']->id,
            'conversation_id' => $mailbox['conversation']->id,
            'target_personal_unread' => false,
            'provider_seen_requested' => false,
            'idempotency_key' => 'api-preview-'.Str::uuid(),
        ])->assertCreated()
            ->assertJsonPath('data.status', EmailConversationActionRun::STATUS_PREVIEWED)
            ->assertJsonPath('data.counts.items', 2)
            ->assertJsonMissing(['subject' => 'Frozen acknowledgement fixture']);

        $run = EmailConversationActionRun::query()->where('public_id', $preview->json('data.id'))->firstOrFail();
        $this->postJson(route('api.v1.email.mailbox.conversation-actions.apply', $run->public_id))
            ->assertOk()
            ->assertJsonPath('queued', false)
            ->assertJsonPath('data.status', EmailConversationActionRun::STATUS_APPLIED)
            ->assertJsonPath('data.counts.personal_applied', 2);
        Queue::assertNotPushed(ProcessEmailConversationAcknowledgementRun::class);
        $this->getJson(route('api.v1.email.mailbox.conversation-actions.show', $run->public_id))
            ->assertOk()
            ->assertJsonPath('data.status', EmailConversationActionRun::STATUS_APPLIED)
            ->assertJsonPath('data.counts.personal_applied', 2)
            ->assertJsonMissingPath('data.items.0.email_message_id');

        $outsider = $this->viewer();
        Sanctum::actingAs($outsider, ['email.read', 'email.update']);
        $this->getJson(route('api.v1.email.mailbox.conversation-actions.show', $run->public_id))
            ->assertNotFound();
    }

    #[Test]
    public function explicit_multi_account_api_is_frozen_and_cancelled_without_effects(): void
    {
        $this->enableAcknowledgement();
        $first = $this->mailbox($this->actor, organize: false);
        $second = $this->mailbox($this->actor, organize: false);
        Sanctum::actingAs($this->actor, ['email.read', 'email.update']);

        $preview = $this->postJson(route('api.v1.email.mailbox.conversation-actions.preview'), [
            'scope_kind' => EmailConversationActionRun::SCOPE_EXPLICIT_MULTI_ACCOUNT,
            'placement_ids' => [$first['placement']->id, $second['placement']->id],
            'target_personal_unread' => true,
            'idempotency_key' => 'api-multi-'.Str::uuid(),
        ])->assertCreated()
            ->assertJsonPath('data.counts.accounts', 2)
            ->assertJsonCount(2, 'data.items');

        $this->postJson(route(
            'api.v1.email.mailbox.conversation-actions.cancel',
            $preview->json('data.id'),
        ))->assertOk()->assertJsonPath('data.status', EmailConversationActionRun::STATUS_CANCELLED);
        $this->assertSame(0, EmailMessageUserState::query()->count());
        $this->assertSame(0, EmailRemoteOperation::query()->count());
    }

    #[Test]
    public function mail_workspace_exposes_explicit_conversation_preview_controls_only_when_enabled(): void
    {
        $this->enableAcknowledgement();
        $mailbox = $this->mailbox($this->actor, organize: true);

        $this->actingAs($this->actor)
            ->get(route('tech.mail.index', ['message' => $mailbox['message']->id]))
            ->assertOk()
            ->assertSee('Mark conversation read for me')
            ->assertSee('Mark conversation unread for me')
            ->assertDontSee('Confirm and apply');
    }

    private function enableAcknowledgement(): void
    {
        config()->set('email_live.conversation_acknowledgement_enabled', true);
        config()->set('email_live.enabled', false);
    }

    private function viewer(bool $manage = false): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $permissions = ['email.inbox_view'];
        if ($manage) {
            $permissions[] = 'email.inbox_manage';
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $accountOverrides
     * @return array{account: EmailAccount, grant: EmailAccountUserGrant, folder: EmailFolder,
     *     namespace: EmailFolderUidNamespace, conversation: EmailConversation,
     *     message: EmailMessage, placement: EmailMailboxPlacement}
     */
    private function mailbox(
        User $viewer,
        bool $organize,
        array $accountOverrides = [],
    ): array {
        $address = Str::lower(Str::random(10)).'@example.test';
        $account = EmailAccount::query()->create(array_merge([
            'address' => $address,
            'description' => 'Conversation acknowledgement safety test',
            'from_name' => 'Acknowledgement Test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'owner_id' => null,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'acknowledgement-test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'acknowledgement-test-secret',
            'smtp_auth_type' => 'password',
            'provider_binding_version' => 1,
        ], $accountOverrides));
        $grant = $this->grant($account, $viewer, $organize);
        $this->baseline($account, $viewer);
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 991,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => 991,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'acknowledgement_test',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();
        $message = $this->message($account);
        $conversation = EmailConversation::query()->create([
            'account_id' => $account->id,
            'conversation_key' => 'ack:'.Str::uuid(),
            'status' => EmailConversation::STATUS_ACTIVE,
            'first_email_message_id' => $message->id,
            'latest_email_message_id' => $message->id,
            'message_count' => 1,
            'active_placement_count' => 1,
            'provider_unread_count' => 1,
            'first_message_at' => now(),
            'last_message_at' => now(),
        ]);
        $placement = $this->placement($account, $folder, $namespace, $conversation, $message);
        $conversation->forceFill(['latest_email_mailbox_placement_id' => $placement->id])->save();

        return compact('account', 'grant', 'folder', 'namespace', 'conversation', 'message', 'placement');
    }

    /**
     * @param array{account: EmailAccount, folder: EmailFolder, namespace: EmailFolderUidNamespace,
     *     conversation: EmailConversation} $mailbox
     * @return array{message: EmailMessage, placement: EmailMailboxPlacement}
     */
    private function addMessage(array $mailbox): array
    {
        $message = $this->message($mailbox['account']);
        $placement = $this->placement(
            $mailbox['account'],
            $mailbox['folder'],
            $mailbox['namespace'],
            $mailbox['conversation'],
            $message,
        );
        $mailbox['conversation']->forceFill([
            'latest_email_message_id' => $message->id,
            'latest_email_mailbox_placement_id' => $placement->id,
            'message_count' => ((int) $mailbox['conversation']->message_count) + 1,
            'active_placement_count' => ((int) $mailbox['conversation']->active_placement_count) + 1,
            'provider_unread_count' => ((int) $mailbox['conversation']->provider_unread_count) + 1,
            'last_message_at' => now(),
        ])->save();

        return compact('message', 'placement');
    }

    /**
     * Add a second active provider placement for the same EmailMessage. This is
     * the canonical-placement case where personal state is message-scoped but
     * provider Seen remains placement-scoped.
     *
     * @param array{account: EmailAccount, conversation: EmailConversation,
     *     message: EmailMessage} $mailbox
     */
    private function addPlacementForMessage(array $mailbox): EmailMailboxPlacement
    {
        $uidValidity = ++$this->nextUid;
        $path = 'Archive-'.$uidValidity;
        $folder = EmailFolder::query()->create([
            'account_id' => $mailbox['account']->id,
            'path' => $path,
            'name' => $path,
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $uidValidity,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $mailbox['account']->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => $uidValidity,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'acknowledgement_test_duplicate_placement',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $mailbox['message']->id,
            'email_conversation_id' => $mailbox['conversation']->id,
            'account_id' => $mailbox['account']->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'provider' => 'imap',
            'folder_path' => $path,
            'imap_uid_validity' => $uidValidity,
            'imap_uid' => ++$this->nextUid,
            'provider_seen' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);
        $mailbox['conversation']->forceFill([
            'active_placement_count' => ((int) $mailbox['conversation']->active_placement_count) + 1,
            'provider_unread_count' => ((int) $mailbox['conversation']->provider_unread_count) + 1,
        ])->save();

        return $placement;
    }

    private function message(EmailAccount $account): EmailMessage
    {
        $uid = ++$this->nextUid;

        return EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid_validity' => 991,
            'imap_uid' => $uid,
            'message_id' => "<ack-{$uid}@example.test>",
            'subject' => 'Frozen acknowledgement fixture',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
    }

    private function placement(
        EmailAccount $account,
        EmailFolder $folder,
        EmailFolderUidNamespace $namespace,
        EmailConversation $conversation,
        EmailMessage $message,
    ): EmailMailboxPlacement {
        return EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversation->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'provider' => 'imap',
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 991,
            'imap_uid' => $message->imap_uid,
            'provider_seen' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);
    }

    private function grant(
        EmailAccount $account,
        User $user,
        bool $organize,
    ): EmailAccountUserGrant {
        return EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => $organize,
            'can_send' => false,
            'granted_by' => $this->actor->id,
            'granted_at' => now(),
        ]);
    }

    private function baseline(EmailAccount $account, User $user): EmailAccountUserReadBaseline
    {
        return EmailAccountUserReadBaseline::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'access_epoch' => 1,
            'baseline_message_id' => 0,
            'ordinary_view_entitled' => true,
            'source' => 'direct_grant',
            'recorded_by' => $this->actor->id,
            'recorded_at' => now()->subMinute(),
            'entitlement_changed_at' => now()->subMinute(),
        ]);
    }
}
