<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailProviderReconciliationCancellationTransition;
use App\Modules\Email\Support\EmailAccountProviderLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TransitionEmailProviderReconciliationCancellation implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 20;

    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [2, 5, 15];

    public function __construct(public int $runId)
    {
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return 'email-provider-reconciliation-cancellation-transition:'.$this->runId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        $accountId = (int) EmailProviderReconciliationRun::query()
            ->whereKey($this->runId)
            ->value('account_id');

        return [EmailAccountProviderLock::middleware($accountId, $this->timeout)];
    }

    public function handle(
        EmailProviderReconciliationCancellationTransition $transition,
    ): void {
        if ($transition->transition($this->runId)) {
            FinalizeEmailProviderReconciliation::dispatch($this->runId)->afterCommit();
        }
    }
}
