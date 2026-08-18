<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\ManageProviderEmailFolder;
use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Actions\RecordEmailRemoteOperation;
use App\Modules\Email\Actions\RetryEmailRemoteOperation;
use App\Modules\Email\Actions\RunEmailRemoteOperation;
use App\Modules\Email\Actions\UndoEmailRemoteOperation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRemoteOperationAttempt;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailRemoteOperationUndoEligibility;
use App\Modules\Email\Services\ImapClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailRemoteOperationUndoTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('email.inbox_view', 'web');
        Permission::findOrCreate('email.inbox_manage', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo(['email.inbox_view', 'email.inbox_manage']);
    }

    #[Test]
    public function seen_inverse_uses_normal_ledger_refreshes_aggregate_and_is_idempotent(): void
    {
        [$account, $conversation, $placement] = $this->mailboxContext();
        $client = $this->statefulFlagClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);

        $source = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::MARK_SEEN,
            $this->actor,
        );
        $inverse = app(UndoEmailRemoteOperation::class)->handle($source, $this->actor);
        $again = app(UndoEmailRemoteOperation::class)->handle($source->fresh(), $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $source->status);
        $this->assertNotNull($source->result_snapshot_json);
        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $inverse->status);
        $this->assertSame(PerformEmailRemoteOperation::MARK_UNSEEN, $inverse->operation_type);
        $this->assertSame($source->id, $inverse->inverse_of_email_remote_operation_id);
        $this->assertSame($inverse->id, $source->fresh()->inverseOperation->id);
        $this->assertSame($inverse->id, $again->id);
        $this->assertSame(2, $client->seenMutations);
        $this->assertFalse($placement->fresh()->provider_seen);
        $this->assertSame(1, $conversation->fresh()->provider_unread_count);
        $this->assertSame(1, $source->attemptRecords()->count());
        $this->assertSame(1, $inverse->attemptRecords()->count());
        $this->assertTrue((bool) $inverse->provider_response_json['undo_verification']['verified']);
    }

    #[Test]
    public function acknowledged_move_is_reversed_only_from_the_exact_target_identity(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $archive = $this->folder($account, 'Archive', EmailFolder::ROLE_ARCHIVE, 88);
        $client = $this->statefulMoveClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);

        $source = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::MOVE,
            $this->actor,
            $archive,
        );
        $targetId = (int) $source->result_snapshot_json['target_after']['placement_id'];
        $inverse = app(UndoEmailRemoteOperation::class)->handle($source, $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $inverse->status);
        $this->assertSame(PerformEmailRemoteOperation::MOVE, $inverse->operation_type);
        $this->assertSame($targetId, $inverse->email_mailbox_placement_id);
        $this->assertSame('Archive', $inverse->source_folder_path);
        $this->assertSame('INBOX', $inverse->target_folder_path);
        $this->assertSame(2, $client->moves);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, EmailMailboxPlacement::findOrFail($targetId)->local_state);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'account_id' => $account->id,
            'email_folder_id' => $placement->email_folder_id,
            'imap_uid_validity' => 77,
            'imap_uid' => 7702,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
    }

    #[Test]
    public function result_snapshot_is_immutable_after_success(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $client = $this->statefulFlagClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);
        $source = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::FLAG,
            $this->actor,
        );

        $this->expectException(LogicException::class);
        $source->forceFill(['result_snapshot_json' => ['rewritten' => true]])->save();
    }

    #[Test]
    public function later_work_and_stale_local_evidence_block_without_an_inverse_provider_call(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $client = $this->statefulFlagClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);
        $source = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::FLAG,
            $this->actor,
        );
        app(RecordEmailRemoteOperation::class)->pending(
            $account,
            PerformEmailRemoteOperation::MARK_SEEN,
            'later-operation:'.$placement->id,
            $this->actor,
            $placement->folder,
            $placement,
            ['source_folder_path' => $placement->folder_path],
        );

        try {
            app(UndoEmailRemoteOperation::class)->handle($source, $this->actor);
            $this->fail('Later work should block Undo.');
        } catch (ValidationException) {
            $this->assertSame(1, $client->flagMutations);
            $this->assertNull($source->fresh()->inverseOperation);
        }

        EmailRemoteOperation::query()->where('idempotency_key', 'later-operation:'.$placement->id)->delete();
        $placement->fresh()->forceFill(['sync_version' => 99])->save();

        $this->expectException(ValidationException::class);
        app(UndoEmailRemoteOperation::class)->handle($source->fresh(), $this->actor);
    }

    #[Test]
    public function revoked_access_and_ambiguous_source_block_before_provider_contact(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $client = $this->statefulFlagClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);
        $source = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::FLAG,
            $this->actor,
        );
        EmailAccountUserGrant::query()
            ->where('email_account_id', $account->id)
            ->where('user_id', $this->actor->id)
            ->update(['can_organize' => false]);

        try {
            app(UndoEmailRemoteOperation::class)->handle($source, $this->actor);
            $this->fail('Revoked access should block Undo.');
        } catch (AuthorizationException) {
            $this->assertSame(1, $client->flagMutations);
        }

        EmailAccountUserGrant::query()
            ->where('email_account_id', $account->id)
            ->where('user_id', $this->actor->id)
            ->update(['can_organize' => true]);
        $source->forceFill(['reconciled_at' => now()])->save();
        $result = app(EmailRemoteOperationUndoEligibility::class)->evaluate($source->fresh(), $this->actor);

        $this->assertFalse($result['eligible']);
        $this->assertSame('EMAIL_UNDO_SOURCE_AMBIGUOUS', $result['reason_code']);
        $this->assertSame(1, $client->flagMutations);
    }

    #[Test]
    public function provider_state_mismatch_supersedes_inverse_without_mutation(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $client = $this->statefulFlagClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);
        $source = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::FLAG,
            $this->actor,
        );
        $client->flagged = false;

        $inverse = app(UndoEmailRemoteOperation::class)->handle($source, $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_SUPERSEDED, $inverse->status);
        $this->assertSame('EMAIL_UNDO_PROVIDER_FLAGS_STALE', $inverse->status_reason_code);
        $this->assertSame(1, $client->flagMutations);
        $attempt = $inverse->attemptRecords()->sole();
        $this->assertSame('blocked', $attempt->outcome);
        $this->assertSame(EmailRemoteOperation::FAILURE_STALE, $attempt->failure_classification);
    }

    #[Test]
    public function detached_inverse_source_link_is_still_recognized_and_never_runs_as_normal_work(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $client = $this->statefulFlagClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);
        $source = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::FLAG,
            $this->actor,
        );
        $context = app(EmailRemoteOperationUndoEligibility::class)->inverseContext($source);
        $inversePlacement = $context['placement'];
        $inverse = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            $context['operation_type'],
            'mail-op:undo:'.$source->id,
            $this->actor,
            $inversePlacement->folder,
            $inversePlacement,
            $context['request'],
            $source,
        );
        DB::table('email_remote_operations')
            ->where('id', $inverse->id)
            ->update(['inverse_of_email_remote_operation_id' => null]);

        $updated = app(RunEmailRemoteOperation::class)->handle($inverse->fresh());

        $this->assertSame(EmailRemoteOperation::STATUS_SUPERSEDED, $updated->status);
        $this->assertSame('EMAIL_UNDO_SOURCE_LINK_MISSING', $updated->status_reason_code);
        $this->assertSame(1, $client->flagMutations);
        $this->assertSame(0, $updated->attemptRecords()->count());
    }

    #[Test]
    public function ambiguous_inverse_reconciles_without_replaying_provider_mutation(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $client = $this->statefulFlagClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);
        $source = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::MARK_SEEN,
            $this->actor,
        );

        $projector = new class extends EmailConversationProjector
        {
            public bool $failOnce = true;

            public function refreshForPlacement(?EmailMailboxPlacement $placement): ?EmailConversation
            {
                if ($this->failOnce) {
                    $this->failOnce = false;

                    throw new RuntimeException('local projection failed after provider acknowledgement');
                }

                return parent::refreshForPlacement($placement);
            }
        };
        $this->app->instance(EmailConversationProjector::class, $projector);

        $inverse = app(UndoEmailRemoteOperation::class)->handle($source, $this->actor);
        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $inverse->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_AMBIGUOUS, $inverse->failure_classification);
        $this->assertSame(2, $client->seenMutations);

        $reconciled = app(RetryEmailRemoteOperation::class)->handle($inverse, $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $reconciled->status);
        $this->assertSame(2, $client->seenMutations);
        $this->assertSame(2, $reconciled->attemptRecords()->count());
        $this->assertSame(
            EmailRemoteOperationAttempt::KIND_RECONCILIATION,
            $reconciled->attemptRecords()->where('attempt_number', 2)->sole()->attempt_kind,
        );
    }

    #[Test]
    public function api_hides_cross_account_undo_and_applies_for_authorized_actor(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $client = $this->statefulFlagClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);
        $source = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::FLAG,
            $this->actor,
        );
        $outsider = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $outsider->givePermissionTo(['email.inbox_view', 'email.inbox_manage']);
        Sanctum::actingAs($outsider, ['email.read', 'email.update']);

        $this->getJson(route('api.v1.email.mailbox.remote-operations.undo.show', $source))->assertNotFound();
        $this->postJson(route('api.v1.email.mailbox.remote-operations.undo.store', $source))->assertNotFound();

        EmailAccountUserGrant::create([
            'email_account_id' => $account->id,
            'user_id' => $outsider->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        $this->getJson(route('api.v1.email.mailbox.remote-operations.undo.show', $source))
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason_code', 'EMAIL_UNDO_AUTH_REQUIRED');
        $this->postJson(route('api.v1.email.mailbox.remote-operations.undo.store', $source))->assertForbidden();

        Sanctum::actingAs($this->actor, ['email.read', 'email.update']);
        $this->getJson(route('api.v1.email.mailbox.remote-operations.undo.show', $source))
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.inverse_operation_type', PerformEmailRemoteOperation::UNFLAG);

        $response = $this->postJson(route('api.v1.email.mailbox.remote-operations.undo.store', $source))
            ->assertOk()
            ->assertJsonPath('data.inverse_of_email_remote_operation_id', $source->id)
            ->assertJsonPath('data.status', EmailRemoteOperation::STATUS_SUCCEEDED);

        $inverseId = (int) $response->json('data.id');
        $this->postJson(route('api.v1.email.mailbox.remote-operations.undo.store', $source))
            ->assertOk()
            ->assertJsonPath('data.id', $inverseId);
        $this->assertSame(2, $client->flagMutations);
    }

    #[Test]
    public function mail_workspace_shows_recent_eligibility_reason_and_undo_action(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $client = $this->statefulFlagClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);
        $source = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::FLAG,
            $this->actor,
        );

        $component = Livewire::actingAs($this->actor)
            ->test(\App\Modules\Email\Livewire\Tech\MailWorkspace::class)
            ->call('toggleRemoteOperations')
            ->assertSet('remoteOperationsOpen', true)
            ->assertSee('Mailbox operations')
            ->assertSee('Verified Undo test')
            ->assertSee('provider state will be verified again')
            ->assertSee('Undo')
            ->call('undoRemoteOperation', $source->id)
            ->assertSee('Mailbox operation was undone through a verified provider inverse.');

        $this->assertSame(2, $client->flagMutations);
    }

    #[Test]
    public function undo_window_is_bounded_but_does_not_create_provider_work_after_expiry(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $client = $this->statefulFlagClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);
        $source = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::FLAG,
            $this->actor,
        );
        $this->travel(EmailRemoteOperationUndoEligibility::WINDOW_MINUTES + 1)->minutes();

        $eligibility = app(EmailRemoteOperationUndoEligibility::class)->evaluate($source->fresh(), $this->actor);

        $this->assertFalse($eligibility['eligible']);
        $this->assertSame('EMAIL_UNDO_WINDOW_EXPIRED', $eligibility['reason_code']);
        $this->assertSame(1, $client->flagMutations);
        $this->assertNull($source->fresh()->inverseOperation);
    }

    #[Test]
    public function folder_mutations_and_moves_without_exact_target_uid_never_offer_undo(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $archive = $this->folder($account, 'Archive', EmailFolder::ROLE_ARCHIVE, 88);
        $client = new class($account) extends ImapClient
        {
            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 77];
            }

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                return [
                    'ok' => true,
                    'target_folder_path' => $targetFolderPath,
                    'target_imap_uid' => null,
                    'target_uid_validity' => null,
                    'target_uid_authoritative' => false,
                ];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);
        $move = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::MOVE,
            $this->actor,
            $archive,
        );
        $moveEligibility = app(EmailRemoteOperationUndoEligibility::class)->evaluate($move, $this->actor);

        $folderOperation = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            ManageProviderEmailFolder::RENAME_FOLDER,
            'folder-operation-no-undo',
            $this->actor,
            $archive,
            null,
            ['source_folder_path' => 'Archive', 'target_folder_path' => 'Filed'],
        );
        $folderOperation->forceFill([
            'status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            'acknowledged_at' => now(),
        ])->save();
        $folderEligibility = app(EmailRemoteOperationUndoEligibility::class)->evaluate($folderOperation, $this->actor);

        $this->assertFalse($moveEligibility['eligible']);
        $this->assertSame('EMAIL_UNDO_SOURCE_NOT_SUCCEEDED', $moveEligibility['reason_code']);
        $this->assertSame(EmailRemoteOperation::FAILURE_AMBIGUOUS, $move->failure_classification);
        $this->assertFalse($folderEligibility['eligible']);
        $this->assertSame('EMAIL_UNDO_UNSUPPORTED', $folderEligibility['reason_code']);
    }

    /** @return array{EmailAccount, EmailConversation, EmailMailboxPlacement} */
    private function mailboxContext(): array
    {
        $account = EmailAccount::create([
            'address' => Str::lower(Str::random(8)).'@example.test',
            'description' => 'Undo test mailbox',
            'from_name' => 'Undo test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'mail@example.test',
            'imap_secret' => 'encrypted-placeholder',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'mail@example.test',
            'smtp_secret' => 'encrypted-placeholder',
            'smtp_auth_type' => 'password',
        ]);
        EmailAccountUserGrant::create([
            'email_account_id' => $account->id,
            'user_id' => $this->actor->id,
            'can_view' => true,
            'can_organize' => true,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        $folder = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 77);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7701,
            'message_id' => '<'.Str::uuid().'@example.test>',
            'subject' => 'Verified Undo test',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $conversation = EmailConversation::create([
            'account_id' => $account->id,
            'conversation_key' => 'test:'.Str::uuid(),
            'status' => EmailConversation::STATUS_ACTIVE,
            'subject' => $message->subject,
            'first_email_message_id' => $message->id,
            'latest_email_message_id' => $message->id,
            'message_count' => 1,
            'active_placement_count' => 1,
            'provider_unread_count' => 1,
            'first_message_at' => now(),
            'last_message_at' => now(),
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversation->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $folder->active_uid_namespace_id,
            'provider' => 'imap',
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 77,
            'imap_uid' => 7701,
            'provider_seen' => false,
            'provider_flagged' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);
        $conversation->forceFill(['latest_email_mailbox_placement_id' => $placement->id])->save();

        return [$account, $conversation, $placement];
    }

    private function folder(EmailAccount $account, string $path, string $role, int $uidValidity): EmailFolder
    {
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => $path,
            'name' => $path,
            'role' => $role,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $uidValidity,
        ]);
        $namespace = EmailFolderUidNamespace::create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => $uidValidity,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test_authoritative_move',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();

        return $folder->refresh();
    }

    private function statefulFlagClient(EmailAccount $account): ImapClient
    {
        return new class($account) extends ImapClient
        {
            public bool $seen = false;

            public bool $flagged = false;

            public int $seenMutations = 0;

            public int $flagMutations = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 77];
            }

            public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
            {
                return [
                    'exists' => true,
                    'imap_uid' => $uid,
                    'folder_path' => $folderPath,
                    'provider_seen' => $this->seen,
                    'provider_flagged' => $this->flagged,
                ];
            }

            public function setSeenByUid(int $uid, bool $seen, string $folderPath = 'INBOX'): bool
            {
                $this->seenMutations++;
                $this->seen = $seen;

                return true;
            }

            public function setFlaggedByUid(int $uid, bool $flagged, string $folderPath = 'INBOX'): bool
            {
                $this->flagMutations++;
                $this->flagged = $flagged;

                return true;
            }

            public function disconnect(): void {}
        };
    }

    private function statefulMoveClient(EmailAccount $account): ImapClient
    {
        return new class($account) extends ImapClient
        {
            public int $moves = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $folderPath === 'INBOX' ? 77 : 88];
            }

            public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
            {
                $exists = ($folderPath === 'Archive' && $uid === 8801 && $this->moves === 1)
                    || ($folderPath === 'INBOX' && $uid === 7702 && $this->moves === 2);

                return [
                    'exists' => $exists,
                    'imap_uid' => $uid,
                    'folder_path' => $folderPath,
                    'provider_seen' => false,
                    'provider_flagged' => false,
                ];
            }

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->moves++;

                return [
                    'ok' => true,
                    'target_folder_path' => $targetFolderPath,
                    'target_imap_uid' => $this->moves === 1 ? 8801 : 7702,
                    'target_uid_validity' => $this->moves === 1 ? 88 : 77,
                    'target_uid_authoritative' => true,
                ];
            }

            public function disconnect(): void {}
        };
    }
}
