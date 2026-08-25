<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\BuildEmailSmartInboxRulePrefill;
use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Services\EmailRulePublisher;
use App\Modules\Email\Services\ImapClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailDeterministicRuleUndoApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['email.inbox_view', 'email.inbox_manage', 'email.rule_manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo([
            'email.inbox_view',
            'email.inbox_manage',
            'email.rule_manage',
        ]);
    }

    #[Test]
    public function rule_undo_api_uses_verified_provider_inverse_and_keeps_execution_immutable(): void
    {
        [$account, $sourcePlacement, $archive] = $this->mailboxContext($this->actor);
        $client = $this->moveClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);
        $source = app(PerformEmailRemoteOperation::class)->handle(
            $sourcePlacement,
            PerformEmailRemoteOperation::MOVE,
            $this->actor,
            $archive,
        );
        $attempt = $this->executionAttempt($account, $sourcePlacement, [[
            'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
            'target_folder_id' => $archive->id,
        ]], [[
            'position' => 0,
            'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
            'status' => EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
            'remote_operation_id' => $source->id,
            'remote_operation_status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            'reason' => 'sensitive-provider.example.test:993',
        ]]);
        $immutable = [
            'status' => $attempt->status,
            'action_results_json' => $attempt->action_results_json,
            'finished_at' => $attempt->finished_at?->toJSON(),
        ];

        Sanctum::actingAs($this->actor, ['email.rules.read', 'email.rules.execute']);

        $this->getJson(route('api.v1.email.rules.executions.show', $attempt))
            ->assertOk()
            ->assertJsonPath('data.id', $attempt->id)
            ->assertJsonPath('data.actions.0.remote_operation_id', $source->id)
            ->assertJsonPath('data.actions.0.reason_code', 'email_rule_action_failed')
            ->assertJsonPath('data.undo.eligible', true)
            ->assertJsonPath('data.undo.reason_code', 'EMAIL_RULE_UNDO_AVAILABLE')
            ->assertJsonMissingPath('data.actions.0.before');

        $response = $this->postJson(route('api.v1.email.rules.executions.undo.store', $attempt))
            ->assertOk()
            ->assertJsonPath('data.execution_attempt_id', $attempt->id)
            ->assertJsonPath('data.source_remote_operation_id', $source->id)
            ->assertJsonPath('data.status', EmailRemoteOperation::STATUS_SUCCEEDED);

        $inverseId = (int) $response->json('data.inverse_remote_operation_id');
        $this->postJson(route('api.v1.email.rules.executions.undo.store', $attempt))
            ->assertOk()
            ->assertJsonPath('data.inverse_remote_operation_id', $inverseId);

        $this->assertSame(2, $client->moves);
        $this->assertSame(2, EmailRemoteOperation::query()->count());
        $this->assertSame($source->id, EmailRemoteOperation::findOrFail($inverseId)->inverse_of_email_remote_operation_id);
        $freshAttempt = $attempt->fresh();
        $this->assertSame($immutable, [
            'status' => $freshAttempt->status,
            'action_results_json' => $freshAttempt->action_results_json,
            'finished_at' => $freshAttempt->finished_at?->toJSON(),
        ]);

        $this->expectException(LogicException::class);
        $attempt->fresh()->forceFill(['status' => 'reverted'])->save();
    }

    #[Test]
    public function mixed_or_local_only_rule_effects_are_never_partially_or_locally_undone(): void
    {
        [$account, $sourcePlacement, $archive] = $this->mailboxContext($this->actor);
        $client = $this->moveClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);
        $source = app(PerformEmailRemoteOperation::class)->handle(
            $sourcePlacement,
            PerformEmailRemoteOperation::MOVE,
            $this->actor,
            $archive,
        );
        $targetMismatch = $this->executionAttempt($account, $sourcePlacement, [[
            'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
            'target_folder_id' => $source->email_folder_id,
        ]], [[
            'position' => 0,
            'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
            'status' => EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
            'remote_operation_id' => $source->id,
            'remote_operation_status' => EmailRemoteOperation::STATUS_SUCCEEDED,
        ]]);
        $incompleteAttempt = $this->executionAttempt($account, $sourcePlacement, [
            [
                'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
                'target_folder_id' => $archive->id,
            ],
            ['type' => 'tag', 'value' => 'missing-result-evidence'],
        ], [[
            'position' => 0,
            'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
            'status' => EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
            'remote_operation_id' => $source->id,
            'remote_operation_status' => EmailRemoteOperation::STATUS_SUCCEEDED,
        ]]);
        $inconsistentAttempt = $this->executionAttempt($account, $sourcePlacement, [
            [
                'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
                'target_folder_id' => $archive->id,
            ],
            ['type' => 'tag', 'value' => 'failed-effect'],
        ], [
            [
                'position' => 0,
                'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
                'status' => EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
                'remote_operation_id' => $source->id,
                'remote_operation_status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            ],
            [
                'position' => 1,
                'type' => 'tag',
                'status' => EmailRuleExecutionAttempt::STATUS_FAILED,
                'reason' => 'email_rule_action_failed',
            ],
        ]);
        $mixedAttempt = $this->executionAttempt($account, $sourcePlacement, [
            [
                'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
                'target_folder_id' => $archive->id,
            ],
            ['type' => 'tag', 'value' => 'already-present'],
        ], [
            [
                'position' => 0,
                'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
                'status' => EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
                'remote_operation_id' => $source->id,
                'remote_operation_status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            ],
            [
                'position' => 1,
                'type' => 'tag',
                'status' => EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
            ],
        ]);

        Sanctum::actingAs($this->actor, ['email.rules.read', 'email.rules.execute']);

        $this->getJson(route('api.v1.email.rules.executions.undo.show', $targetMismatch))
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason_code', 'EMAIL_RULE_UNDO_OPERATION_MISMATCH');
        $this->postJson(route('api.v1.email.rules.executions.undo.store', $targetMismatch))
            ->assertUnprocessable();

        $this->getJson(route('api.v1.email.rules.executions.undo.show', $incompleteAttempt))
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason_code', 'EMAIL_RULE_UNDO_EXECUTION_EVIDENCE_INVALID');
        $this->postJson(route('api.v1.email.rules.executions.undo.store', $incompleteAttempt))
            ->assertUnprocessable();

        $this->getJson(route('api.v1.email.rules.executions.undo.show', $inconsistentAttempt))
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason_code', 'EMAIL_RULE_UNDO_EXECUTION_EVIDENCE_INVALID');
        $this->postJson(route('api.v1.email.rules.executions.undo.store', $inconsistentAttempt))
            ->assertUnprocessable();

        $this->getJson(route('api.v1.email.rules.executions.undo.show', $mixedAttempt))
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason_code', 'EMAIL_RULE_UNDO_MIXED_EFFECTS');
        $this->postJson(route('api.v1.email.rules.executions.undo.store', $mixedAttempt))
            ->assertUnprocessable();

        $localOnly = $this->executionAttempt($account, $sourcePlacement, [[
            'type' => 'archive',
        ]], [[
            'position' => 0,
            'type' => 'archive',
            'status' => EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
            'before' => ['folder_id' => $sourcePlacement->email_folder_id],
        ]]);
        $folderId = $sourcePlacement->fresh()->email_folder_id;

        $this->getJson(route('api.v1.email.rules.executions.undo.show', $localOnly))
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason_code', 'EMAIL_RULE_UNDO_ACTION_NOT_REVERSIBLE');
        $this->postJson(route('api.v1.email.rules.executions.undo.store', $localOnly))
            ->assertUnprocessable();

        $this->assertSame(1, $client->moves);
        $this->assertSame(1, EmailRemoteOperation::query()->count());
        $this->assertNull($source->fresh()->inverseOperation);
        $this->assertSame($folderId, $sourcePlacement->fresh()->email_folder_id);
        $this->assertSame(EmailRuleExecutionAttempt::STATUS_SUCCEEDED, $localOnly->fresh()->status);
    }

    #[Test]
    public function rule_undo_api_rechecks_token_user_and_mailbox_scope_without_leaking_attempts(): void
    {
        [$account, $sourcePlacement, $archive] = $this->mailboxContext($this->actor);
        $client = $this->moveClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);
        $source = app(PerformEmailRemoteOperation::class)->handle(
            $sourcePlacement,
            PerformEmailRemoteOperation::MOVE,
            $this->actor,
            $archive,
        );
        $attempt = $this->executionAttempt($account, $sourcePlacement, [[
            'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
            'target_folder_id' => $archive->id,
        ]], [[
            'position' => 0,
            'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
            'status' => EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
            'remote_operation_id' => $source->id,
            'remote_operation_status' => EmailRemoteOperation::STATUS_SUCCEEDED,
        ]]);
        $outsider = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $outsider->givePermissionTo([
            'email.inbox_view',
            'email.inbox_manage',
            'email.rule_manage',
        ]);

        Sanctum::actingAs($outsider, ['email.rules.read', 'email.rules.execute']);
        $this->getJson(route('api.v1.email.rules.executions.undo.show', $attempt))->assertNotFound();
        $this->postJson(route('api.v1.email.rules.executions.undo.store', $attempt))->assertNotFound();

        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $outsider->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        $this->getJson(route('api.v1.email.rules.executions.undo.show', $attempt))
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason_code', 'EMAIL_UNDO_AUTH_REQUIRED');
        $this->postJson(route('api.v1.email.rules.executions.undo.store', $attempt))->assertForbidden();

        Sanctum::actingAs($this->actor, ['email.rules.read']);
        $this->postJson(route('api.v1.email.rules.executions.undo.store', $attempt))->assertForbidden();

        $this->assertSame(1, $client->moves);
        $this->assertSame(1, EmailRemoteOperation::query()->count());
        $this->assertNull($source->fresh()->inverseOperation);
    }

    #[Test]
    public function stale_provider_evidence_fails_closed_before_rule_undo_creates_an_inverse(): void
    {
        [$account, $sourcePlacement, $archive] = $this->mailboxContext($this->actor);
        $client = $this->moveClient($account);
        $this->app->bind(ImapClient::class, fn () => $client);
        $source = app(PerformEmailRemoteOperation::class)->handle(
            $sourcePlacement,
            PerformEmailRemoteOperation::MOVE,
            $this->actor,
            $archive,
        );
        $attempt = $this->executionAttempt($account, $sourcePlacement, [[
            'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
            'target_folder_id' => $archive->id,
        ]], [[
            'position' => 0,
            'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
            'status' => EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
            'remote_operation_id' => $source->id,
            'remote_operation_status' => EmailRemoteOperation::STATUS_SUCCEEDED,
        ]]);
        EmailMailboxPlacement::query()
            ->whereKey((int) $source->result_snapshot_json['target_after']['placement_id'])
            ->increment('sync_version');

        Sanctum::actingAs($this->actor, ['email.rules.read', 'email.rules.execute']);

        $this->getJson(route('api.v1.email.rules.executions.undo.show', $attempt))
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason_code', 'EMAIL_UNDO_TARGET_PLACEMENT_STALE');
        $this->postJson(route('api.v1.email.rules.executions.undo.store', $attempt))
            ->assertUnprocessable();

        $this->assertSame(1, $client->moves);
        $this->assertSame(1, EmailRemoteOperation::query()->count());
        $this->assertNull($source->fresh()->inverseOperation);
    }

    #[Test]
    public function admin_rule_undo_hides_account_scope_and_reports_only_safe_failure_text(): void
    {
        [$account, $sourcePlacement] = $this->mailboxContext($this->actor);
        $attempt = $this->executionAttempt($account, $sourcePlacement, [[
            'type' => 'archive',
        ]], [[
            'position' => 0,
            'type' => 'archive',
            'status' => EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
            'before' => ['folder_id' => $sourcePlacement->email_folder_id],
            'reason' => 'sensitive-provider.example.test:993',
        ]]);

        $this->actingAs($this->actor)
            ->post(route('tech.admin.settings.email.rules.undo', $attempt))
            ->assertRedirect()
            ->assertSessionHas('error', 'This rule action has no approved verified inverse.');

        $outsider = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $outsider->givePermissionTo(['email.inbox_view', 'email.inbox_manage', 'email.rule_manage']);

        $this->actingAs($outsider)
            ->post(route('tech.admin.settings.email.rules.undo', $attempt))
            ->assertNotFound();

        $this->assertSame(EmailRuleExecutionAttempt::STATUS_SUCCEEDED, $attempt->fresh()->status);
        $this->assertSame($sourcePlacement->email_folder_id, $sourcePlacement->fresh()->email_folder_id);
    }

    /** @return array{EmailAccount, EmailMailboxPlacement, EmailFolder} */
    private function mailboxContext(User $user): array
    {
        $account = EmailAccount::query()->create([
            'address' => Str::lower(Str::random(8)).'@example.test',
            'description' => 'Rule Undo test mailbox',
            'from_name' => 'Rule Undo test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'defaults_for' => [],
            'ticket_ingress_enabled' => true,
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
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => true,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        $inbox = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 77);
        $archive = $this->folder($account, 'Archive', EmailFolder::ROLE_ARCHIVE, 88);
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7701,
            'message_id' => '<'.Str::uuid().'@example.test>',
            'subject' => 'Deterministic rule Undo test',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $conversation = EmailConversation::query()->create([
            'account_id' => $account->id,
            'conversation_key' => 'rule-undo:'.Str::uuid(),
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
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversation->id,
            'account_id' => $account->id,
            'email_folder_id' => $inbox->id,
            'uid_namespace_id' => $inbox->active_uid_namespace_id,
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

        return [$account, $placement, $archive];
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @param  array<int, array<string, mixed>>  $results
     */
    private function executionAttempt(
        EmailAccount $account,
        EmailMailboxPlacement $placement,
        array $actions,
        array $results,
    ): EmailRuleExecutionAttempt {
        $rule = EmailRule::query()->create([
            'name' => 'Rule Undo '.Str::random(8),
            'description' => 'Focused deterministic rule Undo test.',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_NORMAL,
            'rule_kind' => EmailRule::KIND_ADMIN,
            'weight' => 10,
            'is_active' => true,
            'lifecycle_status' => EmailRule::LIFECYCLE_PUBLISHED,
            'stop_processing' => false,
            'conditions_json' => [[
                'field' => 'subject',
                'operator' => 'contains',
                'value' => 'Undo',
            ]],
            'actions_json' => $actions,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $rule->accounts()->sync([$account->id]);
        $version = app(EmailRulePublisher::class)->publish($rule, $this->actor);

        return EmailRuleExecutionAttempt::query()->create([
            'email_rule_id' => $rule->id,
            'email_rule_version_id' => $version->id,
            'email_message_id' => $placement->email_message_id,
            'email_mailbox_placement_id' => $placement->id,
            'routing_phase' => EmailRule::ROUTING_PHASE_NORMAL,
            'status' => EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
            'idempotency_key' => hash('sha256', 'rule-undo:'.Str::uuid()),
            'matched' => true,
            'stop_processing' => false,
            'conditions_json' => $rule->conditions_json,
            'actions_json' => $actions,
            'action_results_json' => $results,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);
    }

    private function folder(EmailAccount $account, string $path, string $role, int $uidValidity): EmailFolder
    {
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => $path,
            'name' => $path,
            'role' => $role,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $uidValidity,
        ]);
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => $uidValidity,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test_rule_undo',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();

        return $folder->refresh();
    }

    private function moveClient(EmailAccount $account): ImapClient
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
