<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class EmailSystemHealth
{
    /**
     * Build the operational health snapshot shown on the Email Configuration page.
     */
    public function snapshot(array $config): array
    {
        $activeAccounts = EmailAccount::query()
            ->where('is_active', true)
            ->orderBy('address')
            ->get([
                'id',
                'address',
                'last_successful_fetch_at',
                'last_error_code',
                'last_error_message',
            ]);

        $pollInterval = max(1, (int) ($config['poll_interval'] ?? 1));
        $items = [
            $this->activeAccountsItem($activeAccounts),
            $this->ingestItem($config),
            $this->schedulerItem($pollInterval),
            $this->lastFetchItem($activeAccounts, $pollInterval),
            $this->accountErrorsItem($activeAccounts),
            $this->queueItem(),
        ];

        return [
            'overall' => $this->worstStatus($items),
            'items' => $items,
        ];
    }

    private function activeAccountsItem(Collection $activeAccounts): array
    {
        $count = $activeAccounts->count();

        if ($count === 0) {
            return $this->item(
                'Active accounts',
                'error',
                'No active email accounts are enabled for automatic polling.',
                '0 active',
            );
        }

        return $this->item(
            'Active accounts',
            'ok',
            $count === 1 ? '1 active email account can be polled.' : "{$count} active email accounts can be polled.",
            "{$count} active",
        );
    }

    private function ingestItem(array $config): array
    {
        $paused = (string) ($config['pause_ingest'] ?? '0') === '1';
        $pollInterval = max(1, (int) ($config['poll_interval'] ?? 1));
        $batchSize = max(1, (int) ($config['batch_size'] ?? 20));

        if ($paused) {
            return $this->item(
                'Ingest',
                'warning',
                "Ingest is paused. Scheduler runs will not queue fetch jobs. Policy: every {$pollInterval} min, batch {$batchSize}.",
                'Paused',
            );
        }

        return $this->item(
            'Ingest',
            'ok',
            "Polling policy: every {$pollInterval} min, batch {$batchSize}.",
            'Enabled',
        );
    }

    private function schedulerItem(int $pollInterval): array
    {
        $heartbeat = $this->cacheCarbon('email_last_poll_run');

        if (! $heartbeat) {
            return $this->item(
                'Scheduler poll',
                'error',
                'No email poll heartbeat has been recorded. Confirm cron runs schedule:run and the default queue worker is processing jobs.',
                'No heartbeat',
            );
        }

        $staleAfterMinutes = max(3, ($pollInterval * 2) + 1);

        if ($heartbeat->lt(now()->subMinutes($staleAfterMinutes))) {
            return $this->item(
                'Scheduler poll',
                'error',
                'Last email poll heartbeat was '.$heartbeat->diffForHumans().". Expected within {$staleAfterMinutes} minutes.",
                'Stale',
            );
        }

        return $this->item(
            'Scheduler poll',
            'ok',
            'Last email poll heartbeat was '.$heartbeat->diffForHumans().'.',
            'OK',
        );
    }

    private function lastFetchItem(Collection $activeAccounts, int $pollInterval): array
    {
        if ($activeAccounts->isEmpty()) {
            return $this->item(
                'Last fetch',
                'warning',
                'No active accounts exist, so there is no fetch activity to report.',
                'Unavailable',
            );
        }

        $latestFetch = $activeAccounts
            ->pluck('last_successful_fetch_at')
            ->filter()
            ->sortDesc()
            ->first();

        if (! $latestFetch) {
            return $this->item(
                'Last fetch',
                'warning',
                'No successful fetch has been recorded for any active account.',
                'Never',
            );
        }

        $staleAfterMinutes = max(15, $pollInterval * 5);

        if ($latestFetch->lt(now()->subMinutes($staleAfterMinutes))) {
            return $this->item(
                'Last fetch',
                'warning',
                'Latest successful fetch was '.$latestFetch->diffForHumans().". Expected within {$staleAfterMinutes} minutes during normal mail flow.",
                'Stale',
            );
        }

        return $this->item(
            'Last fetch',
            'ok',
            'Latest successful fetch was '.$latestFetch->diffForHumans().'.',
            'OK',
        );
    }

    private function accountErrorsItem(Collection $activeAccounts): array
    {
        $failedAccount = $activeAccounts->first(fn (EmailAccount $account): bool => filled($account->last_error_code) || filled($account->last_error_message));

        if (! $failedAccount) {
            return $this->item(
                'Account errors',
                'ok',
                'No active account has a stored IMAP/SMTP error.',
                'OK',
            );
        }

        $message = trim((string) ($failedAccount->last_error_message ?: $failedAccount->last_error_code));

        return $this->item(
            'Account errors',
            'error',
            "{$failedAccount->address}: {$message}",
            'Error',
        );
    }

    private function queueItem(): array
    {
        $connection = (string) config('queue.default', 'sync');
        $connectionConfig = (array) config("queue.connections.{$connection}", []);

        if (($connectionConfig['driver'] ?? null) !== 'database') {
            return $this->item(
                'Queue worker',
                'warning',
                "Queue monitoring is unavailable for the {$connection} queue driver. Database-backed jobs are required for this card.",
                'Unavailable',
            );
        }

        try {
            if (! Schema::hasTable('jobs') || ! Schema::hasTable('failed_jobs')) {
                return $this->item(
                    'Queue worker',
                    'warning',
                    'Queue monitoring is unavailable because the jobs or failed_jobs table does not exist.',
                    'Unavailable',
                );
            }

            $now = now()->timestamp;
            $staleCutoff = now()->subMinutes(5)->timestamp;

            $readyByQueue = DB::table('jobs')
                ->select('queue', DB::raw('count(*) as aggregate'))
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now)
                ->groupBy('queue')
                ->pluck('aggregate', 'queue')
                ->map(fn ($count): int => (int) $count)
                ->all();

            $reservedByQueue = DB::table('jobs')
                ->select('queue', DB::raw('count(*) as aggregate'))
                ->whereNotNull('reserved_at')
                ->groupBy('queue')
                ->pluck('aggregate', 'queue')
                ->map(fn ($count): int => (int) $count)
                ->all();

            $staleWatchedJobs = DB::table('jobs')
                ->select('queue', DB::raw('count(*) as aggregate'))
                ->whereIn('queue', ['default', 'email'])
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now)
                ->where('created_at', '<=', $staleCutoff)
                ->groupBy('queue')
                ->pluck('aggregate', 'queue')
                ->map(fn ($count): int => (int) $count)
                ->all();

            $failedJobs = (int) DB::table('failed_jobs')->count();
        } catch (Throwable $exception) {
            return $this->item(
                'Queue worker',
                'warning',
                'Queue monitoring is unavailable: '.$exception->getMessage(),
                'Unavailable',
            );
        }

        if ($failedJobs > 0) {
            return $this->item(
                'Queue worker',
                'error',
                $this->queueDetail($readyByQueue, $reservedByQueue, $staleWatchedJobs, $failedJobs),
                'Error',
            );
        }

        if (array_sum($staleWatchedJobs) > 0) {
            return $this->item(
                'Queue worker',
                'error',
                $this->queueDetail($readyByQueue, $reservedByQueue, $staleWatchedJobs, $failedJobs),
                'Stale jobs',
            );
        }

        return $this->item(
            'Queue worker',
            'ok',
            $this->queueDetail($readyByQueue, $reservedByQueue, $staleWatchedJobs, $failedJobs),
            'OK',
        );
    }

    private function queueDetail(array $readyByQueue, array $reservedByQueue, array $staleWatchedJobs, int $failedJobs): string
    {
        $parts = [
            'Ready: '.$this->queueCounts($readyByQueue),
            'Reserved: '.$this->queueCounts($reservedByQueue),
            'Failed: '.$failedJobs,
        ];

        if (array_sum($staleWatchedJobs) > 0) {
            $parts[] = 'Stale ready jobs on default/email: '.$this->queueCounts($staleWatchedJobs);
        }

        return implode('. ', $parts).'.';
    }

    private function queueCounts(array $counts): string
    {
        if ($counts === []) {
            return 'none';
        }

        ksort($counts);

        return collect($counts)
            ->map(fn (int $count, string $queue): string => "{$queue}={$count}")
            ->implode(', ');
    }

    private function cacheCarbon(string $key): ?Carbon
    {
        $value = Cache::get($key);

        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function item(string $label, string $status, string $detail, string $badge): array
    {
        return compact('label', 'status', 'detail', 'badge');
    }

    private function worstStatus(array $items): string
    {
        $rank = ['ok' => 0, 'warning' => 1, 'error' => 2];

        return collect($items)
            ->pluck('status')
            ->sortByDesc(fn (string $status): int => $rank[$status] ?? 1)
            ->first() ?? 'ok';
    }
}
