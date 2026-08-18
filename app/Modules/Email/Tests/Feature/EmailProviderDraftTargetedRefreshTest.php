<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Jobs\FetchImapAccount;
use App\Modules\Email\Jobs\RefreshEmailProviderDraftFolder;
use App\Modules\Email\Jobs\StoreInboundMessage;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailProviderDraftSyncService;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailProviderDraftTargetedRefreshTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function manual_append_reinfers_the_exact_enabled_drafts_folder_and_ignores_a_stale_child_role(): void
    {
        Queue::fake();

        $account = $this->account('draft-folder-selection@example.test');
        $this->folder($account, 770, 794);
        EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'INBOX.Drafts.Client',
            'name' => 'Client',
            'delimiter' => '.',
            'role' => EmailFolder::ROLE_DRAFTS,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 771,
            'uid_next' => 10,
            'live_start_uid' => 9,
            'sync_status' => EmailFolder::SYNC_SYNCED,
            'last_discovered_at' => now()->subMinute(),
            'last_synced_at' => now()->subMinute(),
        ]);
        EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Provider Draft Store',
            'name' => 'Provider Draft Store',
            'special_use' => '\\Drafts',
            'role' => EmailFolder::ROLE_DRAFTS,
            'is_selectable' => true,
            'sync_enabled' => false,
            'uid_validity' => 772,
            'uid_next' => 10,
            'live_start_uid' => 9,
            'sync_status' => EmailFolder::SYNC_SYNCED,
            'last_discovered_at' => now()->subMinute(),
            'last_synced_at' => now()->subMinute(),
        ]);
        $canonical = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Drafts',
            'name' => 'Drafts',
            'delimiter' => '.',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 880,
            'uid_next' => 20,
            'live_start_uid' => 19,
            'sync_status' => EmailFolder::SYNC_SYNCED,
            'last_discovered_at' => now()->subMinute(),
            'last_synced_at' => now()->subMinute(),
        ]);
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $draft = $this->draft($account, $canonical, $actor, '<folder-selection@example.test>');
        $draft->forceFill([
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_LOCAL_ONLY,
            'provider_draft_folder_path' => null,
            'provider_draft_uid_validity' => null,
            'provider_draft_uid' => null,
            'provider_draft_message_id' => null,
            'provider_draft_normalized_message_id' => null,
        ])->save();
        $access = $this->createMock(MailboxAccess::class);
        $access->method('canAccessAccount')->willReturn(true);
        $client = new class($account) extends ImapClient
        {
            public ?string $appendedFolder = null;

            public function connect(): void {}

            public function disconnect(): void {}

            public function appendDraft(string $folderPath, string $message): array
            {
                $this->appendedFolder = $folderPath;

                return [
                    'ok' => true,
                    'folder_path' => $folderPath,
                    'imap_uid_validity' => 880,
                    'imap_uid' => 20,
                    'response' => ['OK Append completed'],
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $result = (new EmailProviderDraftSyncService($access))->sync($draft, $actor);

        $this->assertSame('Drafts', $client->appendedFolder);
        $this->assertSame('Drafts', $result->provider_draft_folder_path);
        $this->assertSame(EmailFolder::ROLE_DRAFTS, $canonical->fresh()->role);
        Queue::assertPushed(
            RefreshEmailProviderDraftFolder::class,
            fn (RefreshEmailProviderDraftFolder $job): bool => $job->folderId === $canonical->id,
        );
    }

    #[Test]
    public function successful_manual_append_queues_exact_folder_refresh_without_guessing_a_placement(): void
    {
        Queue::fake();

        $account = $this->account('targeted-dispatch@example.test');
        $folder = $this->folder($account, 770, 794);
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $draft = $this->draft($account, $folder, $actor, '<targeted-dispatch@example.test>');
        $draft->forceFill([
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_LOCAL_ONLY,
            'provider_draft_folder_path' => null,
            'provider_draft_uid_validity' => null,
            'provider_draft_uid' => null,
            'provider_draft_message_id' => null,
            'provider_draft_normalized_message_id' => null,
            'provider_draft_synced_at' => null,
        ])->save();
        $access = $this->createMock(MailboxAccess::class);
        $access->expects($this->once())
            ->method('canAccessAccount')
            ->willReturn(true);
        $client = new class($account) extends ImapClient
        {
            public function connect(): void {}

            public function disconnect(): void {}

            public function appendDraft(string $folderPath, string $message): array
            {
                return [
                    'ok' => true,
                    'folder_path' => $folderPath,
                    'imap_uid_validity' => 770,
                    'imap_uid' => 795,
                    'response' => ['OK Append completed'],
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $result = (new EmailProviderDraftSyncService($access))->sync($draft, $actor);

        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_SYNCED, $result->provider_draft_status);
        $this->assertSame(795, $result->provider_draft_uid);
        $this->assertDatabaseCount('email_messages', 0);
        $this->assertDatabaseCount('email_mailbox_placements', 0);
        Queue::assertPushed(
            RefreshEmailProviderDraftFolder::class,
            fn (RefreshEmailProviderDraftFolder $job): bool => $job->draftId === $draft->id
                && $job->accountId === $account->id
                && $job->folderId === $folder->id
                && $job->batchSize === RefreshEmailProviderDraftFolder::DEFAULT_BATCH_SIZE,
        );
    }

    #[Test]
    public function targeted_refresh_uses_folder_cursor_and_projects_only_the_matching_provider_message(): void
    {
        $account = $this->account('targeted-project@example.test');
        $folder = $this->folder($account, 770, 794);
        $draft = $this->draft($account, $folder, null, '<targeted-project@example.test>');
        $otherPayload = $this->providerPayload(795, '<other-client-draft@example.test>', 'Other client draft');
        $matchingPayload = $this->providerPayload(796, '<targeted-project@example.test>', 'Saved Nexum draft');
        $client = new class($account, [$otherPayload, $matchingPayload]) extends ImapClient
        {
            public bool $connected = false;

            public bool $disconnected = false;

            /** @var array<int, array<string, mixed>> */
            public array $payloads;

            /** @var array{path: string, uid: int, limit: int}|null */
            public ?array $fetch = null;

            /** @param array<int, array<string, mixed>> $payloads */
            public function __construct(EmailAccount $account, array $payloads)
            {
                parent::__construct($account);
                $this->payloads = $payloads;
            }

            public function connect(): void
            {
                $this->connected = true;
            }

            public function disconnect(): void
            {
                $this->disconnected = true;
            }

            public function folderState(string $folderPath): array
            {
                return [
                    'uid_validity' => 770,
                    'next_uid' => 797,
                    'exists_count' => 3,
                    'unseen_count' => 0,
                    'highest_modseq' => 45,
                ];
            }

            public function fetchAfterUidInFolder(string $folderPath, int $uid, int $limit = 20): array
            {
                $this->fetch = ['path' => $folderPath, 'uid' => $uid, 'limit' => $limit];

                return $this->payloads;
            }
        };
        $job = new class($draft->id, $account->id, $folder->id, 500, $client) extends RefreshEmailProviderDraftFolder
        {
            public function __construct(
                int $draftId,
                int $accountId,
                int $folderId,
                int $batchSize,
                private readonly ImapClient $client,
            ) {
                parent::__construct($draftId, $accountId, $folderId, $batchSize);
            }

            protected function makeImapClient(EmailAccount $account): ImapClient
            {
                return $this->client;
            }
        };

        $job->handle(app(EmailProviderDraftSyncService::class));

        $this->assertTrue($client->connected);
        $this->assertTrue($client->disconnected);
        $this->assertSame([
            'path' => 'INBOX.Drafts',
            'uid' => 794,
            'limit' => RefreshEmailProviderDraftFolder::MAX_BATCH_SIZE,
        ], $client->fetch);
        $this->assertDatabaseMissing('email_messages', [
            'account_id' => $account->id,
            'mailbox' => 'INBOX.Drafts',
            'imap_uid' => 795,
        ]);
        $message = EmailMessage::query()
            ->where('account_id', $account->id)
            ->where('mailbox', 'INBOX.Drafts')
            ->where('imap_uid', 796)
            ->sole();
        $placement = EmailMailboxPlacement::query()
            ->where('email_message_id', $message->id)
            ->sole();

        $this->assertTrue($placement->provider_draft);
        $this->assertSame($folder->id, $placement->email_folder_id);
        $this->assertSame(770, $placement->imap_uid_validity);
        $this->assertSame(796, $placement->imap_uid);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->local_state);
        $this->assertSame(EmailMailboxPlacement::SYNC_SYNCED, $placement->sync_status);
        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_SYNCED, $draft->fresh()->provider_draft_status);
        $this->assertSame(796, $draft->fresh()->provider_draft_uid);
        $this->assertSame(797, $folder->fresh()->uid_next);
        $this->assertSame(EmailFolder::SYNC_SYNCED, $folder->fresh()->sync_status);
    }

    #[Test]
    public function targeted_refresh_miss_does_not_downgrade_a_draft_already_reconciled_by_normal_sync(): void
    {
        $account = $this->account('targeted-monotonic@example.test');
        $folder = $this->folder($account, 770, 794);
        $draft = $this->draft($account, $folder, null, '<targeted-monotonic@example.test>');
        $draft->forceFill([
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_PENDING,
            'provider_draft_uid_validity' => null,
            'provider_draft_uid' => null,
        ])->save();

        StoreInboundMessage::dispatchSync(array_merge(
            $this->providerPayload(795, '<targeted-monotonic@example.test>', 'Normal sync won the race'),
            [
                'account_id' => $account->id,
                'mailbox' => $folder->path,
                'uid_validity' => 770,
                'email_folder_id' => $folder->id,
                'is_oversize' => false,
                'run_inbound_rules' => false,
            ],
        ));

        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_SYNCED, $draft->fresh()->provider_draft_status);
        $this->assertSame(795, $draft->fresh()->provider_draft_uid);

        $client = new class($account) extends ImapClient
        {
            public ?int $afterUid = null;

            public function connect(): void {}

            public function disconnect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 770, 'next_uid' => 796];
            }

            public function fetchAfterUidInFolder(string $folderPath, int $uid, int $limit = 20): array
            {
                $this->afterUid = $uid;

                return [];
            }
        };
        $job = new class($draft->id, $account->id, $folder->id, 20, $client) extends RefreshEmailProviderDraftFolder
        {
            public function __construct(
                int $draftId,
                int $accountId,
                int $folderId,
                int $batchSize,
                private readonly ImapClient $client,
            ) {
                parent::__construct($draftId, $accountId, $folderId, $batchSize);
            }

            protected function makeImapClient(EmailAccount $account): ImapClient
            {
                return $this->client;
            }
        };

        $job->handle(app(EmailProviderDraftSyncService::class));

        $this->assertSame(795, $client->afterUid);
        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_SYNCED, $draft->fresh()->provider_draft_status);
        $this->assertSame(795, $draft->fresh()->provider_draft_uid);
    }

    #[Test]
    public function lost_append_response_leaves_a_durable_guard_and_a_second_save_does_not_append_again(): void
    {
        Queue::fake();

        $account = $this->account('draft-append-ambiguous@example.test');
        $folder = $this->folder($account, 770, 794);
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $draft = $this->draft($account, $folder, $actor, '<old-draft@example.test>');
        $draft->forceFill([
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_LOCAL_ONLY,
            'provider_draft_folder_path' => null,
            'provider_draft_uid_validity' => null,
            'provider_draft_uid' => null,
            'provider_draft_message_id' => null,
            'provider_draft_normalized_message_id' => null,
        ])->save();
        $access = $this->createMock(MailboxAccess::class);
        $access->expects($this->exactly(2))->method('canAccessAccount')->willReturn(true);
        $client = new class($account) extends ImapClient
        {
            public int $connections = 0;

            public int $appends = 0;

            public function connect(): void
            {
                $this->connections++;
            }

            public function disconnect(): void {}

            public function appendDraft(string $folderPath, string $message): array
            {
                $this->appends++;

                throw new \RuntimeException('socket dropped after provider write');
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);
        $service = new EmailProviderDraftSyncService($access);

        $first = $service->sync($draft, $actor);
        $second = $service->sync($first, $actor);

        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_ERROR, $first->provider_draft_status);
        $this->assertSame(
            EmailComposerDraft::PROVIDER_DRAFT_APPEND_OUTCOME_UNRESOLVED,
            $first->provider_draft_error_code,
        );
        $this->assertSame($first->provider_draft_message_id, $second->provider_draft_message_id);
        $this->assertSame('INBOX.Drafts', $second->provider_draft_folder_path);
        $this->assertNull($second->provider_draft_uid);
        $this->assertSame(1, $client->connections);
        $this->assertSame(1, $client->appends);
        $this->assertStringNotContainsString('socket dropped', (string) $second->provider_draft_error_message);
        Queue::assertPushed(RefreshEmailProviderDraftFolder::class);
    }

    #[Test]
    public function connection_failure_before_append_is_retryable_without_creating_two_provider_copies(): void
    {
        Queue::fake();

        $account = $this->account('draft-append-prewrite@example.test');
        $folder = $this->folder($account, 770, 794);
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $draft = $this->draft($account, $folder, $actor, '<old-prewrite@example.test>');
        $draft->forceFill([
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_LOCAL_ONLY,
            'provider_draft_folder_path' => null,
            'provider_draft_uid_validity' => null,
            'provider_draft_uid' => null,
            'provider_draft_message_id' => null,
            'provider_draft_normalized_message_id' => null,
        ])->save();
        $access = $this->createMock(MailboxAccess::class);
        $access->expects($this->exactly(2))->method('canAccessAccount')->willReturn(true);
        $client = new class($account) extends ImapClient
        {
            public int $connections = 0;

            public int $appends = 0;

            public function connect(): void
            {
                $this->connections++;

                if ($this->connections === 1) {
                    throw new \RuntimeException('provider unavailable before append');
                }
            }

            public function disconnect(): void {}

            public function appendDraft(string $folderPath, string $message): array
            {
                $this->appends++;

                return [
                    'ok' => true,
                    'folder_path' => $folderPath,
                    'imap_uid_validity' => 770,
                    'imap_uid' => 795,
                    'response' => ['OK Append completed'],
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);
        $service = new EmailProviderDraftSyncService($access);

        $first = $service->sync($draft, $actor);
        $reservedMessageId = $first->provider_draft_message_id;
        $second = $service->sync($first, $actor);

        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_ERROR, $first->provider_draft_status);
        $this->assertSame(
            EmailComposerDraft::PROVIDER_DRAFT_APPEND_PREWRITE_FAILED,
            $first->provider_draft_error_code,
        );
        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_SYNCED, $second->provider_draft_status);
        $this->assertSame($reservedMessageId, $second->provider_draft_message_id);
        $this->assertSame(2, $client->connections);
        $this->assertSame(1, $client->appends);
    }

    #[Test]
    public function a_fresh_append_reservation_blocks_concurrency_but_a_stale_prewrite_reservation_is_recoverable(): void
    {
        Queue::fake();

        $account = $this->account('draft-append-reservation@example.test');
        $folder = $this->folder($account, 770, 794);
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $draft = $this->draft($account, $folder, $actor, '<reserved-draft@example.test>');
        $draft->forceFill([
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVED,
            'provider_draft_folder_path' => null,
            'provider_draft_uid_validity' => null,
            'provider_draft_uid' => null,
            'provider_draft_message_id' => '<reserved-draft@example.test>',
            'provider_draft_normalized_message_id' => 'reserved-draft@example.test',
            'provider_draft_error_code' => EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVATION
                .':'.now()->getTimestamp().':1111111111111111',
            'provider_draft_error_message' => null,
        ])->save();
        $access = $this->createMock(MailboxAccess::class);
        $access->expects($this->exactly(2))->method('canAccessAccount')->willReturn(true);
        $client = new class($account) extends ImapClient
        {
            public int $connections = 0;

            public int $appends = 0;

            public function connect(): void
            {
                $this->connections++;
            }

            public function disconnect(): void {}

            public function appendDraft(string $folderPath, string $message): array
            {
                $this->appends++;

                return [
                    'ok' => true,
                    'folder_path' => $folderPath,
                    'imap_uid_validity' => 770,
                    'imap_uid' => 795,
                    'response' => ['OK Append completed'],
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);
        $service = new EmailProviderDraftSyncService($access);

        $freshReservation = $service->sync($draft, $actor);

        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVED, $freshReservation->provider_draft_status);
        $this->assertSame(0, $client->connections);
        $this->assertSame(0, $client->appends);

        $freshReservation->forceFill([
            'provider_draft_error_code' => EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVATION
                .':'.now()->subMinutes(6)->getTimestamp().':1111111111111111',
        ])->save();
        $recovered = $service->sync($freshReservation->refresh(), $actor);

        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_SYNCED, $recovered->provider_draft_status);
        $this->assertSame('<reserved-draft@example.test>', $recovered->provider_draft_message_id);
        $this->assertSame(1, $client->connections);
        $this->assertSame(1, $client->appends);
    }

    #[Test]
    public function unresolved_append_without_a_uid_is_not_misreported_as_provider_cleanup(): void
    {
        $account = $this->account('draft-unresolved-cleanup@example.test');
        $folder = $this->folder($account, 770, 794);
        $draft = $this->draft($account, $folder, null, '<unresolved-cleanup@example.test>');
        $draft->forceFill([
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_ERROR,
            'provider_draft_uid_validity' => null,
            'provider_draft_uid' => null,
            'provider_draft_error_code' => EmailComposerDraft::PROVIDER_DRAFT_APPEND_OUTCOME_UNRESOLVED,
            'provider_draft_error_message' => 'The provider append outcome is unresolved.',
        ])->save();
        $access = $this->createMock(MailboxAccess::class);
        $client = new class($account) extends ImapClient
        {
            public bool $connected = false;

            public function connect(): void
            {
                $this->connected = true;
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $result = (new EmailProviderDraftSyncService($access))->delete($draft);

        $this->assertFalse($client->connected);
        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_ERROR, $result->provider_draft_status);
        $this->assertSame(
            EmailComposerDraft::PROVIDER_DRAFT_APPEND_OUTCOME_UNRESOLVED,
            $result->provider_draft_error_code,
        );
        $this->assertNull($result->provider_draft_deleted_at);
    }

    #[Test]
    public function provider_cleanup_failure_stores_a_stable_message_instead_of_raw_provider_diagnostics(): void
    {
        $account = $this->account('draft-cleanup-safe-error@example.test');
        $folder = $this->folder($account, 770, 794);
        $draft = $this->draft($account, $folder, null, '<cleanup-safe-error@example.test>');
        $access = $this->createMock(MailboxAccess::class);
        $client = new class($account) extends ImapClient
        {
            public function connect(): void {}

            public function disconnect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 770];
            }

            public function deleteByUid(int $uid, string $folderPath = 'INBOX'): bool
            {
                throw new \RuntimeException('secret provider diagnostic');
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $result = (new EmailProviderDraftSyncService($access))->delete($draft);

        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_ERROR, $result->provider_draft_status);
        $this->assertSame('PROVIDER_DRAFT_DELETE_FAILED', $result->provider_draft_error_code);
        $this->assertStringNotContainsString('secret provider diagnostic', (string) $result->provider_draft_error_message);
        $this->assertSame(
            'The provider draft copy could not be removed. Nexum kept the cleanup issue for review.',
            $result->provider_draft_error_message,
        );
    }

    #[Test]
    public function targeted_refresh_fails_closed_when_the_folder_uid_namespace_changed(): void
    {
        $account = $this->account('targeted-uidvalidity@example.test');
        $folder = $this->folder($account, 770, 794);
        $draft = $this->draft($account, $folder, null, '<targeted-uidvalidity@example.test>');
        $client = new class($account) extends ImapClient
        {
            public bool $fetched = false;

            public function connect(): void {}

            public function disconnect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 771, 'next_uid' => 796];
            }

            public function fetchAfterUidInFolder(string $folderPath, int $uid, int $limit = 20): array
            {
                $this->fetched = true;

                return [];
            }
        };
        $job = new class($draft->id, $account->id, $folder->id, 20, $client) extends RefreshEmailProviderDraftFolder
        {
            public function __construct(
                int $draftId,
                int $accountId,
                int $folderId,
                int $batchSize,
                private readonly ImapClient $client,
            ) {
                parent::__construct($draftId, $accountId, $folderId, $batchSize);
            }

            protected function makeImapClient(EmailAccount $account): ImapClient
            {
                return $this->client;
            }
        };

        $job->handle(app(EmailProviderDraftSyncService::class));

        $this->assertFalse($client->fetched);
        $this->assertDatabaseCount('email_messages', 0);
        $this->assertDatabaseCount('email_mailbox_placements', 0);
        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_ERROR, $draft->fresh()->provider_draft_status);
        $this->assertSame('PROVIDER_DRAFT_UIDVALIDITY_CHANGED', $draft->fresh()->provider_draft_error_code);
        $this->assertSame(EmailFolder::SYNC_ERROR, $folder->fresh()->sync_status);
        $this->assertSame('PROVIDER_DRAFT_UIDVALIDITY_CHANGED', $folder->fresh()->sync_error_code);
    }

    #[Test]
    public function targeted_refresh_is_exactly_scoped_and_shares_the_account_poll_overlap_lock(): void
    {
        $account = $this->account('targeted-scope@example.test');
        $otherAccount = $this->account('targeted-scope-other@example.test');
        $folder = $this->folder($account, 770, 794);
        $draft = $this->draft($account, $folder, null, '<targeted-scope@example.test>');
        $job = new class($draft->id, $otherAccount->id, $folder->id) extends RefreshEmailProviderDraftFolder
        {
            protected function makeImapClient(EmailAccount $account): ImapClient
            {
                throw new \RuntimeException('A cross-account target must not create an IMAP client.');
            }
        };

        $job->handle(app(EmailProviderDraftSyncService::class));

        $this->assertDatabaseCount('email_messages', 0);
        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_SYNCED, $draft->fresh()->provider_draft_status);
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame(
            'email-provider-draft-refresh:'.$otherAccount->id.':'.$folder->id.':'.$draft->id.':1',
            $job->uniqueId(),
        );

        $targetedLock = $job->middleware()[0];
        $accountLock = (new FetchImapAccount($otherAccount->id))->middleware()[0];
        $this->assertInstanceOf(WithoutOverlapping::class, $targetedLock);
        $this->assertTrue($targetedLock->shareKey);
        $this->assertTrue($accountLock->shareKey);
        $this->assertSame($targetedLock->getLockKey($job), $accountLock->getLockKey($job));
    }

    private function account(string $address): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Targeted provider Drafts refresh test',
            'from_name' => 'Nexum Draft Test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'provider_binding_version' => 1,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'targeted-refresh-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'targeted-refresh-secret',
            'smtp_auth_type' => 'password',
        ]);
    }

    private function folder(EmailAccount $account, int $uidValidity, int $liveStartUid): EmailFolder
    {
        return EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'INBOX.Drafts',
            'name' => 'Drafts',
            'role' => EmailFolder::ROLE_DRAFTS,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $uidValidity,
            'uid_next' => $liveStartUid + 1,
            'live_start_uid' => $liveStartUid,
            'sync_status' => EmailFolder::SYNC_SYNCED,
            'last_discovered_at' => now()->subMinute(),
            'last_synced_at' => now()->subMinute(),
        ]);
    }

    private function draft(
        EmailAccount $account,
        EmailFolder $folder,
        ?User $user,
        string $messageId,
    ): EmailComposerDraft {
        $user ??= User::factory()->create(['status' => User::STATUS_ACTIVE]);

        return EmailComposerDraft::query()->create([
            'user_id' => $user->id,
            'email_account_id' => $account->id,
            'provider_binding_version' => (int) $account->provider_binding_version,
            'mode' => 'new',
            'draft_key' => 'targeted-provider-draft-'.$user->id.'-'.$account->id,
            'status' => EmailComposerDraft::STATUS_ACTIVE,
            'to_recipients' => 'customer@example.test',
            'subject' => 'Targeted provider draft',
            'body_html' => '<p>Targeted provider draft body.</p>',
            'body_text' => 'Targeted provider draft body.',
            'idempotency_key' => 'targeted-provider-draft-'.$user->id.'-'.$account->id,
            'last_saved_at' => now(),
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_SYNCED,
            'provider_draft_folder_path' => $folder->path,
            'provider_draft_uid_validity' => $folder->uid_validity,
            'provider_draft_uid' => $folder->live_start_uid + 1,
            'provider_draft_message_id' => $messageId,
            'provider_draft_normalized_message_id' => trim(mb_strtolower($messageId), '<>'),
            'provider_draft_synced_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function providerPayload(int $uid, string $messageId, string $subject): array
    {
        return [
            'mailbox' => 'INBOX.Drafts',
            'imap_uid' => $uid,
            'message_id' => $messageId,
            'subject' => $subject,
            'from_name' => 'Nexum Draft Test',
            'from_email' => 'targeted-project@example.test',
            'to' => [['email' => 'customer@example.test', 'name' => 'Customer']],
            'cc' => [],
            'headers' => [],
            'in_reply_to' => null,
            'references' => '',
            'received_at' => now(),
            'size_bytes' => 30 * 1024 * 1024,
            'flags' => ['Draft'],
            'provider_seen' => false,
            'provider_answered' => false,
            'provider_flagged' => false,
            'provider_deleted' => false,
            'provider_draft' => true,
        ];
    }
}
