<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Domain\Email\Jobs\PollActiveEmailAccounts;
use App\Domain\Email\Jobs\EmailAccountHealthCheckJob;
use App\Domain\Email\Jobs\EmailRetentionPurgeJob;
use App\Domain\Email\Models\EmailAccount;
use App\Domain\Email\Jobs\FetchImapAccount;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Email polling every minute
Schedule::job(new PollActiveEmailAccounts())
    ->everyMinute()
    ->name('email.poll')
    ->withoutOverlapping();

// Email account health check every five minutes
Schedule::call(function () {
    EmailAccount::query()
        ->where('is_active', true)
        ->chunkById(50, function ($accounts) {
            foreach ($accounts as $account) {
                EmailAccountHealthCheckJob::dispatch($account->id);
            }
        });
})->everyFiveMinutes()
  ->name('email.health');

// Monthly retention purge (default 24 months)
Schedule::job(new EmailRetentionPurgeJob(24))
        ->monthlyOn(1, '03:00')
        ->name('email.retention.purge');

// Manual polling via CLI: php artisan email:poll [--account=ID] [--async]
Artisan::command('email:poll {--account=} {--async}', function () {
    $accountId = $this->option('account');
    $async = (bool) $this->option('async');

    $query = EmailAccount::query()->where('is_active', true);
    if (!empty($accountId)) {
        $query->whereKey($accountId);
    }

    $accounts = $query->get();
    if ($accounts->isEmpty()) {
        $this->info('No active accounts to poll.');
        return 0;
    }

    $count = 0;
    foreach ($accounts as $account) {
        if ($async) {
            FetchImapAccount::dispatch($account->id)->onQueue('email');
        } else {
            FetchImapAccount::dispatchSync($account->id);
        }
        $count++;
    }

    $this->info(($async ? 'Queued poll for ' : 'Checked now for ') . $count . ' account' . ($count>1?'s':''));
    return 0;
})->purpose('Fetch new mail for active accounts (optionally one account)');
