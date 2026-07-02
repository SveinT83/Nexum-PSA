<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Modules\Email\Jobs\PollActiveEmailAccounts;
use App\Modules\Email\Jobs\EmailAccountHealthCheckJob;
use App\Modules\Email\Jobs\EmailRetentionPurgeJob;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Jobs\FetchImapAccount;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Integration\Jobs\PullBookStackToKnowledge;
use App\Modules\Integration\Jobs\CleanupAiChats;
use App\Modules\Integration\Services\AiChatCleanup;
use App\Modules\Economy\Jobs\GenerateEconomyOrdersJob;
use App\Modules\Contact\Actions\MigrateClientUsersToContacts;
use App\Modules\Marketing\Jobs\SendDueMarketingCampaignEmails;
use App\Jobs\Integrations\NAbleRmmSyncJob;

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

// N-able RMM Sync every hour
Schedule::job(new NAbleRmmSyncJob())
    ->hourly()
    ->name('integrations.nable_rmm.sync')
    ->withoutOverlapping();

// RMM Alert Sync every 15 minutes
Schedule::command('integrations:rmm-alert-sync')
    ->everyFifteenMinutes()
    ->name('integrations.rmm_alerts.sync')
    ->withoutOverlapping();

// Tactical RMM Sync every hour
Schedule::command('integrations:tactical-rmm-sync')
    ->hourly()
    ->name('integrations.tactical_rmm.sync')
    ->withoutOverlapping();

// BookStack Knowledge pull. The job checks the configured interval itself,
// defaulting to one pull per hour.
Schedule::job(new PullBookStackToKnowledge())
    ->everyMinute()
    ->name('integrations.book_stack.pull')
    ->withoutOverlapping();

// AI chat retention cleanup. Settings determine whether the job performs work.
Schedule::job(new CleanupAiChats())
    ->weeklyOn(1, '03:30')
    ->name('ai.chats.cleanup')
    ->withoutOverlapping();

// Economy order generation catch-up. Manual Generate orders uses the same
// action, while this keeps picked costs and closed-ticket time from piling up.
Schedule::job(new GenerateEconomyOrdersJob())
    ->dailyAt('02:15')
    ->name('economy.orders.generate')
    ->withoutOverlapping();

// Lead Intelligence schedule planner. The command owns due-segment decisions
// and dispatches queued research-run jobs for eligible segments.
Schedule::command('lead-intelligence:plan-due-runs')
    ->everyMinute()
    ->name('lead_intelligence.plan_due_runs')
    ->withoutOverlapping();

// Marketing campaign automation. Campaign settings and recipient due_at control
// whether this run performs work.
Schedule::job(new SendDueMarketingCampaignEmails())
    ->everyMinute()
    ->name('marketing.campaigns.send_due')
    ->withoutOverlapping();

Artisan::command('ai:cleanup-chats {--queue : Dispatch cleanup to the queue instead of running now}', function () {
    if ($this->option('queue')) {
        CleanupAiChats::dispatch();
        $this->info('AI chat cleanup queued.');

        return 0;
    }

    $summary = app(AiChatCleanup::class)->run();
    $this->info('AI chat cleanup completed: '.json_encode($summary));

    return 0;
})->purpose('Clean up AI chat sessions based on retention settings');

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

// Manual inbound rule processing: php artisan email:process-inbound-rules [--message=ID] [--limit=100] [--async]
Artisan::command('email:process-inbound-rules {--message=} {--limit=100} {--async}', function () {
    $messageId = $this->option('message');
    $limit = max(1, (int) $this->option('limit'));
    $async = (bool) $this->option('async');

    $query = EmailMessage::query()
        ->whereNull('ticket_id')
        ->orderBy('received_at');

    if (! empty($messageId)) {
        $query->whereKey($messageId);
    } else {
        $query->limit($limit);
    }

    $messageIds = $query->pluck('id');

    if ($messageIds->isEmpty()) {
        $this->info('No unlinked inbound email messages to process.');
        return 0;
    }

    foreach ($messageIds as $id) {
        if ($async) {
            ProcessInboundRules::dispatch($id)->onQueue('email');
        } else {
            ProcessInboundRules::dispatchSync($id);
        }
    }

    $this->info(($async ? 'Queued rules for ' : 'Processed rules for ') . $messageIds->count() . ' message' . ($messageIds->count() > 1 ? 's' : '') . '.');
    return 0;
})->purpose('Process stored inbound email messages through routing rules');

Artisan::command('contacts:migrate-client-users', function (MigrateClientUsersToContacts $migration) {
    $summary = $migration->handle();

    foreach ($summary as $key => $value) {
        $this->line(str_replace('_', ' ', $key).': '.$value);
    }

    return 0;
})->purpose('Create Contact records from legacy client_users and link compatibility records');

Artisan::command('marketing:send-due {--campaign=}', function () {
    $campaignId = $this->option('campaign') ? (int) $this->option('campaign') : null;

    SendDueMarketingCampaignEmails::dispatchSync($campaignId);

    $this->info('Due marketing campaign email processing completed.');

    return 0;
})->purpose('Send due marketing campaign emails through the configured marketing SMTP account');
