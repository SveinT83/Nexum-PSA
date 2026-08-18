<?php

namespace App\Console\Commands;

use App\Modules\Email\Jobs\DispatchEmailProviderReconciliation;
use App\Modules\Email\Models\EmailAccount;
use Illuminate\Console\Command;
use Throwable;

class ReconcileEmailProviderCommand extends Command
{
    protected $signature = 'email:reconcile-provider
        {--account= : Reconcile one Email account ID instead of all due accounts}
        {--async : Queue the dispatcher instead of evaluating due accounts now}';

    protected $description = 'Queue bounded read-only provider reconciliation for due accounts or force one account catch-up.';

    public function handle(): int
    {
        $accountId = null;
        if ($this->option('account') !== null) {
            $accountId = filter_var($this->option('account'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($accountId === false || ! EmailAccount::query()->whereKey($accountId)->exists()) {
                $this->error('--account must identify an existing Email account.');

                return self::INVALID;
            }
            $accountId = (int) $accountId;
        }

        try {
            $dispatcher = new DispatchEmailProviderReconciliation($accountId);
            if ($this->option('async')) {
                DispatchEmailProviderReconciliation::dispatch($accountId);
            } else {
                app()->call([$dispatcher, 'handle']);
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Provider reconciliation dispatch did not complete.');

            return self::FAILURE;
        }

        $this->info($this->option('async')
            ? 'Provider reconciliation dispatcher queued.'
            : ($accountId
                ? 'Provider reconciliation catch-up account evaluated.'
                : 'Due provider reconciliation accounts evaluated.'));

        return self::SUCCESS;
    }
}
