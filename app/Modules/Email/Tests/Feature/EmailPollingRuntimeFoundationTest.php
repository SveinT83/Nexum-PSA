<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Settings\CommonSetting;
use App\Modules\Email\Actions\DispatchEmailAccountPolling;
use App\Modules\Email\Jobs\FetchImapAccount;
use App\Modules\Email\Jobs\PollActiveEmailAccounts;
use App\Modules\Email\Jobs\StoreInboundMessage;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Support\EmailAccountProviderLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Tests\TestCase;

class EmailPollingRuntimeFoundationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function scheduler_and_async_command_use_the_same_all_active_account_dispatch_contract(): void
    {
        $first = $this->account('runtime-first@example.test');
        $second = $this->account('runtime-second@example.test');
        $this->account('runtime-disabled@example.test', active: false);

        Queue::fake();
        Cache::forget('email_last_poll_run');

        app()->call([new PollActiveEmailAccounts, 'handle']);

        $scheduledJobs = Queue::pushed(FetchImapAccount::class);
        $this->assertSame(
            [$first->id, $second->id],
            $scheduledJobs->pluck('accountId')->sort()->values()->all(),
        );
        $this->assertTrue($scheduledJobs->every(
            fn (FetchImapAccount $job): bool => $job->queue === 'email' && $job->batchSize === 20,
        ));

        Queue::fake();

        $exitCode = Artisan::call('email:poll', ['--async' => true]);

        $commandJobs = Queue::pushed(FetchImapAccount::class);
        $this->assertSame(0, $exitCode);
        $this->assertSame(
            $scheduledJobs->pluck('accountId')->sort()->values()->all(),
            $commandJobs->pluck('accountId')->sort()->values()->all(),
        );
        $this->assertTrue($commandJobs->every(
            fn (FetchImapAccount $job): bool => $job->queue === 'email' && $job->batchSize === 20,
        ));
        $this->assertStringContainsString('Queued poll for 2 accounts.', Artisan::output());
    }

    #[Test]
    public function command_preserves_an_optional_exact_active_account_scope(): void
    {
        $first = $this->account('runtime-exact-first@example.test');
        $second = $this->account('runtime-exact-second@example.test');
        Queue::fake();

        $exitCode = Artisan::call('email:poll', [
            '--account' => $second->id,
            '--async' => true,
        ]);

        $this->assertSame(0, $exitCode);
        Queue::assertPushed(FetchImapAccount::class, 1);
        Queue::assertPushed(
            FetchImapAccount::class,
            fn (FetchImapAccount $job): bool => $job->accountId === $second->id
                && $job->accountId !== $first->id
                && $job->queue === 'email',
        );
    }

    #[Test]
    public function operations_scheduled_tick_is_all_account_and_honors_the_ingest_pause(): void
    {
        $first = $this->account('runtime-scheduled-first@example.test');
        $second = $this->account('runtime-scheduled-second@example.test');
        Queue::fake();
        Cache::forget('email_last_poll_run');

        $exitCode = Artisan::call('email:poll', ['--scheduled' => true]);

        $this->assertSame(ConsoleCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('Scheduled all-account poll tick evaluated.', Artisan::output());
        Queue::assertPushed(FetchImapAccount::class, 2);
        Queue::assertPushed(
            FetchImapAccount::class,
            fn (FetchImapAccount $job): bool => in_array($job->accountId, [$first->id, $second->id], true)
                && $job->queue === 'email',
        );

        Queue::fake();
        Cache::forget('email_last_poll_run');
        CommonSetting::query()->updateOrCreate(
            ['type' => 'emailhub', 'name' => 'pause_ingest'],
            ['value' => '1'],
        );

        $this->assertSame(
            ConsoleCommand::SUCCESS,
            Artisan::call('email:poll', ['--scheduled' => true]),
        );
        Queue::assertNothingPushed();

        $this->assertSame(
            ConsoleCommand::INVALID,
            Artisan::call('email:poll', ['--scheduled' => true, '--account' => $first->id]),
        );
    }

    #[Test]
    public function synchronous_polling_persists_child_store_work_inside_the_parent_provider_lock(): void
    {
        $account = $this->account('runtime-synchronous-store@example.test');
        Bus::fake();

        $result = app(DispatchEmailAccountPolling::class)->handle(
            accountId: $account->id,
            batchSize: 20,
            asynchronously: false,
        );

        $this->assertSame(['matched' => 1, 'started' => 1, 'failed' => 0], $result);
        Bus::assertDispatchedSync(
            FetchImapAccount::class,
            fn (FetchImapAccount $job): bool => $job->accountId === $account->id
                && $job->syncStore,
        );
    }

    #[Test]
    public function command_rejects_malformed_or_nonpositive_account_scope(): void
    {
        $this->account('runtime-invalid-scope@example.test');
        Queue::fake();

        foreach (['abc', '-1', '0'] as $accountOption) {
            $exitCode = Artisan::call('email:poll', [
                '--account' => $accountOption,
                '--async' => true,
            ]);

            $this->assertSame(ConsoleCommand::INVALID, $exitCode);
            $this->assertStringContainsString(
                'The --account option must be a positive integer.',
                Artisan::output(),
            );
        }

        Queue::assertNothingPushed();
    }

    #[Test]
    public function overlap_release_has_a_bounded_retry_window_that_outlives_worker_attempt_limits(): void
    {
        $account = $this->account('runtime-overlap@example.test');
        $job = (new FetchImapAccount($account->id))->withFakeQueueInteractions();
        $middleware = $job->middleware()[0];

        $this->assertInstanceOf(WithoutOverlapping::class, $middleware);
        $this->assertTrue($middleware->shareKey);
        $this->assertSame(EmailAccountProviderLock::RELEASE_AFTER_SECONDS, $middleware->releaseAfter);
        $this->assertSame('email', $job->queue);

        $lock = Cache::lock($middleware->getLockKey($job), 180);
        $this->assertTrue($lock->get());
        $handled = false;

        try {
            $middleware->handle($job, function () use (&$handled): void {
                $handled = true;
            });
        } finally {
            $lock->release();
        }

        $this->assertFalse($handled);
        $job->assertReleased(EmailAccountProviderLock::RELEASE_AFTER_SECONDS);

        $nextJob = (new FetchImapAccount($account->id))->withFakeQueueInteractions();
        $nextJob->middleware()[0]->handle($nextJob, function () use (&$handled): void {
            $handled = true;
        });
        $this->assertTrue($handled);

        $payloadMethod = new ReflectionMethod(Queue::connection('sync'), 'createPayload');
        $payload = json_decode(
            $payloadMethod->invoke(Queue::connection('sync'), new FetchImapAccount($account->id), 'email'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(40, $payload['maxTries']);
        $this->assertSame(10, $payload['maxExceptions']);
        $this->assertSame('15,30,60', $payload['backoff']);
        $this->assertSame(120, $payload['timeout']);
        $this->assertGreaterThan(now()->addMinutes(9)->timestamp, $payload['retryUntil']);

        $sameAccountLock = (new FetchImapAccount($account->id))->middleware()[0];
        $otherAccountLock = (new FetchImapAccount($this->account('runtime-overlap-other@example.test')->id))->middleware()[0];
        $this->assertSame($middleware->getLockKey($job), $sameAccountLock->getLockKey($job));
        $this->assertNotSame($middleware->getLockKey($job), $otherAccountLock->getLockKey($job));
    }

    #[Test]
    public function queued_inbound_store_releases_normal_parent_fetch_lock_contention(): void
    {
        $account = $this->account('runtime-store-overlap@example.test');
        $job = (new StoreInboundMessage([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'uid_validity' => 101,
            'imap_uid' => 11,
        ]))->withFakeQueueInteractions();
        $lock = EmailAccountProviderLock::acquire($account->id, 60);
        $this->assertNotNull($lock);

        try {
            app()->call([$job, 'handle']);
        } finally {
            $lock?->release();
        }

        $job->assertReleased(EmailAccountProviderLock::RELEASE_AFTER_SECONDS);
        $this->assertSame('email', $job->queue);

        $payloadMethod = new ReflectionMethod(Queue::connection('sync'), 'createPayload');
        $payload = json_decode(
            $payloadMethod->invoke(Queue::connection('sync'), new StoreInboundMessage([
                'account_id' => $account->id,
                'mailbox' => 'INBOX',
                'uid_validity' => 101,
                'imap_uid' => 12,
            ]), 'email'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(40, $payload['maxTries']);
        $this->assertSame(10, $payload['maxExceptions']);
        $this->assertSame('15,30,60', $payload['backoff']);
        $this->assertSame(60, $payload['timeout']);
        $this->assertGreaterThan(now()->addMinutes(9)->timestamp, $payload['retryUntil']);
    }

    #[Test]
    public function selectable_folders_with_missing_discovery_state_use_exact_state_and_recover(): void
    {
        Queue::fake();
        $account = $this->account('runtime-folder-fallback@example.test', [
            'imap_uid_validity' => 101,
            'imap_live_start_uid' => 10,
            'last_successful_fetch_at' => now()->subDay(),
        ]);
        $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 101, 10);

        foreach (range(1, 53) as $number) {
            $this->folder(
                $account,
                sprintf('Provider/Folder-%02d', $number),
                EmailFolder::ROLE_CUSTOM,
                5300 + $number,
                10,
            );
        }

        $client = new class($account) extends ImapClient
        {
            /** @var array<int, string> */
            public array $stateRequests = [];

            /** @var array<int, string> */
            public array $fetchRequests = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function mailboxState(): array
            {
                return ['uid_validity' => 101, 'next_uid' => 11];
            }

            public function folders(): array
            {
                return [
                    [
                        'path' => 'INBOX',
                        'name' => 'INBOX',
                        'role' => EmailFolder::ROLE_INBOX,
                        'is_selectable' => true,
                        'sync_enabled' => true,
                        'uid_validity' => 101,
                        'uid_next' => 11,
                    ],
                    ...array_map(fn (int $number): array => [
                        'path' => sprintf('Provider/Folder-%02d', $number),
                        'name' => sprintf('Folder-%02d', $number),
                        'role' => EmailFolder::ROLE_CUSTOM,
                        'is_selectable' => true,
                        'sync_enabled' => true,
                        'uid_validity' => 0,
                        'uid_next' => 0,
                        'sync_status' => EmailFolder::SYNC_ERROR,
                        'sync_error_code' => 'IMAP_FOLDER_STATE',
                        'sync_error_message' => 'Folder state absent from discovery.',
                    ], range(1, 53)),
                ];
            }

            public function folderState(string $folderPath): array
            {
                $this->stateRequests[] = $folderPath;
                preg_match('/(\d+)$/', $folderPath, $matches);
                $number = (int) ($matches[1] ?? 0);

                return [
                    'uid_validity' => 5300 + $number,
                    'next_uid' => 11,
                    'exists_count' => 10,
                    'unseen_count' => 0,
                ];
            }

            public function fetchAfterUid(int $uid, int $limit = 20): array
            {
                return [];
            }

            public function fetchAfterUidInFolder(string $folderPath, int $uid, int $limit = 20): array
            {
                $this->fetchRequests[] = $folderPath;

                return [];
            }
        };

        $this->fetchJob($account, $client)->handle();

        $this->assertCount(53, $client->stateRequests);
        $this->assertCount(53, $client->fetchRequests);
        $this->assertSame(0, EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('sync_error_code', 'IMAP_FOLDER_STATE')
            ->count());
        $this->assertSame(53, EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('path', 'like', 'Provider/%')
            ->where('sync_status', EmailFolder::SYNC_SYNCED)
            ->count());
        $this->assertNotNull($account->fresh()->last_successful_fetch_at);
    }

    #[Test]
    public function exact_state_fallback_preserves_byte_distinct_trailing_space_paths(): void
    {
        Queue::fake();
        $account = $this->account('runtime-folder-byte-identity@example.test', [
            'imap_uid_validity' => 101,
            'imap_live_start_uid' => 10,
        ]);
        $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 101, 10);

        $client = new class($account) extends ImapClient
        {
            /** @var list<string> */
            public array $stateRequests = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function folders(): array
            {
                return [
                    ['path' => 'INBOX', 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 101, 'uid_next' => 11],
                    ['path' => 'Foo', 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 0, 'uid_next' => 0],
                    ['path' => 'Foo ', 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 0, 'uid_next' => 0],
                ];
            }

            public function folderState(string $folderPath): array
            {
                $this->stateRequests[] = $folderPath;

                return [
                    'uid_validity' => $folderPath === 'Foo ' ? 203 : 202,
                    'next_uid' => 1,
                ];
            }

            public function fetchAfterUid(int $uid, int $limit = 20): array
            {
                return [];
            }
        };

        $this->fetchJob($account, $client)->handle();

        $this->assertSame(['Foo', 'Foo '], $client->stateRequests);
        $this->assertDatabaseHas('email_folders', [
            'account_id' => $account->id,
            'path' => 'Foo',
            'uid_validity' => 202,
        ]);
        $this->assertDatabaseHas('email_folders', [
            'account_id' => $account->id,
            'path' => 'Foo ',
            'uid_validity' => 203,
        ]);
    }

    #[Test]
    public function exact_state_fallback_still_fails_closed_when_uidvalidity_changed(): void
    {
        Queue::fake();
        $account = $this->account('runtime-folder-uidvalidity@example.test', [
            'imap_uid_validity' => 101,
            'imap_live_start_uid' => 10,
            'last_successful_fetch_at' => now()->subDay(),
        ]);
        $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 101, 10);
        $changed = $this->folder($account, 'Provider/Changed', EmailFolder::ROLE_CUSTOM, 202, 50);

        $client = new class($account) extends ImapClient
        {
            public bool $changedFolderFetched = false;

            public function connect(): void {}

            public function disconnect(): void {}

            public function mailboxState(): array
            {
                return ['uid_validity' => 101, 'next_uid' => 11];
            }

            public function folders(): array
            {
                return [
                    ['path' => 'INBOX', 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 101, 'uid_next' => 11],
                    ['path' => 'Provider/Changed', 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 0, 'uid_next' => 0],
                ];
            }

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => 999, 'next_uid' => 55];
            }

            public function fetchAfterUid(int $uid, int $limit = 20): array
            {
                return [];
            }

            public function fetchAfterUidInFolder(string $folderPath, int $uid, int $limit = 20): array
            {
                $this->changedFolderFetched = true;

                return [];
            }
        };

        $this->fetchJob($account, $client)->handle();

        $changed->refresh();
        $this->assertFalse($client->changedFolderFetched);
        $this->assertSame(202, $changed->uid_validity);
        $this->assertSame(EmailFolder::SYNC_ERROR, $changed->sync_status);
        $this->assertSame('IMAP_UIDVALIDITY_CHANGED', $changed->sync_error_code);
    }

    #[Test]
    public function folder_high_water_and_dedup_ignore_uids_from_a_superseded_namespace(): void
    {
        Queue::fake();
        $account = $this->account('runtime-folder-namespace@example.test', [
            'imap_uid_validity' => 101,
            'imap_live_start_uid' => 10,
        ]);
        $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 101, 10);
        $folder = $this->folder($account, 'Provider/Namespace', EmailFolder::ROLE_CUSTOM, 555, 10);

        $oldHighWater = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => 444,
            'imap_uid' => 999,
            'message_id' => '<old-high-water@example.test>',
            'subject' => 'Old namespace high water',
            'from_email' => 'sender@example.test',
            'received_at' => now()->subDay(),
            'state' => 'untriaged',
        ]);
        EmailMailboxPlacement::query()->create([
            'email_message_id' => $oldHighWater->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => $folder->path,
            'imap_uid_validity' => 444,
            'imap_uid' => 999,
        ]);
        EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => 444,
            'imap_uid' => 11,
            'message_id' => '<old-same-uid@example.test>',
            'subject' => 'Old namespace same UID',
            'from_email' => 'sender@example.test',
            'received_at' => now()->subDay(),
            'state' => 'untriaged',
        ]);

        $client = new class($account) extends ImapClient
        {
            public ?int $namespaceHighWater = null;

            public function connect(): void {}

            public function disconnect(): void {}

            public function mailboxState(): array
            {
                return ['uid_validity' => 101, 'next_uid' => 11];
            }

            public function folders(): array
            {
                return [
                    ['path' => 'INBOX', 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 101, 'uid_next' => 11],
                    ['path' => 'Provider/Namespace', 'is_selectable' => true, 'sync_enabled' => true, 'uid_validity' => 555, 'uid_next' => 12],
                ];
            }

            public function fetchAfterUid(int $uid, int $limit = 20): array
            {
                return [];
            }

            public function fetchAfterUidInFolder(string $folderPath, int $uid, int $limit = 20): array
            {
                $this->namespaceHighWater = $uid;

                return [[
                    'mailbox' => $folderPath,
                    'imap_uid' => 11,
                    'message_id' => '<current-same-uid@example.test>',
                    'subject' => 'Current namespace same UID',
                    'from_email' => 'sender@example.test',
                    'to' => [],
                    'cc' => [],
                    'headers' => [],
                    'received_at' => now()->toDateTimeString(),
                    'size_bytes' => 100,
                ]];
            }
        };

        $this->fetchJob($account, $client)->handle();

        $this->assertSame(10, $client->namespaceHighWater);
        Queue::assertPushed(
            StoreInboundMessage::class,
            fn (StoreInboundMessage $job): bool => (int) $job->payload['uid_validity'] === 555
                && (int) $job->payload['imap_uid'] === 11,
        );
    }

    #[Test]
    public function provider_failures_store_and_throw_only_sanitized_polling_evidence(): void
    {
        $account = $this->account('runtime-sanitized@example.test');
        $client = new class($account) extends ImapClient
        {
            public function connect(): void
            {
                throw new RuntimeException('Authentication rejected; credential password=top-secret-token');
            }
        };
        $job = $this->fetchJob($account, $client);

        try {
            $job->handle();
            $this->fail('The sanitized polling exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('IMAP account polling could not connect.', $exception->getMessage());
        }

        $account->refresh();
        $this->assertSame('IMAP_AUTH', $account->last_error_code);
        $this->assertSame('IMAP authentication failed. Check the configured account credentials.', $account->last_error_message);
        $this->assertStringNotContainsString('top-secret-token', $account->last_error_message);

        $job->queuedAt = now()->subMinutes(11)->toIso8601String();
        $job->failed(new RuntimeException('password=another-secret'));
        $this->assertSame('IMAP_FETCH_RETRY_EXHAUSTED', $account->fresh()->last_error_code);
        $this->assertStringNotContainsString('another-secret', (string) $account->fresh()->last_error_message);
    }

    private function fetchJob(EmailAccount $account, ImapClient $client): FetchImapAccount
    {
        return new class($account->id, 20, false, $client) extends FetchImapAccount
        {
            public function __construct(
                int $accountId,
                int $batchSize,
                bool $syncStore,
                private readonly ImapClient $client,
            ) {
                parent::__construct($accountId, $batchSize, $syncStore);
            }

            protected function makeImapClient(EmailAccount $account): ImapClient
            {
                return $this->client;
            }
        };
    }

    /** @param array<string, mixed> $overrides */
    private function account(string $address, array $overrides = [], bool $active = true): EmailAccount
    {
        return EmailAccount::query()->create(array_merge([
            'address' => $address,
            'description' => 'Polling runtime foundation test',
            'from_name' => 'Nexum Runtime Test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => $active,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'runtime-test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'runtime-test-secret',
            'smtp_auth_type' => 'password',
        ], $overrides));
    }

    private function folder(
        EmailAccount $account,
        string $path,
        string $role,
        int $uidValidity,
        int $liveStartUid,
    ): EmailFolder {
        return EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => $path,
            'name' => basename($path),
            'role' => $role,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $uidValidity,
            'uid_next' => $liveStartUid + 1,
            'live_start_uid' => $liveStartUid,
            'sync_status' => EmailFolder::SYNC_BASELINED,
            'last_synced_at' => now()->subMinute(),
        ]);
    }
}
