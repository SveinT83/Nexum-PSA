<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\CancelEmailRemoteOperation;
use App\Modules\Email\Actions\ManageProviderEmailFolder;
use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Actions\RecordEmailRemoteOperation;
use App\Modules\Email\Actions\RunDueEmailRemoteOperations;
use App\Modules\Email\Actions\RunEmailRemoteOperation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRemoteOperationAttempt;
use App\Modules\Email\Services\EmailFolderProjector;
use App\Modules\Email\Services\EmailProviderReadException;
use App\Modules\Email\Services\EmailProviderRemoteOperationObserver;
use App\Modules\Email\Services\EmailRemoteOperationReconciler;
use App\Modules\Email\Services\ImapClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Webklex\PHPIMAP\Connection\Protocols\Response;

class EmailRemoteOperationRecoveryTest extends TestCase
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
    public function provider_missing_and_an_unresolved_owner_reject_new_manual_operations_before_client_resolution(): void
    {
        [$missingAccount, , $missingPlacement] = $this->mailboxContext();
        $missingPlacement->forceFill(['provider_missing_at' => now()])->save();
        $clientResolutions = 0;
        $this->app->bind(ImapClient::class, function () use (&$clientResolutions): never {
            $clientResolutions++;

            throw new RuntimeException('Provider client resolution is forbidden for a rejected operation.');
        });

        foreach ([
            fn () => app(PerformEmailRemoteOperation::class)->handle(
                $missingPlacement->fresh(),
                PerformEmailRemoteOperation::FLAG,
                $this->actor,
            ),
            fn () => app(RecordEmailRemoteOperation::class)->pending(
                $missingAccount,
                PerformEmailRemoteOperation::FLAG,
                'missing-direct-reservation:'.$missingPlacement->id,
                $this->actor,
                $missingPlacement->folder,
                $missingPlacement->fresh(),
                [
                    'source_folder_path' => 'INBOX',
                    'placement_sync_version' => 1,
                    'placement_imap_uid' => 7701,
                    'placement_uid_validity' => 77,
                ],
            ),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A provider-missing placement must reject new provider work.');
            } catch (ValidationException $exception) {
                $this->assertSame(
                    'This mailbox placement is no longer available at the provider.',
                    $exception->errors()['placement'][0],
                );
            }
        }

        $this->assertSame(0, EmailRemoteOperation::query()->count());

        [$conflictAccount, , $conflictPlacement] = $this->mailboxContext();
        $owner = $this->pendingSeen($conflictAccount, $conflictPlacement, 'absence-owner');

        try {
            app(PerformEmailRemoteOperation::class)->handle(
                $conflictPlacement->fresh(),
                PerformEmailRemoteOperation::FLAG,
                $this->actor,
            );
            $this->fail('A competing unresolved placement operation must retain ownership.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Another provider mailbox operation is still unresolved for this placement.',
                $exception->errors()['placement'][0],
            );
        }

        $this->assertSame(1, EmailRemoteOperation::query()->count());
        $this->assertSame(EmailRemoteOperation::STATUS_PENDING, $owner->fresh()->status);
        $this->assertSame(0, $clientResolutions);
    }

    #[Test]
    public function claim_time_guard_blocks_legacy_conflicts_and_provider_absence_without_resolving_a_client(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $owner = $this->pendingSeen($account, $placement, 'oldest-unresolved-owner');
        $legacyConflict = $owner->replicate();
        $legacyConflict->forceFill([
            'operation_type' => PerformEmailRemoteOperation::FLAG,
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'idempotency_key' => 'legacy-conflicting-row:'.$placement->id,
            'failure_classification' => null,
            'reconciliation_required_at' => null,
            'reconciled_at' => null,
        ])->save();
        $client = new class($account) extends ImapClient
        {
            public int $connections = 0;

            public int $mutations = 0;

            public function connect(): void
            {
                $this->connections++;
            }

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 77];
            }

            public function setSeenByUid(int $uid, bool $seen, string $folderPath = 'INBOX'): bool
            {
                $this->mutations++;

                return true;
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $blockedConflict = app(RunEmailRemoteOperation::class)->handle($legacyConflict);

        $this->assertSame(EmailRemoteOperation::STATUS_SUPERSEDED, $blockedConflict->status);
        $this->assertSame('REMOTE_OPERATION_PLACEMENT_CONFLICT', $blockedConflict->status_reason_code);
        $this->assertSame(EmailRemoteOperation::STATUS_PENDING, $owner->fresh()->status);
        $this->assertSame(0, $client->connections);

        $owner = app(RunEmailRemoteOperation::class)->handle($owner);
        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $owner->status);
        $this->assertSame(1, $client->connections);
        $this->assertSame(1, $client->mutations);
        $placement->forceFill(['provider_missing_at' => now()])->save();
        $legacyMissing = $owner->replicate();
        $legacyMissing->forceFill([
            'operation_type' => PerformEmailRemoteOperation::UNFLAG,
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'idempotency_key' => 'legacy-provider-missing-row:'.$placement->id,
            'failure_classification' => null,
            'reconciliation_required_at' => null,
            'reconciled_at' => null,
        ])->save();

        $blockedMissing = app(RunEmailRemoteOperation::class)->handle($legacyMissing);

        $this->assertSame(EmailRemoteOperation::STATUS_SUPERSEDED, $blockedMissing->status);
        $this->assertSame('REMOTE_OPERATION_PROVIDER_MISSING', $blockedMissing->status_reason_code);

        [, , $foreignPlacement] = $this->mailboxContext();
        $relationshipDrift = $owner->replicate();
        $relationshipDrift->forceFill([
            'email_mailbox_placement_id' => $foreignPlacement->id,
            'operation_type' => PerformEmailRemoteOperation::FLAG,
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'idempotency_key' => 'legacy-relationship-drift-row:'.$placement->id,
            'failure_classification' => null,
            'reconciliation_required_at' => null,
            'reconciled_at' => null,
        ])->save();

        $blockedRelationship = app(RunEmailRemoteOperation::class)->handle($relationshipDrift);

        $this->assertSame(EmailRemoteOperation::STATUS_SUPERSEDED, $blockedRelationship->status);
        $this->assertSame('REMOTE_OPERATION_RELATION_STALE', $blockedRelationship->status_reason_code);
        $this->assertSame(1, $client->connections);
        $this->assertSame(1, $client->mutations);
        $this->assertSame(1, EmailRemoteOperationAttempt::query()->count());
    }

    #[Test]
    public function stale_serialized_scope_cannot_execute_an_operation_repointed_to_another_account(): void
    {
        [$sourceAccount, , $sourcePlacement] = $this->mailboxContext();
        $operation = $this->pendingSeen($sourceAccount, $sourcePlacement, 'stale-cross-account-scope');
        [, , $targetPlacement] = $this->mailboxContext();
        $sourceBefore = $sourcePlacement->fresh()->getAttributes();
        $targetBefore = $targetPlacement->fresh()->getAttributes();

        DB::table('email_remote_operations')
            ->where('id', $operation->id)
            ->update([
                'account_id' => $targetPlacement->account_id,
                'email_folder_id' => $targetPlacement->email_folder_id,
                'email_mailbox_placement_id' => $targetPlacement->id,
                'provider_binding_version' => $targetPlacement->account->provider_binding_version,
                'source_folder_path' => $targetPlacement->folder_path,
                'expected_placement_sync_version' => $targetPlacement->sync_version,
                'expected_provider_uid' => $targetPlacement->imap_uid,
                'expected_uid_validity' => $targetPlacement->imap_uid_validity,
            ]);
        $clientResolutions = 0;
        $this->app->bind(ImapClient::class, function () use (&$clientResolutions): never {
            $clientResolutions++;

            throw new RuntimeException('A drifted cross-account operation must not resolve a provider client.');
        });

        $updated = app(RunEmailRemoteOperation::class)->handle($operation);

        $this->assertSame(EmailRemoteOperation::STATUS_SUPERSEDED, $updated->status);
        $this->assertSame('REMOTE_OPERATION_RELATION_STALE', $updated->status_reason_code);
        $this->assertSame(0, $clientResolutions);
        $this->assertSame(0, EmailRemoteOperationAttempt::query()->count());
        $this->assertSame($sourceBefore, $sourcePlacement->fresh()->getAttributes());
        $this->assertSame($targetBefore, $targetPlacement->fresh()->getAttributes());
    }

    #[Test]
    public function ambiguous_provider_missing_owner_may_reconcile_not_applied_but_never_mutates(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement, 'ambiguous-provider-missing-owner');
        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'attempts' => 1,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
            'reconciliation_required_at' => now(),
        ])->save();
        $placement->forceFill(['provider_missing_at' => now()])->save();
        $client = new class($account) extends ImapClient
        {
            public int $reads = 0;

            public int $mutations = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 77];
            }

            public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
            {
                $this->reads++;

                return [
                    'exists' => true,
                    'imap_uid' => $uid,
                    'folder_path' => $folderPath,
                    'provider_seen' => false,
                    'provider_flagged' => false,
                ];
            }

            public function setSeenByUid(int $uid, bool $seen, string $folderPath = 'INBOX'): bool
            {
                $this->mutations++;

                return true;
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation, 'manual', $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_SUPERSEDED, $updated->status);
        $this->assertSame('REMOTE_OPERATION_PROVIDER_MISSING', $updated->status_reason_code);
        $this->assertSame(1, $client->reads);
        $this->assertSame(0, $client->mutations);
        $this->assertFalse($placement->fresh()->provider_seen);
        $this->assertNotNull($placement->fresh()->provider_missing_at);
        $attempt = $updated->attemptRecords()->sole();
        $this->assertSame(EmailRemoteOperationAttempt::KIND_RECONCILIATION, $attempt->attempt_kind);
        $this->assertSame(EmailRemoteOperationReconciler::NOT_APPLIED, $attempt->outcome);
    }

    #[Test]
    public function exact_nonmissing_idempotent_operation_retries_the_same_effective_row(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $client = new class($account) extends ImapClient
        {
            public int $connections = 0;

            public int $mutations = 0;

            public function connect(): void
            {
                $this->connections++;
                if ($this->connections === 1) {
                    throw new RuntimeException('Transient preflight failure.');
                }
            }

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 77];
            }

            public function setSeenByUid(int $uid, bool $seen, string $folderPath = 'INBOX'): bool
            {
                $this->mutations++;

                return true;
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $first = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::MARK_SEEN,
            $this->actor,
        );
        $retried = app(PerformEmailRemoteOperation::class)->handle(
            $placement->fresh(),
            PerformEmailRemoteOperation::MARK_SEEN,
            $this->actor,
        );

        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $first->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_TRANSIENT, $first->failure_classification);
        $this->assertSame($first->id, $retried->id);
        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $retried->status);
        $this->assertSame(2, $client->connections);
        $this->assertSame(1, $client->mutations);
        $this->assertSame(1, EmailRemoteOperation::query()->count());
        $this->assertTrue($placement->fresh()->provider_seen);
    }

    #[Test]
    public function stale_placement_evidence_supersedes_without_touching_provider(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement);
        $placement->forceFill(['sync_version' => 2])->save();

        $client = new class($account) extends ImapClient
        {
            public int $connections = 0;

            public function connect(): void
            {
                $this->connections++;
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation);

        $this->assertSame(EmailRemoteOperation::STATUS_SUPERSEDED, $updated->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_STALE, $updated->failure_classification);
        $this->assertSame('REMOTE_OPERATION_PLACEMENT_STALE', $updated->status_reason_code);
        $this->assertSame(0, $client->connections);
        $this->assertSame(0, EmailRemoteOperationAttempt::query()->count());
    }

    #[Test]
    public function connection_failures_are_audited_without_consuming_mutation_budget_or_exposing_provider_details(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement);

        $client = new class($account) extends ImapClient
        {
            public function connect(): void
            {
                throw new RuntimeException('no headers found password=hunter2');
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $first = app(RunEmailRemoteOperation::class)->handle($operation);
        $this->assertSame(EmailRemoteOperation::FAILURE_TRANSIENT, $first->failure_classification);
        $this->assertNotNull($first->next_attempt_at);
        $this->assertSame(0, $first->attempts);
        $this->assertSame(0, $first->providerAttemptCount());
        $this->assertTrue($first->canBeRetried());
        $this->assertSame('The provider mailbox was unavailable before the operation could start.', $first->error_message);

        $attempt = $first->attemptRecords()->sole();
        $this->assertSame(EmailRemoteOperationAttempt::STATUS_FINISHED, $attempt->status);
        $this->assertSame(EmailRemoteOperationAttempt::KIND_PREFLIGHT, $attempt->attempt_kind);
        $this->assertStringNotContainsString('hunter2', (string) $attempt->reason_message);
        $this->assertStringNotContainsString('no headers found', (string) $attempt->reason_message);
        $this->assertSame('RuntimeException', data_get($attempt->error_json, 'type'));
        $this->assertSame('0', data_get($attempt->error_json, 'code'));
        $this->assertStringNotContainsString('no headers found', json_encode($attempt->error_json));

        $this->expectException(LogicException::class);
        $attempt->forceFill(['reason_message' => 'rewritten'])->save();
    }

    #[Test]
    public function missing_provider_uid_stops_trash_before_move_without_retrying(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX.Trash',
            'name' => 'Trash',
            'delimiter' => '.',
            'parent_path' => 'INBOX',
            'role' => EmailFolder::ROLE_TRASH,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 88,
        ]);

        $client = new class($account) extends ImapClient
        {
            public int $uidSearches = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 77];
            }

            public function messageExistsByUid(int $uid, string $folderPath = 'INBOX'): bool
            {
                $this->uidSearches++;

                return false;
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $operation = app(PerformEmailRemoteOperation::class)->handle(
            $placement,
            PerformEmailRemoteOperation::TRASH,
            $this->actor,
        );

        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $operation->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_STALE, $operation->failure_classification);
        $this->assertSame('REMOTE_OPERATION_SOURCE_MISSING', $operation->status_reason_code);
        $this->assertNull($operation->next_attempt_at);
        $this->assertNull($operation->provider_response_json);
        $this->assertFalse($operation->canBeRetried());
        $this->assertSame('INBOX.Trash', $operation->target_folder_path);
        $this->assertSame(1, $client->uidSearches);

        $attempt = $operation->attemptRecords()->sole();
        $this->assertSame('blocked', $attempt->outcome);
        $this->assertSame(EmailRemoteOperationAttempt::KIND_PREFLIGHT, $attempt->attempt_kind);
        $this->assertSame(EmailRemoteOperation::FAILURE_STALE, $attempt->failure_classification);
        $this->assertSame('REMOTE_OPERATION_SOURCE_MISSING', $attempt->reason_code);
        $this->assertSame(0, $operation->attempts);
        $this->assertSame(0, $operation->providerAttemptCount());

        $result = app(RunDueEmailRemoteOperations::class)->handle();
        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $operation->fresh()->attemptRecords()->count());
    }

    #[Test]
    public function provider_prefetch_failure_is_a_safe_read_attempt_not_a_mutation_attempt(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement);
        $client = new class($account) extends ImapClient
        {
            public int $reads = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 77];
            }

            public function fetchByUid(int $uid, string $folderPath = 'INBOX')
            {
                $this->reads++;

                throw new EmailProviderReadException(
                    'The provider message could not be read before the mailbox operation.',
                    0,
                    new RuntimeException('no headers found token=provider-secret'),
                );
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation);

        $this->assertSame(1, $client->reads);
        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $updated->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_TRANSIENT, $updated->failure_classification);
        $this->assertSame('REMOTE_OPERATION_TRANSIENT', $updated->error_code);
        $this->assertSame('The provider message could not be read before the mailbox operation.', $updated->error_message);
        $this->assertNull($updated->next_attempt_at);
        $this->assertTrue($updated->canBeRetried());
        $this->assertSame(0, $updated->attempts);
        $this->assertSame(0, $updated->providerAttemptCount());

        $attempt = $updated->attemptRecords()->sole();
        $this->assertSame(EmailRemoteOperationAttempt::KIND_PREFLIGHT, $attempt->attempt_kind);
        $this->assertSame('RuntimeException', data_get($attempt->error_json, 'type'));
        $this->assertSame('0', data_get($attempt->error_json, 'code'));
        $this->assertStringNotContainsString('no headers found', (string) $attempt->reason_message);
        $this->assertStringNotContainsString('provider-secret', json_encode([
            $updated->error_message,
            $updated->status_reason_message,
            $attempt->reason_message,
            $attempt->error_json,
        ]));
    }

    #[Test]
    public function flag_mutation_rejects_a_reused_uid_after_source_uidvalidity_reset(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement, 'seen-after-source-reset');
        $client = new class($account) extends ImapClient
        {
            public int $mutations = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 78];
            }

            public function setSeenByUid(int $uid, bool $seen, string $folderPath = 'INBOX'): bool
            {
                $this->mutations++;

                return true;
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation);

        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $updated->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_STALE, $updated->failure_classification);
        $this->assertSame('REMOTE_OPERATION_UIDVALIDITY_STALE', $updated->status_reason_code);
        $this->assertSame(0, $client->mutations);
        $this->assertFalse($placement->fresh()->provider_seen);
    }

    #[Test]
    public function ambiguous_flag_recovery_rejects_a_reused_uid_without_reading_or_projecting_it(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement, 'recover-seen-after-source-reset');
        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'attempts' => 1,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
        ])->save();
        $client = new class($account) extends ImapClient
        {
            public int $messageReads = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 78];
            }

            public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
            {
                $this->messageReads++;

                return [
                    'exists' => true,
                    'provider_seen' => true,
                    'imap_uid' => $uid,
                    'folder_path' => $folderPath,
                ];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation, 'manual', $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $updated->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_AMBIGUOUS, $updated->failure_classification);
        $this->assertSame('REMOTE_RECONCILIATION_SOURCE_NAMESPACE', $updated->status_reason_code);
        $this->assertSame(0, $client->messageReads);
        $this->assertFalse($placement->fresh()->provider_seen);
    }

    #[Test]
    public function move_uses_only_authoritative_copyuid_instead_of_a_concurrent_uidnext_guess(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $response = Response::make(1, [], [
            "TAG1 OK [COPYUID 88 7701 9124] MOVE completed\r\n",
        ]);
        $client = new class($account, $response) extends ImapClient
        {
            public function __construct(
                EmailAccount $account,
                private readonly Response $response,
            ) {
                parent::__construct($account);
            }

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 77];
            }

            public function messageExistsByUid(int $uid, string $folderPath = 'INBOX'): bool
            {
                return true;
            }

            protected function performUidMove(
                int $uid,
                string $sourceFolderPath,
                string $targetFolderPath,
            ): Response {
                return $this->response;
            }
        };

        // 9123 represents a concurrent delivery that consumed the pre-MOVE
        // UIDNEXT guess. COPYUID proves the moved message actually became 9124.
        $result = $client->moveByUid((int) $placement->imap_uid, 'INBOX', 'INBOX.Trash');

        $this->assertTrue($result['ok']);
        $this->assertSame('INBOX.Trash', $result['target_folder_path']);
        $this->assertSame(9124, $result['target_imap_uid']);
        $this->assertSame(88, $result['target_uid_validity']);
        $this->assertTrue($result['target_uid_authoritative']);
    }

    #[Test]
    public function move_rejects_copyuid_that_is_not_one_exact_tagged_ok_response_code(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $responses = [
            Response::make(1, [], [
                "* OK [COPYUID 88 7701 9901] unsolicited\r\n",
                "TAG1 OK MOVE completed\r\n",
            ]),
            Response::make(1, [], [
                "TAG1 OK [COPYUID 88 7701 9902] [COPYUID 88 7701 9903] MOVE completed\r\n",
            ]),
            Response::make(1, [], [
                "TAG1 OK [COPYUID 88 invalid 9904] MOVE completed\r\n",
            ]),
            Response::make(1, [], [
                "TAG1 OK [COPYUID 88 7702 9905] MOVE completed\r\n",
            ]),
            Response::make(1, [], [
                "TAG1 OK [COPYUID 88 7701:7702 9906] MOVE completed\r\n",
            ]),
        ];

        foreach ($responses as $response) {
            $client = new class($account, $response) extends ImapClient
            {
                public function __construct(
                    EmailAccount $account,
                    private readonly Response $response,
                ) {
                    parent::__construct($account);
                }

                public function folderState(string $folderPath): array
                {
                    return ['uid_validity' => 77];
                }

                public function messageExistsByUid(int $uid, string $folderPath = 'INBOX'): bool
                {
                    return true;
                }

                protected function performUidMove(
                    int $uid,
                    string $sourceFolderPath,
                    string $targetFolderPath,
                ): Response {
                    return $this->response;
                }
            };

            $result = $client->moveByUid((int) $placement->imap_uid, 'INBOX', 'Archive');

            $this->assertFalse($result['target_uid_authoritative']);
            $this->assertNull($result['target_uid_validity']);
            $this->assertNull($result['target_imap_uid']);
        }
    }

    #[Test]
    public function acknowledged_move_without_exact_copyuid_remains_ambiguous_and_is_never_projected(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $target = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 88,
        ]);
        $this->activateNamespace($target, 88);
        $operation = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            PerformEmailRemoteOperation::MOVE,
            'move-without-copyuid',
            $this->actor,
            $placement->folder,
            $placement,
            [
                'source_folder_path' => 'INBOX',
                'target_folder_path' => 'Archive',
            ],
        );
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

        $updated = app(RunEmailRemoteOperation::class)->handle($operation);

        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $updated->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_AMBIGUOUS, $updated->failure_classification);
        $this->assertFalse($updated->canBeRetried());
        $this->assertNull($updated->acknowledged_target_uid_validity);
        $this->assertNull($updated->acknowledged_target_uid);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        $this->assertFalse(EmailMailboxPlacement::query()
            ->where('email_folder_id', $target->id)
            ->exists());
    }

    #[Test]
    public function exact_copyuid_projects_the_matching_active_target_namespace_atomically(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $target = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 88,
        ]);
        $namespace = $this->activateNamespace($target, 88);
        $operation = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            PerformEmailRemoteOperation::MOVE,
            'move-with-copyuid',
            $this->actor,
            $placement->folder,
            $placement,
            [
                'source_folder_path' => 'INBOX',
                'target_folder_path' => 'Archive',
            ],
        );
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
                    'target_imap_uid' => 8801,
                    'target_uid_validity' => 88,
                    'target_uid_authoritative' => true,
                ];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation);
        $targetPlacement = EmailMailboxPlacement::query()
            ->where('email_folder_id', $target->id)
            ->sole();

        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $updated->status);
        $this->assertSame(88, $updated->acknowledged_target_uid_validity);
        $this->assertSame(8801, $updated->acknowledged_target_uid);
        $this->assertSame($namespace->id, $targetPlacement->uid_namespace_id);
        $this->assertSame(88, $targetPlacement->imap_uid_validity);
        $this->assertSame(8801, $targetPlacement->imap_uid);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);
    }

    #[Test]
    public function provider_move_preserves_byte_distinct_source_and_target_paths_end_to_end(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $sourceFolder = $placement->folder;
        $sourceFolder->forceFill([
            'path' => 'Source ',
            'name' => 'Exact source',
            'role' => EmailFolder::ROLE_CUSTOM,
        ])->save();
        $placement->forceFill(['folder_path' => 'Source '])->save();
        $placement->message->forceFill(['mailbox' => 'Source '])->save();
        $sourceSibling = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Source',
            'name' => 'Trimmed source sibling',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 177,
        ]);
        $target = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Target ',
            'name' => 'Exact target',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 88,
        ]);
        $namespace = $this->activateNamespace($target, 88);
        $targetSibling = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Target',
            'name' => 'Trimmed target sibling',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 188,
        ]);

        $client = new class($account) extends ImapClient
        {
            /** @var list<string> */
            public array $statePaths = [];

            /** @var list<array{int, string, string}> */
            public array $moves = [];

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                $this->statePaths[] = $folderPath;

                return ['uid_validity' => $folderPath === 'Source ' ? 77 : 999];
            }

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->moves[] = [$uid, $sourceFolderPath, $targetFolderPath];

                return [
                    'ok' => true,
                    'target_folder_path' => $targetFolderPath,
                    'target_imap_uid' => 8801,
                    'target_uid_validity' => 88,
                    'target_uid_authoritative' => true,
                ];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $operation = app(PerformEmailRemoteOperation::class)->handle(
            $placement->fresh(),
            PerformEmailRemoteOperation::MOVE,
            $this->actor,
            $target,
        );
        $targetPlacement = EmailMailboxPlacement::query()
            ->where('email_folder_id', $target->id)
            ->sole();

        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $operation->status);
        $this->assertSame('Source ', $operation->source_folder_path);
        $this->assertSame('Target ', $operation->target_folder_path);
        $this->assertSame(['Source '], $client->statePaths);
        $this->assertSame([[7701, 'Source ', 'Target ']], $client->moves);
        $this->assertSame($namespace->id, $targetPlacement->uid_namespace_id);
        $this->assertSame('Target ', $targetPlacement->folder_path);
        $this->assertSame('Source', $sourceSibling->fresh()->path);
        $this->assertSame('Target', $targetSibling->fresh()->path);
        $this->assertFalse(EmailMailboxPlacement::query()
            ->where('email_folder_id', $targetSibling->id)
            ->exists());
    }

    #[Test]
    public function copyuid_from_a_superseded_target_namespace_never_aliases_a_reused_uid(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $target = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 99,
        ]);
        $this->activateNamespace($target, 99);
        $operation = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            PerformEmailRemoteOperation::MOVE,
            'move-old-target-namespace',
            $this->actor,
            $placement->folder,
            $placement,
            [
                'source_folder_path' => 'INBOX',
                'target_folder_path' => 'Archive',
            ],
        );
        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'attempts' => 1,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
            'provider_response_json' => [
                'ok' => true,
                'target_folder_path' => 'Archive',
                'target_imap_uid' => 8801,
                'target_uid_validity' => 88,
                'target_uid_authoritative' => true,
            ],
            'acknowledged_target_uid_validity' => 88,
            'acknowledged_target_uid' => 8801,
        ])->save();
        $client = new class($account) extends ImapClient
        {
            public int $targetReads = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $folderPath === 'Archive' ? 99 : 77];
            }

            public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
            {
                if ($folderPath === 'Archive') {
                    $this->targetReads++;
                }

                return [
                    'exists' => $folderPath === 'Archive',
                    'imap_uid' => $uid,
                    'folder_path' => $folderPath,
                ];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation, 'manual', $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $updated->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_AMBIGUOUS, $updated->failure_classification);
        $this->assertSame('REMOTE_RECONCILIATION_TARGET_NAMESPACE', $updated->status_reason_code);
        $this->assertSame(0, $client->targetReads);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        $this->assertFalse(EmailMailboxPlacement::query()
            ->where('email_folder_id', $target->id)
            ->exists());
    }

    #[Test]
    public function ambiguous_move_recovery_rejects_a_reused_source_uid_after_uidvalidity_reset(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $target = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 88,
        ]);
        $this->activateNamespace($target, 88);
        $operation = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            PerformEmailRemoteOperation::MOVE,
            'recover-after-source-reset',
            $this->actor,
            $placement->folder,
            $placement,
            [
                'source_folder_path' => 'INBOX',
                'target_folder_path' => 'Archive',
            ],
        );
        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'attempts' => 1,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
            'acknowledged_target_uid_validity' => 88,
            'acknowledged_target_uid' => 8801,
        ])->save();
        $client = new class($account) extends ImapClient
        {
            public int $messageReads = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $folderPath === 'INBOX' ? 78 : 88];
            }

            public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
            {
                $this->messageReads++;

                return ['exists' => true, 'imap_uid' => $uid, 'folder_path' => $folderPath];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation, 'manual', $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $updated->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_AMBIGUOUS, $updated->failure_classification);
        $this->assertSame('REMOTE_RECONCILIATION_SOURCE_NAMESPACE', $updated->status_reason_code);
        $this->assertSame(0, $client->messageReads);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        $this->assertFalse(EmailMailboxPlacement::query()
            ->where('email_folder_id', $target->id)
            ->exists());
    }

    #[Test]
    public function ambiguous_flag_and_move_recovery_require_the_frozen_local_source_namespace(): void
    {
        [$flagAccount, , $flagPlacement] = $this->mailboxContext();
        [$moveAccount, , $movePlacement] = $this->mailboxContext();
        $target = EmailFolder::create([
            'account_id' => $moveAccount->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 88,
        ]);
        $this->activateNamespace($target, 88);
        $flag = $this->pendingSeen($flagAccount, $flagPlacement, 'recover-flag-after-local-namespace-change');
        $move = app(RecordEmailRemoteOperation::class)->pending(
            $moveAccount,
            PerformEmailRemoteOperation::MOVE,
            'recover-move-after-local-namespace-change',
            $this->actor,
            $movePlacement->folder,
            $movePlacement,
            [
                'source_folder_path' => 'INBOX',
                'target_folder_path' => 'Archive',
            ],
        );
        foreach ([$flag, $move] as $operation) {
            $operation->forceFill([
                'status' => EmailRemoteOperation::STATUS_FAILED,
                'attempts' => 1,
                'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
                'acknowledged_target_uid_validity' => $operation->is($move) ? 88 : null,
                'acknowledged_target_uid' => $operation->is($move) ? 8801 : null,
            ])->save();
        }

        foreach ([$flagPlacement, $movePlacement] as $placement) {
            $oldNamespace = EmailFolderUidNamespace::query()->findOrFail($placement->uid_namespace_id);
            $oldNamespace->forceFill([
                'status' => EmailFolderUidNamespace::STATUS_SUPERSEDED,
                'superseded_at' => now(),
            ])->save();
            $newNamespace = EmailFolderUidNamespace::create([
                'account_id' => $placement->account_id,
                'email_folder_id' => $placement->email_folder_id,
                'generation' => 2,
                'uid_validity' => 78,
                'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
                'provenance_code' => 'test_local_namespace_replacement',
                'established_at' => now(),
            ]);
            $placement->folder->forceFill(['active_uid_namespace_id' => $newNamespace->id])->save();
        }

        $clientFor = fn (EmailAccount $account): ImapClient => new class($account) extends ImapClient
        {
            public int $messageReads = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $folderPath === 'Archive' ? 88 : 77];
            }

            public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
            {
                $this->messageReads++;

                return ['exists' => true, 'imap_uid' => $uid, 'folder_path' => $folderPath];
            }

            public function disconnect(): void {}
        };
        $flagClient = $clientFor($flagAccount);
        $this->app->bind(ImapClient::class, fn () => $flagClient);
        $updatedFlag = app(RunEmailRemoteOperation::class)->handle($flag, 'manual', $this->actor);
        $moveClient = $clientFor($moveAccount);
        $this->app->bind(ImapClient::class, fn () => $moveClient);
        $updatedMove = app(RunEmailRemoteOperation::class)->handle($move, 'manual', $this->actor);

        $this->assertSame('REMOTE_RECONCILIATION_SOURCE_NAMESPACE', $updatedFlag->status_reason_code);
        $this->assertSame('REMOTE_RECONCILIATION_SOURCE_NAMESPACE', $updatedMove->status_reason_code);
        $this->assertSame(EmailRemoteOperation::FAILURE_AMBIGUOUS, $updatedFlag->failure_classification);
        $this->assertSame(EmailRemoteOperation::FAILURE_AMBIGUOUS, $updatedMove->failure_classification);
        $this->assertSame(0, $flagClient->messageReads);
        $this->assertSame(0, $moveClient->messageReads);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $flagPlacement->fresh()->local_state);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $movePlacement->fresh()->local_state);
    }

    #[Test]
    public function ambiguous_move_recovery_requires_matching_provider_and_local_target_namespace(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $target = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 88,
        ]);
        $namespace = $this->activateNamespace($target, 88);
        $operation = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            PerformEmailRemoteOperation::MOVE,
            'recover-exact-target-namespace',
            $this->actor,
            $placement->folder,
            $placement,
            [
                'source_folder_path' => 'INBOX',
                'target_folder_path' => 'Archive',
            ],
        );
        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'attempts' => 1,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
            'provider_response_json' => [
                'target_folder_path' => 'Archive',
                'target_imap_uid' => 8801,
                'target_uid_validity' => 88,
                'target_uid_authoritative' => true,
            ],
            'acknowledged_target_uid_validity' => 88,
            'acknowledged_target_uid' => 8801,
        ])->save();
        $client = new class($account) extends ImapClient
        {
            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $folderPath === 'Archive' ? 88 : 77];
            }

            public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
            {
                return [
                    'exists' => $folderPath === 'Archive',
                    'imap_uid' => $uid,
                    'folder_path' => $folderPath,
                ];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation, 'manual', $this->actor);
        $targetPlacement = EmailMailboxPlacement::query()
            ->where('email_folder_id', $target->id)
            ->sole();

        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $updated->status);
        $this->assertSame($namespace->id, $targetPlacement->uid_namespace_id);
        $this->assertSame(88, $targetPlacement->imap_uid_validity);
        $this->assertSame(8801, $targetPlacement->imap_uid);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);
    }

    #[Test]
    public function authoritative_target_identity_is_coherent_immutable_and_rollback_retained(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $target = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 88,
        ]);
        $this->activateNamespace($target, 88);
        $operation = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            PerformEmailRemoteOperation::MOVE,
            'target-identity-db-guard',
            $this->actor,
            $placement->folder,
            $placement,
            [
                'source_folder_path' => 'INBOX',
                'target_folder_path' => $target->path,
            ],
        );

        try {
            DB::table('email_remote_operations')
                ->where('id', $operation->id)
                ->update(['acknowledged_target_uid' => 8801]);
            $this->fail('A partial authoritative target tuple must fail at the database boundary.');
        } catch (\Throwable) {
            $this->assertNull($operation->fresh()->acknowledged_target_uid);
        }

        DB::table('email_remote_operations')
            ->where('id', $operation->id)
            ->update([
                'acknowledged_target_uid_validity' => 88,
                'acknowledged_target_uid' => 8801,
            ]);
        $this->assertSame(8801, $operation->fresh()->acknowledged_target_uid);

        try {
            DB::table('email_remote_operations')
                ->where('id', $operation->id)
                ->update(['acknowledged_target_uid' => 8802]);
            $this->fail('Raw repair code must not rewrite acknowledged target identity.');
        } catch (\Throwable) {
            $this->assertSame(8801, $operation->fresh()->acknowledged_target_uid);
        }

        try {
            $operation->refresh()->forceFill(['acknowledged_target_uid' => 8802])->save();
            $this->fail('Eloquent must not rewrite acknowledged target identity.');
        } catch (LogicException) {
            $this->assertSame(8801, $operation->fresh()->acknowledged_target_uid);
        }

        $migration = require database_path(
            'migrations/2026_08_16_118300_add_authoritative_target_identity_to_email_remote_operations.php',
        );
        try {
            $migration->down();
            $this->fail('Rollback must preserve retained authoritative target identity evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('must be preserved', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn(
            'email_remote_operations',
            'acknowledged_target_uid_validity',
        ));
    }

    #[Test]
    public function folder_discovery_repairs_nested_special_roles_and_trash_uses_the_canonical_folder(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $trash = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX.Trash',
            'name' => 'Trash',
            'delimiter' => '.',
            'parent_path' => 'INBOX',
            'role' => EmailFolder::ROLE_TRASH,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 88,
        ]);
        $legacyChild = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX.Trash. Skatteetaten',
            'name' => ' Skatteetaten',
            'delimiter' => '.',
            'parent_path' => 'INBOX.Trash',
            'role' => EmailFolder::ROLE_TRASH,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 89,
        ]);

        $target = app(PerformEmailRemoteOperation::class)->targetFolderFor(
            $placement,
            PerformEmailRemoteOperation::TRASH,
        );

        $this->assertSame($trash->id, $target?->id);
        $this->assertSame(
            EmailFolder::ROLE_CUSTOM,
            EmailFolder::inferRole($legacyChild->path, null, $legacyChild->delimiter),
        );
        $this->assertSame(
            EmailFolder::ROLE_TRASH,
            EmailFolder::inferRole($legacyChild->path, '\\Trash', $legacyChild->delimiter),
        );
        $this->assertSame(EmailFolder::ROLE_INBOX, EmailFolder::inferRole('INBOX'));
        $this->assertSame(EmailFolder::ROLE_CUSTOM, EmailFolder::inferRole('INBOX '));
        $this->assertSame(EmailFolder::ROLE_CUSTOM, EmailFolder::inferRole('Sent '));
        $this->assertSame(EmailFolder::ROLE_CUSTOM, EmailFolder::inferRole('Archive '));
        $this->assertSame(EmailFolder::ROLE_CUSTOM, EmailFolder::inferRole("Sent\u{00A0}"));
        $this->assertSame(EmailFolder::ROLE_CUSTOM, EmailFolder::inferRole("Archive\u{0085}"));
        $this->assertSame(
            EmailFolder::ROLE_SENT,
            EmailFolder::inferRole('Sent ', '\\Sent'),
        );

        $repaired = app(EmailFolderProjector::class)->upsertFolder($account, [
            'path' => $legacyChild->path,
            'name' => $legacyChild->name,
            'delimiter' => $legacyChild->delimiter,
            'parent_path' => $legacyChild->parent_path,
            'special_use' => null,
            'role' => EmailFolder::ROLE_TRASH,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $legacyChild->uid_validity,
        ]);

        $this->assertSame($legacyChild->id, $repaired?->id);
        $this->assertSame(EmailFolder::ROLE_CUSTOM, $repaired?->role);
        $this->assertSame(EmailFolder::ROLE_TRASH, $trash->fresh()->role);
        $this->assertSame(1, EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('path', $legacyChild->path)
            ->count());
    }

    #[Test]
    public function connection_retries_are_bounded_without_consuming_mutation_budget(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement);
        $operation->forceFill([
            'max_attempts' => 2,
        ])->save();

        $client = new class($account) extends ImapClient
        {
            public function connect(): void
            {
                throw new RuntimeException('provider timeout');
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $first = app(RunEmailRemoteOperation::class)->handle($operation, 'scheduled');
        $this->assertNotNull($first->next_attempt_at);

        $updated = app(RunEmailRemoteOperation::class)->handle($first, 'scheduled');

        $this->assertSame(0, $updated->attempts);
        $this->assertSame(0, $updated->providerAttemptCount());
        $this->assertNull($updated->next_attempt_at);
        $this->assertSame('REMOTE_OPERATION_MAX_ATTEMPTS', $updated->status_reason_code);
        $this->assertTrue($updated->canBeRetried());
        $this->assertSame(2, $updated->attemptRecords()->count());
        $this->assertTrue($updated->attemptRecords->every(
            fn (EmailRemoteOperationAttempt $attempt): bool => $attempt->attempt_kind === EmailRemoteOperationAttempt::KIND_PREFLIGHT,
        ));
    }

    #[Test]
    public function revoked_requester_access_supersedes_before_provider_connection(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement);
        EmailAccountUserGrant::query()
            ->where('email_account_id', $account->id)
            ->where('user_id', $this->actor->id)
            ->update(['can_organize' => false]);

        $client = new class($account) extends ImapClient
        {
            public int $connections = 0;

            public function connect(): void
            {
                $this->connections++;
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation);

        $this->assertSame(EmailRemoteOperation::STATUS_SUPERSEDED, $updated->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_AUTHORIZATION, $updated->failure_classification);
        $this->assertSame(0, $client->connections);
    }

    #[Test]
    public function due_runner_executes_only_due_retryable_work(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement);
        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'attempts' => 1,
            'failure_classification' => EmailRemoteOperation::FAILURE_TRANSIENT,
            'next_attempt_at' => now()->subMinute(),
        ])->save();

        $client = new class($account) extends ImapClient
        {
            public int $mutations = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 77];
            }

            public function setSeenByUid(int $uid, bool $seen, string $folderPath = 'INBOX'): bool
            {
                $this->mutations++;

                return true;
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $result = app(RunDueEmailRemoteOperations::class)->handle();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $client->mutations);
        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $operation->fresh()->status);
    }

    #[Test]
    public function cancellation_is_idempotent_and_refuses_running_attempts(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement);

        $first = app(CancelEmailRemoteOperation::class)->handle($operation, $this->actor);
        $second = app(CancelEmailRemoteOperation::class)->handle($first, $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_CANCELLED, $second->status);

        $running = $this->pendingSeen($account, $placement, 'running-cancel');
        $running->forceFill(['status' => EmailRemoteOperation::STATUS_RUNNING])->save();

        $this->expectException(ValidationException::class);
        app(CancelEmailRemoteOperation::class)->handle($running, $this->actor);
    }

    #[Test]
    public function ambiguous_seen_result_reconciles_without_duplicate_mutation_and_refreshes_conversation(): void
    {
        [$account, $conversation, $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement);
        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'attempts' => 1,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
            'reconciliation_required_at' => now(),
        ])->save();

        $client = new class($account) extends ImapClient
        {
            public int $mutations = 0;

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
                    'provider_seen' => true,
                    'provider_flagged' => false,
                ];
            }

            public function setSeenByUid(int $uid, bool $seen, string $folderPath = 'INBOX'): bool
            {
                $this->mutations++;

                return true;
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation, 'scheduled');

        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $updated->status);
        $this->assertSame(0, $client->mutations);
        $this->assertTrue($updated->provider_response_json['reconciled']);
        $this->assertTrue($placement->fresh()->provider_seen);
        $this->assertSame(0, $conversation->fresh()->provider_unread_count);
        $this->assertSame(EmailRemoteOperationAttempt::KIND_RECONCILIATION, $updated->attemptRecords()->sole()->attempt_kind);
    }

    #[Test]
    public function ambiguous_move_without_target_evidence_never_replays_provider_mutation(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $target = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 88,
        ]);
        $operation = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            'move',
            'ambiguous-move',
            $this->actor,
            $placement->folder,
            $placement,
            ['source_folder_path' => 'INBOX', 'target_folder_path' => $target->path],
        );
        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'attempts' => 1,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
        ])->save();

        $client = new class($account) extends ImapClient
        {
            public int $moves = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 77];
            }

            public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
            {
                return ['exists' => false, 'imap_uid' => $uid, 'folder_path' => $folderPath];
            }

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->moves++;

                return ['ok' => true];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation, 'manual', $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $updated->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_AMBIGUOUS, $updated->failure_classification);
        $this->assertSame('REMOTE_RECONCILIATION_MOVE_AMBIGUOUS', $updated->status_reason_code);
        $this->assertNull($updated->next_attempt_at);
        $this->assertSame(0, $client->moves);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        $this->assertFalse($updated->canBeRetried());
    }

    #[Test]
    public function ambiguous_move_with_source_and_target_present_never_replays_provider_mutation(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $target = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 88,
        ]);
        $this->activateNamespace($target, 88);
        $operation = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            'move',
            'ambiguous-duplicated-move',
            $this->actor,
            $placement->folder,
            $placement,
            ['source_folder_path' => 'INBOX', 'target_folder_path' => $target->path],
        );
        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'attempts' => 1,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
            'provider_response_json' => [
                'target_imap_uid' => 8801,
                'target_uid_validity' => 88,
                'target_uid_authoritative' => true,
            ],
            'acknowledged_target_uid_validity' => 88,
            'acknowledged_target_uid' => 8801,
        ])->save();

        $client = new class($account) extends ImapClient
        {
            public int $reads = 0;

            public int $moves = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $folderPath === 'Archive' ? 88 : 77];
            }

            public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
            {
                $this->reads++;

                return ['exists' => true, 'imap_uid' => $uid, 'folder_path' => $folderPath];
            }

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->moves++;

                return ['ok' => true];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation, 'manual', $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $updated->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_AMBIGUOUS, $updated->failure_classification);
        $this->assertSame('REMOTE_RECONCILIATION_MOVE_DUPLICATED', $updated->status_reason_code);
        $this->assertSame(2, $client->reads);
        $this->assertSame(0, $client->moves);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
    }

    #[Test]
    public function folder_rename_and_delete_writes_preserve_trailing_space_identity(): void
    {
        [$account] = $this->mailboxContext();
        $renameSource = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Rename source ',
            'name' => 'Rename source exact',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 501,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $renameSourceSibling = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Rename source',
            'name' => 'Rename source sibling',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 502,
        ]);
        $renameTargetSibling = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Rename target',
            'name' => 'Rename target sibling',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 503,
        ]);
        $deleteSource = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Delete source ',
            'name' => 'Delete source exact',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 504,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $deleteSourceSibling = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Delete source',
            'name' => 'Delete source sibling',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 505,
        ]);
        $rename = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            ManageProviderEmailFolder::RENAME_FOLDER,
            'rename-byte-exact-'.$account->id,
            $this->actor,
            $renameSource,
            null,
            [
                'source_folder_path' => 'Rename source ',
                'target_folder_path' => 'Rename target ',
            ],
        );
        $delete = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            ManageProviderEmailFolder::DELETE_FOLDER,
            'delete-byte-exact-'.$account->id,
            $this->actor,
            $deleteSource,
            null,
            ['source_folder_path' => 'Delete source '],
        );

        $client = new class($account) extends ImapClient
        {
            /** @var list<array{string, string}> */
            public array $renames = [];

            /** @var list<string> */
            public array $deletions = [];

            public function connect(): void {}

            public function renameFolder(string $sourceFolderPath, string $targetFolderPath): array
            {
                $this->renames[] = [$sourceFolderPath, $targetFolderPath];

                return [
                    'ok' => true,
                    'source_folder_path' => $sourceFolderPath,
                    'target_folder_path' => $targetFolderPath,
                    'path' => $targetFolderPath,
                ];
            }

            public function deleteFolder(string $folderPath): array
            {
                $this->deletions[] = $folderPath;

                return ['ok' => true, 'folder_path' => $folderPath];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $renamed = app(RunEmailRemoteOperation::class)->handle($rename);
        $deleted = app(RunEmailRemoteOperation::class)->handle($delete);

        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $renamed->status);
        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $deleted->status);
        $this->assertSame([['Rename source ', 'Rename target ']], $client->renames);
        $this->assertSame(['Delete source '], $client->deletions);
        $this->assertSame('Rename target ', $renameSource->fresh()->path);
        $this->assertSame('Rename source', $renameSourceSibling->fresh()->path);
        $this->assertSame('Rename target', $renameTargetSibling->fresh()->path);
        $this->assertFalse((bool) $deleteSource->fresh()->is_selectable);
        $this->assertTrue((bool) $deleteSourceSibling->fresh()->is_selectable);
    }

    #[Test]
    public function ambiguous_recovery_reads_only_exact_trailing_space_paths(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $sourceFolder = $placement->folder;
        $sourceFolder->forceFill([
            'path' => 'Recovery source ',
            'name' => 'Recovery source exact',
            'role' => EmailFolder::ROLE_CUSTOM,
        ])->save();
        $placement->forceFill(['folder_path' => 'Recovery source '])->save();
        EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Recovery source',
            'name' => 'Recovery source sibling',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 177,
        ]);
        $target = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Recovery target ',
            'name' => 'Recovery target exact',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 88,
        ]);
        $this->activateNamespace($target, 88);
        $targetSibling = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Recovery target',
            'name' => 'Recovery target sibling',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 188,
        ]);
        $move = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            PerformEmailRemoteOperation::MOVE,
            'recover-byte-exact-move-'.$account->id,
            $this->actor,
            $sourceFolder,
            $placement,
            [
                'source_folder_path' => 'Recovery source ',
                'target_folder_path' => 'Recovery target ',
            ],
        );
        $move->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
            'acknowledged_target_uid_validity' => 88,
            'acknowledged_target_uid' => 8801,
        ])->save();

        $renameFolder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Recovery rename ',
            'name' => 'Recovery rename exact',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 301,
        ]);
        EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Recovery rename',
            'name' => 'Recovery rename sibling',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 302,
        ]);
        EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Recovery renamed',
            'name' => 'Recovery renamed sibling',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 303,
        ]);
        $rename = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            ManageProviderEmailFolder::RENAME_FOLDER,
            'recover-byte-exact-rename-'.$account->id,
            $this->actor,
            $renameFolder,
            null,
            [
                'source_folder_path' => 'Recovery rename ',
                'target_folder_path' => 'Recovery renamed ',
            ],
        );
        $deleteFolder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Recovery delete ',
            'name' => 'Recovery delete exact',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 401,
        ]);
        EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Recovery delete',
            'name' => 'Recovery delete sibling',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 402,
        ]);
        $delete = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            ManageProviderEmailFolder::DELETE_FOLDER,
            'recover-byte-exact-delete-'.$account->id,
            $this->actor,
            $deleteFolder,
            null,
            ['source_folder_path' => 'Recovery delete '],
        );

        $client = new class($account) extends ImapClient
        {
            /** @var list<string> */
            public array $statePaths = [];

            /** @var list<string> */
            public array $messagePaths = [];

            /** @var list<string> */
            public array $folderExistencePaths = [];

            public function folderState(string $folderPath): array
            {
                $this->statePaths[] = $folderPath;

                return ['uid_validity' => $folderPath === 'Recovery source ' ? 77 : 88];
            }

            public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
            {
                $this->messagePaths[] = $folderPath;

                return ['exists' => true, 'imap_uid' => $uid, 'folder_path' => $folderPath];
            }

            public function folderExists(string $folderPath): bool
            {
                $this->folderExistencePaths[] = $folderPath;

                return in_array($folderPath, ['Recovery rename ', 'Recovery delete '], true);
            }
        };
        $reconciler = app(EmailRemoteOperationReconciler::class);

        $moveResult = $reconciler->reconcile($move, $client);
        $renameResult = $reconciler->reconcile($rename, $client);
        $deleteResult = $reconciler->reconcile($delete, $client);

        $this->assertSame(EmailRemoteOperationReconciler::UNRESOLVED, $moveResult['result']);
        $this->assertSame('REMOTE_RECONCILIATION_MOVE_DUPLICATED', $moveResult['reason_code']);
        $this->assertSame(EmailRemoteOperationReconciler::NOT_APPLIED, $renameResult['result']);
        $this->assertSame(EmailRemoteOperationReconciler::NOT_APPLIED, $deleteResult['result']);
        $this->assertSame(
            ['Recovery source ', 'Recovery target '],
            $client->statePaths,
        );
        $this->assertSame(
            ['Recovery source ', 'Recovery target '],
            $client->messagePaths,
        );
        $this->assertSame(
            ['Recovery rename ', 'Recovery renamed ', 'Recovery delete '],
            $client->folderExistencePaths,
        );
        $this->assertSame('Recovery target', $targetSibling->fresh()->path);
    }

    #[Test]
    public function stable_observer_resolves_only_the_byte_exact_trailing_space_target(): void
    {
        [$account, , $sourcePlacement] = $this->mailboxContext();
        $sourceFolder = $sourcePlacement->folder;
        $sourceFolder->forceFill([
            'path' => 'Observed source ',
            'name' => 'Observed source exact',
            'role' => EmailFolder::ROLE_CUSTOM,
        ])->save();
        $sourcePlacement->forceFill(['folder_path' => 'Observed source '])->save();
        $sourceNamespace = $sourcePlacement->uidNamespace;

        $exactTargetFolder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Observed target ',
            'name' => 'Observed target exact',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 923,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $exactTargetNamespace = $this->activateNamespace($exactTargetFolder, 923);
        $trimmedTargetFolder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Observed target',
            'name' => 'Observed target sibling',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 923,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $trimmedTargetNamespace = $this->activateNamespace($trimmedTargetFolder, 923);
        $run = EmailProviderReconciliationRun::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'trigger' => EmailProviderReconciliationRun::TRIGGER_MANUAL,
            'status' => EmailProviderReconciliationRun::STATUS_QUEUED,
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_START,
            'active_slot' => 1,
            'idempotency_key' => hash('sha256', 'path-observer:'.$account->id),
            'provider_binding_version' => 1,
            'max_folders' => 10,
            'uid_batch_size' => 10,
            'provider_time_cap_seconds' => 10,
            'normal_interval_seconds' => 300,
            'queued_at' => now(),
        ]);
        $sourceFolderRun = $this->stableReconciliationFolder(
            $run,
            $sourceFolder,
            $sourceNamespace,
            77,
        );
        $this->stableReconciliationFolder(
            $run,
            $exactTargetFolder,
            $exactTargetNamespace,
            923,
        );
        $this->stableReconciliationFolder(
            $run,
            $trimmedTargetFolder,
            $trimmedTargetNamespace,
            923,
        );
        $exactTargetPlacement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $sourcePlacement->email_message_id,
            'email_conversation_id' => $sourcePlacement->email_conversation_id,
            'account_id' => $account->id,
            'email_folder_id' => $exactTargetFolder->id,
            'uid_namespace_id' => $exactTargetNamespace->id,
            'provider' => 'imap',
            'folder_path' => 'Observed target ',
            'imap_uid_validity' => 923,
            'imap_uid' => 7,
            'provider_seen' => true,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
            'last_provider_reconciliation_run_id' => $run->id,
        ]);
        $trimmedTargetPlacement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $sourcePlacement->email_message_id,
            'email_conversation_id' => $sourcePlacement->email_conversation_id,
            'account_id' => $account->id,
            'email_folder_id' => $trimmedTargetFolder->id,
            'uid_namespace_id' => $trimmedTargetNamespace->id,
            'provider' => 'imap',
            'folder_path' => 'Observed target',
            'imap_uid_validity' => 923,
            'imap_uid' => 7,
            'provider_seen' => true,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
            'last_provider_reconciliation_run_id' => $run->id,
        ]);
        $absence = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $sourceFolderRun->id,
            'uid_namespace_id' => $sourceNamespace->id,
            'imap_uid' => $sourcePlacement->imap_uid,
            'kind' => EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE,
            'status' => EmailProviderReconciliationItem::STATUS_PENDING,
            'source_placement_id' => $sourcePlacement->id,
            'placement_sync_version_before' => $sourcePlacement->sync_version,
        ]);
        $operation = EmailRemoteOperation::query()->create([
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'email_folder_id' => $sourceFolder->id,
            'email_mailbox_placement_id' => $sourcePlacement->id,
            'provider' => 'imap',
            'operation_type' => PerformEmailRemoteOperation::MOVE,
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
            'idempotency_key' => 'observer-byte-exact-'.$account->id,
            'source_folder_path' => 'Observed source ',
            'target_folder_path' => 'Observed target ',
            'expected_placement_sync_version' => $sourcePlacement->sync_version,
            'expected_provider_uid' => $sourcePlacement->imap_uid,
            'expected_uid_validity' => $sourcePlacement->imap_uid_validity,
            'acknowledged_target_uid_validity' => 923,
            'acknowledged_target_uid' => 7,
        ]);

        $resolved = app(EmailProviderRemoteOperationObserver::class)
            ->reconcileStableSourceAbsence($absence);

        $this->assertSame($exactTargetPlacement->id, $resolved);
        $this->assertSame($exactTargetPlacement->id, $absence->fresh()->target_placement_id);
        $this->assertSame($operation->id, $absence->fresh()->email_remote_operation_id);
        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $operation->fresh()->status);
        $this->assertSame('Observed target', $trimmedTargetPlacement->fresh()->folder_path);
    }

    #[Test]
    public function provider_folder_inventory_errors_do_not_confirm_ambiguous_deletion(): void
    {
        [$account] = $this->mailboxContext();
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'Projects',
            'name' => 'Projects',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 99,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $operation = app(RecordEmailRemoteOperation::class)->pending(
            $account,
            ManageProviderEmailFolder::DELETE_FOLDER,
            'ambiguous-folder-delete',
            $this->actor,
            $folder,
            null,
            ['source_folder_path' => $folder->path, 'folder_id' => $folder->id],
        );
        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'attempts' => 1,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
        ])->save();

        $client = new class($account) extends ImapClient
        {
            public int $deletions = 0;

            public function connect(): void {}

            protected function providerFolderInventory(): iterable
            {
                throw new RuntimeException('Provider folder inventory is unavailable.');
            }

            public function deleteFolder(string $folderPath): array
            {
                $this->deletions++;

                return ['ok' => true];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $updated = app(RunEmailRemoteOperation::class)->handle($operation, 'manual', $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $updated->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_AMBIGUOUS, $updated->failure_classification);
        $this->assertSame('REMOTE_RECONCILIATION_FAILED', $updated->status_reason_code);
        $this->assertSame(0, $client->deletions);
        $this->assertTrue((bool) $folder->fresh()->is_selectable);
        $this->assertTrue((bool) $folder->fresh()->sync_enabled);
        $this->assertNull($updated->next_attempt_at);
        $this->assertTrue($updated->canBeRetried());

        $this->travel(2)->hours();
        $result = app(RunDueEmailRemoteOperations::class)->handle();

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $client->deletions);
    }

    #[Test]
    public function ambiguous_reconciliation_runs_at_the_mutation_limit_without_an_extra_write(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement);
        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'attempts' => 1,
            'max_attempts' => 1,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
            'reconciliation_required_at' => now(),
        ])->save();
        EmailRemoteOperationAttempt::create([
            'email_remote_operation_id' => $operation->id,
            'attempt_number' => 1,
            'attempt_kind' => EmailRemoteOperationAttempt::KIND_RECONCILIATION,
            'trigger' => 'scheduled',
            'status' => EmailRemoteOperationAttempt::STATUS_FINISHED,
            'outcome' => 'unresolved',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
        ]);

        $client = new class($account) extends ImapClient
        {
            public int $reads = 0;

            public int $mutations = 0;

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 77];
            }

            public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
            {
                $this->reads++;

                return [
                    'exists' => true,
                    'imap_uid' => $uid,
                    'folder_path' => $folderPath,
                    'provider_seen' => false,
                    'provider_flagged' => false,
                ];
            }

            public function setSeenByUid(int $uid, bool $seen, string $folderPath = 'INBOX'): bool
            {
                $this->mutations++;

                return true;
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $this->assertTrue($operation->fresh()->canBeRetried());

        $updated = app(RunEmailRemoteOperation::class)->handle($operation, 'manual', $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $updated->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_PERMANENT, $updated->failure_classification);
        $this->assertSame('REMOTE_OPERATION_MAX_ATTEMPTS', $updated->status_reason_code);
        $this->assertSame(1, $client->reads);
        $this->assertSame(0, $client->mutations);
        $this->assertSame(1, $updated->providerAttemptCount());
        $this->assertSame(2, $updated->attemptRecords()->count());
        $this->assertTrue($updated->attemptRecords()->get()->every(
            fn (EmailRemoteOperationAttempt $attempt): bool => $attempt->attempt_kind === EmailRemoteOperationAttempt::KIND_RECONCILIATION,
        ));
        $this->assertFalse($updated->canBeRetried());
    }

    #[Test]
    public function remote_operation_api_hides_operations_outside_mailbox_scope(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement);
        $outsider = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $outsider->givePermissionTo(['email.inbox_view', 'email.inbox_manage']);
        Sanctum::actingAs($outsider, ['email.read']);

        $this->getJson(route('api.v1.email.mailbox.remote-operations.show', $operation))
            ->assertNotFound();

        $this->getJson(route('api.v1.email.mailbox.remote-operations.index', ['account_id' => $account->id]))
            ->assertNotFound();

        $this->getJson(route('api.v1.email.mailbox.remote-operations.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        EmailAccountUserGrant::create([
            'email_account_id' => $account->id,
            'user_id' => $outsider->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        Sanctum::actingAs($outsider, ['email.update']);

        $this->postJson(route('api.v1.email.mailbox.remote-operations.retry', $operation))
            ->assertForbidden();
    }

    #[Test]
    public function remote_operation_api_lists_details_retries_and_cancels_through_shared_actions(): void
    {
        [$account, , $placement] = $this->mailboxContext();
        $operation = $this->pendingSeen($account, $placement);
        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'attempts' => 1,
            'failure_classification' => EmailRemoteOperation::FAILURE_TRANSIENT,
            'next_attempt_at' => now()->addMinute(),
        ])->save();

        $client = new class($account) extends ImapClient
        {
            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 77];
            }

            public function setSeenByUid(int $uid, bool $seen, string $folderPath = 'INBOX'): bool
            {
                return true;
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);
        Sanctum::actingAs($this->actor, ['email.read', 'email.update']);

        $this->getJson(route('api.v1.email.mailbox.remote-operations.index', ['account_id' => $account->id]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $operation->id)
            ->assertJsonPath('data.0.failure_classification', EmailRemoteOperation::FAILURE_TRANSIENT);

        $this->getJson(route('api.v1.email.mailbox.remote-operations.show', $operation))
            ->assertOk()
            ->assertJsonPath('data.id', $operation->id)
            ->assertJsonPath('data.max_attempts', 5);

        $this->postJson(route('api.v1.email.mailbox.remote-operations.retry', $operation))
            ->assertOk()
            ->assertJsonPath('data.status', EmailRemoteOperation::STATUS_SUCCEEDED)
            ->assertJsonPath('data.attempt_records.0.attempt_kind', EmailRemoteOperationAttempt::KIND_MUTATION);

        $cancel = $this->pendingSeen($account, $placement->fresh(), 'api-cancel');
        $this->postJson(route('api.v1.email.mailbox.remote-operations.cancel', $cancel))
            ->assertOk()
            ->assertJsonPath('data.status', EmailRemoteOperation::STATUS_CANCELLED)
            ->assertJsonPath('data.status_reason_code', 'REMOTE_OPERATION_CANCELLED');
    }

    /** @return array{EmailAccount, EmailConversation, EmailMailboxPlacement} */
    private function mailboxContext(): array
    {
        $account = EmailAccount::create([
            'address' => Str::lower(Str::random(8)).'@example.test',
            'description' => 'Recovery test mailbox',
            'from_name' => 'Recovery test',
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
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 77,
        ]);
        $namespace = $this->activateNamespace($folder, 77);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 7701,
            'message_id' => '<'.Str::uuid().'@example.test>',
            'subject' => 'Provider recovery test',
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
            'uid_namespace_id' => $namespace->id,
            'provider' => 'imap',
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 77,
            'imap_uid' => 7701,
            'provider_seen' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);
        $conversation->forceFill(['latest_email_mailbox_placement_id' => $placement->id])->save();

        return [$account, $conversation, $placement];
    }

    private function activateNamespace(
        EmailFolder $folder,
        int $uidValidity,
    ): EmailFolderUidNamespace {
        $namespace = EmailFolderUidNamespace::create([
            'account_id' => $folder->account_id,
            'email_folder_id' => $folder->id,
            'generation' => max(1, (int) $folder->uidNamespaces()->max('generation') + 1),
            'uid_validity' => $uidValidity,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test_authoritative_copyuid',
            'established_at' => now(),
        ]);
        $folder->forceFill([
            'uid_validity' => $uidValidity,
            'active_uid_namespace_id' => $namespace->id,
        ])->save();

        return $namespace;
    }

    private function stableReconciliationFolder(
        EmailProviderReconciliationRun $run,
        EmailFolder $folder,
        EmailFolderUidNamespace $namespace,
        int $uidValidity,
    ): EmailProviderReconciliationFolder {
        return EmailProviderReconciliationFolder::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $folder->account_id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => $folder->path,
            'folder_name' => $folder->name,
            'delimiter' => '/',
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_EXISTING,
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
            'expected_uid_validity' => $uidValidity,
            'start_uid_validity' => $uidValidity,
            'end_uid_validity' => $uidValidity,
            'reason_code' => 'stable_end_validated',
        ]);
    }

    private function pendingSeen(
        EmailAccount $account,
        EmailMailboxPlacement $placement,
        ?string $key = null,
    ): EmailRemoteOperation {
        return app(RecordEmailRemoteOperation::class)->pending(
            $account,
            'mark_seen',
            $key ?: 'seen:'.$placement->id.':'.Str::uuid(),
            $this->actor,
            $placement->folder,
            $placement,
            [
                'source_folder_path' => $placement->folder_path,
                'placement_sync_version' => $placement->sync_version,
                'placement_imap_uid' => $placement->imap_uid,
                'placement_uid_validity' => $placement->imap_uid_validity,
                'target_state' => ['provider_seen' => true],
            ],
        );
    }
}
