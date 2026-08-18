<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\Jobs\DispatchEmailProviderIdleListeners;
use App\Modules\Email\Jobs\ListenForEmailProviderChanges;
use App\Modules\Email\Models\EmailAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class EmailProviderIdleDispatchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function one_failed_account_does_not_starve_a_serialized_second_page(): void
    {
        Cache::flush();
        $accounts = $this->accounts(
            DispatchEmailProviderIdleListeners::ACCOUNT_PAGE_SIZE + 2,
            'idle-dispatch-failure',
        );
        $failedAccountId = (int) $accounts[9]->id;
        $this->fakeQueueFailingFor($failedAccountId);

        $firstPage = new DispatchEmailProviderIdleListeners;
        app()->call([$firstPage, 'handle']);

        $firstPageIds = $accounts
            ->take(DispatchEmailProviderIdleListeners::ACCOUNT_PAGE_SIZE)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === $failedAccountId)
            ->values()
            ->all();
        $this->assertSame($firstPageIds, $this->listenerAccountIds());
        $this->assertNotContains((int) $accounts->last()->id, $this->listenerAccountIds());

        $lastFirstPageId = (int) $accounts
            ->take(DispatchEmailProviderIdleListeners::ACCOUNT_PAGE_SIZE)
            ->last()
            ->id;
        $successor = Queue::pushed(DispatchEmailProviderIdleListeners::class)
            ->first(fn (DispatchEmailProviderIdleListeners $job): bool => $job->afterAccountId === $lastFirstPageId);
        $this->assertInstanceOf(DispatchEmailProviderIdleListeners::class, $successor);
        $this->assertSame('email-idle', $successor->queue);
        $this->assertSame(
            'email-provider-idle-dispatch:after:'.$lastFirstPageId,
            $successor->uniqueId(),
        );

        $serialized = unserialize(serialize($successor));
        $this->assertInstanceOf(DispatchEmailProviderIdleListeners::class, $serialized);
        $this->assertSame($lastFirstPageId, $serialized->afterAccountId);

        app()->call([$serialized, 'handle']);

        $this->assertContains((int) $accounts->last()->id, $this->listenerAccountIds());
        Queue::assertPushed(DispatchEmailProviderIdleListeners::class, 1);
    }

    #[Test]
    public function redelivery_remains_one_page_and_unique_listeners_suppress_duplicate_socket_jobs(): void
    {
        Cache::flush();
        Queue::fake();
        $accounts = $this->accounts(
            DispatchEmailProviderIdleListeners::ACCOUNT_PAGE_SIZE + 2,
            'idle-dispatch-redelivery',
        );
        $firstPage = new DispatchEmailProviderIdleListeners;

        app()->call([$firstPage, 'handle']);
        app()->call([$firstPage, 'handle']);

        $this->assertSame(
            $accounts
                ->take(DispatchEmailProviderIdleListeners::ACCOUNT_PAGE_SIZE)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            $this->listenerAccountIds(),
        );
        Queue::assertPushed(
            ListenForEmailProviderChanges::class,
            DispatchEmailProviderIdleListeners::ACCOUNT_PAGE_SIZE,
        );
        Queue::assertPushed(DispatchEmailProviderIdleListeners::class, 1);

        $successor = Queue::pushed(DispatchEmailProviderIdleListeners::class)->firstOrFail();
        app()->call([$successor, 'handle']);

        $this->assertSame(
            $accounts->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            $this->listenerAccountIds(),
        );
        $this->assertSame(
            $accounts->count(),
            Queue::pushed(ListenForEmailProviderChanges::class)
                ->map(fn (ListenForEmailProviderChanges $job): string => $job->uniqueId())
                ->unique()
                ->count(),
        );
        Queue::assertPushed(DispatchEmailProviderIdleListeners::class, 1);
    }

    /** @return \Illuminate\Support\Collection<int, EmailAccount> */
    private function accounts(int $count, string $prefix)
    {
        $now = now();
        $secret = encrypt('test-secret');
        $rows = collect(range(1, $count))->map(fn (int $number): array => [
            'address' => "{$prefix}-{$number}@example.test",
            'from_name' => 'IDLE Dispatch Test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'provider_credential_source' => 'legacy',
            'provider_binding_version' => 1,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => "{$prefix}-{$number}@example.test",
            'imap_secret' => $secret,
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => "{$prefix}-{$number}@example.test",
            'smtp_secret' => $secret,
            'smtp_auth_type' => 'password',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('email_accounts')->insert($rows->all());

        return EmailAccount::query()
            ->where('address', 'like', $prefix.'-%')
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, int> */
    private function listenerAccountIds(): array
    {
        return Queue::pushed(ListenForEmailProviderChanges::class)
            ->pluck('accountId')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    private function fakeQueueFailingFor(int $accountId): void
    {
        $realQueue = Queue::getFacadeRoot();
        $fake = new class($this->app, $realQueue, $accountId) extends QueueFake
        {
            public function __construct(
                $app,
                QueueManager $queue,
                private readonly int $failedAccountId,
            ) {
                parent::__construct($app, [], $queue);
            }

            public function push($job, $data = '', $queue = null)
            {
                if ($job instanceof ListenForEmailProviderChanges
                    && $job->accountId === $this->failedAccountId) {
                    throw new RuntimeException('simulated per-account listener queue failure');
                }

                return parent::push($job, $data, $queue);
            }
        };

        Queue::swap($fake);
    }
}
