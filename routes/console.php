<?php

use App\Jobs\Integrations\NAbleRmmSyncJob;
use App\Modules\Contact\Actions\MigrateClientUsersToContacts;
use App\Modules\Economy\Jobs\GenerateEconomyOrdersJob;
use App\Modules\Email\Actions\DispatchEmailAccountPolling;
use App\Modules\Email\Jobs\CleanupEmailProviderDeletionCache;
use App\Modules\Email\Jobs\DispatchEmailProviderDeletionReconciliation;
use App\Modules\Email\Jobs\DispatchEmailProviderIdleListeners;
use App\Modules\Email\Jobs\DispatchEmailProviderReconciliation;
use App\Modules\Email\Jobs\EmailAccountHealthCheckJob;
use App\Modules\Email\Jobs\EmailRetentionPurgeJob;
use App\Modules\Email\Jobs\PollActiveEmailAccounts;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Jobs\RetryDueEmailRemoteOperations;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Integration\Jobs\CleanupAiAccessData;
use App\Modules\Integration\Jobs\CleanupAiChats;
use App\Modules\Integration\Jobs\CloudFactorySyncJob;
use App\Modules\Integration\Jobs\PullBookStackToKnowledge;
use App\Modules\Integration\Services\AiChatCleanup;
use App\Modules\Marketing\Jobs\SendDueMarketingCampaignEmails;
use App\Modules\Notification\Jobs\DispatchPendingInboundEmailExternalNotifications;
use App\Modules\Notification\Jobs\DispatchPendingInboundEmailNotificationFanouts;
use App\Modules\Storage\Actions\DispatchDueSupplierOrderImports;
use App\Modules\Storage\Actions\PurgeSupplierOrderImportTroubleshootingData;
use App\Modules\Storage\Actions\RunSupplierOrderImportOperationsMaintenance;
use App\Modules\Storage\Actions\SendSupplierOrderImportDailyDigest;
use App\Modules\Storage\Jobs\RecordSupplierOrderImportQueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command as ConsoleCommand;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Email polling every minute
Schedule::job(new PollActiveEmailAccounts)
    ->everyMinute()
    ->name('email.poll')
    ->withoutOverlapping();

// Provider mutations are retried only after row-locked evidence validation.
// The queued job also recovers stale running work as ambiguous and reconciles
// provider state before any possible replay.
Schedule::job(new RetryDueEmailRemoteOperations)
    ->everyMinute()
    ->name('email.remote_operations.retry_due')
    ->withoutOverlapping(5);

// Full all-folder provider reconciliation is the correctness fallback for
// missed/out-of-order IDLE hints and provider-originated changes.
Schedule::job(new DispatchEmailProviderReconciliation)
    ->everyMinute()
    ->name('email.provider_reconciliation.dispatch')
    ->withoutOverlapping(5);

// IDLE only lowers latency and needs its separately supervised queue worker.
// Default-off scheduling avoids accumulating listener jobs on installations
// that intentionally rely on the complete scheduled cycle alone.
Schedule::job(new DispatchEmailProviderIdleListeners)
    ->everyMinute()
    ->name('email.provider_reconciliation.idle_dispatch')
    ->withoutOverlapping(5)
    ->when(fn (): bool => (bool) config('email_provider_reconciliation.idle_enabled', false));

// Canonical inbound notifications and their external-delivery outbox are
// committed atomically. This bounded dispatcher recovers a worker loss after
// commit but before the immediate delivery job was queued.
Schedule::job(new DispatchPendingInboundEmailExternalNotifications)
    ->everyMinute()
    ->name('notification.inbound_email.external_dispatch')
    ->withoutOverlapping(5);

// Recipient discovery is its own durable cursor. This bounded wakeup closes
// the commit-before-dispatch crash window without rescanning all subscribers.
Schedule::job(new DispatchPendingInboundEmailNotificationFanouts)
    ->everyMinute()
    ->name('notification.inbound_email.fanout_dispatch')
    ->withoutOverlapping(5);

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

// These lifecycle jobs are internally gated by the explicit, default-off
// provider deletion reconciliation setting. A complete inventory only marks
// provider-confirmed loss; cleanup remains subject to grace and retention.
Schedule::job(new DispatchEmailProviderDeletionReconciliation)
    ->dailyAt('04:00')
    ->name('email.provider_deletion.reconcile')
    ->withoutOverlapping(120);

Schedule::job(new CleanupEmailProviderDeletionCache)
    ->dailyAt('05:00')
    ->name('email.provider_deletion.cleanup')
    ->withoutOverlapping(120);

// Scheduled Ticket activation and recurrence generation
Schedule::job(new \App\Modules\Ticket\Jobs\ProcessScheduledTickets)
    ->everyMinute()
    ->name('ticket.scheduled_process')
    ->withoutOverlapping(5);

// Supplier-order import dispatch owns a durable scheduler heartbeat and claims
// due rows before queueing, so overlapping scheduler invocations remain safe.
Schedule::call(fn () => app(DispatchDueSupplierOrderImports::class)->handle())
    ->everyMinute()
    ->name('storage.supplier_order_imports.dispatch_due')
    ->withoutOverlapping(5);

// A queued heartbeat distinguishes a running scheduler from a healthy worker.
Schedule::call(fn () => RecordSupplierOrderImportQueueHeartbeat::dispatch())
    ->everyMinute()
    ->name('storage.supplier_order_imports.queue_heartbeat')
    ->withoutOverlapping(5);

// Health maintenance recovers only stale locks and delivers deduplicated
// exception alerts. Ordinary successful imports remain silent.
Schedule::call(fn () => app(RunSupplierOrderImportOperationsMaintenance::class)->handle())
    ->everyFiveMinutes()
    ->name('storage.supplier_order_imports.health')
    ->withoutOverlapping(10);

Schedule::call(fn () => app(PurgeSupplierOrderImportTroubleshootingData::class)->handle())
    ->dailyAt('04:10')
    ->name('storage.supplier_order_imports.retention')
    ->withoutOverlapping(120);

Schedule::call(fn () => app(SendSupplierOrderImportDailyDigest::class)->handle())
    ->dailyAt('07:00')
    ->name('storage.supplier_order_imports.digest')
    ->withoutOverlapping(120);

/**
 * Cron-safe Supplier Order runtime entry point. This intentionally avoids
 * invoking the global scheduler and exposes only the five owned operations.
 */
Artisan::command(
    'storage:supplier-orders {operation : dispatch, heartbeat, health, retention, or digest}',
    function () {
        $operation = strtolower(trim((string) $this->argument('operation')));

        if ($operation === 'dispatch') {
            $queued = app(DispatchDueSupplierOrderImports::class)->handle();
            $this->info("Supplier-order dispatch completed: {$queued} import(s) queued.");

            return ConsoleCommand::SUCCESS;
        }

        if ($operation === 'heartbeat') {
            RecordSupplierOrderImportQueueHeartbeat::dispatch();
            $this->info('Supplier-order worker heartbeat queued.');

            return ConsoleCommand::SUCCESS;
        }

        if ($operation === 'health') {
            $result = app(RunSupplierOrderImportOperationsMaintenance::class)->handle();
            $this->info(sprintf(
                'Supplier-order health completed: %s; %d recovered; %d active alert(s).',
                (string) data_get($result, 'health.state', 'unknown'),
                (int) ($result['recovered_count'] ?? 0),
                (int) ($result['active_alert_count'] ?? 0),
            ));

            return ConsoleCommand::SUCCESS;
        }

        if ($operation === 'retention') {
            $result = app(PurgeSupplierOrderImportTroubleshootingData::class)->handle();
            $this->info(sprintf(
                'Supplier-order retention completed: %d day(s); immutable audit records unchanged.',
                (int) ($result['retention_days'] ?? 0),
            ));

            return ConsoleCommand::SUCCESS;
        }

        if ($operation === 'digest') {
            $alertId = app(SendSupplierOrderImportDailyDigest::class)->handle();
            $this->info($alertId === null
                ? 'Supplier-order digest completed: no digest due.'
                : 'Supplier-order digest completed: digest delivered.');

            return ConsoleCommand::SUCCESS;
        }

        $this->error(
            "Invalid supplier-order operation [{$operation}]. "
            .'Allowed operations: dispatch, heartbeat, health, retention, digest.',
        );

        return ConsoleCommand::INVALID;
    },
)->purpose('Run one isolated Supplier Order operational task without the global scheduler');

// N-able RMM Sync every hour
Schedule::job(new NAbleRmmSyncJob)
    ->hourly()
    ->name('integrations.nable_rmm.sync')
    ->withoutOverlapping();

// Cloud Factory checks its settings-controlled customer, subscription, and
// monthly catalogue intervals before starting a provider synchronization.
Schedule::job(new CloudFactorySyncJob)
    ->everyFiveMinutes()
    ->name('integrations.cloudfactory.sync')
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
Schedule::job(new PullBookStackToKnowledge)
    ->everyMinute()
    ->name('integrations.book_stack.pull')
    ->withoutOverlapping();

// AI chat retention cleanup. Settings determine whether the job performs work.
Schedule::job(new CleanupAiChats)
    ->weeklyOn(1, '03:30')
    ->name('ai.chats.cleanup')
    ->withoutOverlapping();

// AI/coordinator audit and optional encrypted payload retention cleanup.
Schedule::job(new CleanupAiAccessData)
    ->dailyAt('03:45')
    ->name('ai.access.cleanup')
    ->withoutOverlapping();

// Economy order generation catch-up. Manual Generate orders uses the same
// action, while this keeps picked costs and closed-ticket time from piling up.
Schedule::job(new GenerateEconomyOrdersJob)
    ->dailyAt('02:15')
    ->name('economy.orders.generate')
    ->withoutOverlapping();

// Lead Intelligence schedule planner. The command owns due-segment decisions
// and dispatches queued research-run jobs for eligible segments.
Schedule::command('lead-intelligence:plan-due-runs')
    ->everyMinute()
    ->name('lead_intelligence.plan_due_runs')
    ->withoutOverlapping();

// Data Exchange schedules queue due profile runs. Delivery attempts are
// recorded by the Data Exchange runtime after a generated file exists.
Schedule::command('data-exchange:run-due')
    ->everyMinute()
    ->name('data_exchange.run_due')
    ->withoutOverlapping();

// Marketing campaign automation. Campaign settings and recipient due_at control
// whether this run performs work.
Schedule::call(static function (): void {
    // Construct the queued job only when the scheduled event runs. Its
    // constructor freezes the provider binding before queue serialization,
    // while application/bootstrap discovery remains independent of schema.
    SendDueMarketingCampaignEmails::dispatch();
})
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
// Operations may add --scheduled for the all-account pause/interval-aware
// tick without installing the cross-application Laravel scheduler.
Artisan::command('email:poll {--account=} {--async} {--scheduled}', function () {
    $accountOption = $this->option('account');
    $accountId = null;

    if ((bool) $this->option('scheduled')) {
        if ($accountOption !== null) {
            $this->error('The --scheduled option always evaluates all active accounts.');

            return ConsoleCommand::INVALID;
        }

        app()->call([new PollActiveEmailAccounts, 'handle']);
        $this->info('Scheduled all-account poll tick evaluated.');

        return ConsoleCommand::SUCCESS;
    }

    if ($accountOption !== null) {
        $validatedAccountId = filter_var(
            $accountOption,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($validatedAccountId === false) {
            $this->error('The --account option must be a positive integer.');

            return ConsoleCommand::INVALID;
        }

        $accountId = (int) $validatedAccountId;
    }

    $async = (bool) $this->option('async');
    $batchSize = max(1, (int) (\App\Models\Settings\CommonSetting::query()
        ->where('type', 'emailhub')
        ->where('name', 'batch_size')
        ->value('value') ?? 20));
    $result = app(DispatchEmailAccountPolling::class)->handle(
        accountId: $accountId,
        batchSize: $batchSize,
        asynchronously: $async,
    );

    if ($result['matched'] === 0) {
        $this->info('No active accounts to poll.');

        return ConsoleCommand::SUCCESS;
    }

    $count = $result['started'];
    $this->info(($async ? 'Queued poll for ' : 'Checked now for ').$count.' account'.($count !== 1 ? 's' : '').'.');

    if ($result['failed'] > 0) {
        $this->error($result['failed'].' account'.($result['failed'] !== 1 ? 's' : '').' could not be started. Check Email account health.');

        return ConsoleCommand::FAILURE;
    }

    return ConsoleCommand::SUCCESS;
})->purpose('Fetch new mail for active accounts or run the pause-aware scheduled tick');

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

    $this->info(($async ? 'Queued rules for ' : 'Processed rules for ').$messageIds->count().' message'.($messageIds->count() > 1 ? 's' : '').'.');

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
