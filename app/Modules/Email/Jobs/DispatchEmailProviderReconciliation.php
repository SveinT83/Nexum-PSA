<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Actions\StartEmailProviderReconciliation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailProviderReconciliationReadException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchEmailProviderReconciliation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ACCOUNT_PAGE_SIZE = 50;

    public int $timeout = 120;

    public int $tries = 5;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30];

    public int $uniqueFor = 55;

    /** Last account ID durably serialized into an all-account page job. */
    public int $afterAccountId = 0;

    public function __construct(
        public ?int $accountId = null,
        ?int $afterAccountId = null,
    ) {
        $this->afterAccountId = max(0, $afterAccountId ?? 0);
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return $this->accountId !== null
            ? 'email-provider-reconciliation-dispatch:account:'.$this->accountId
            : 'email-provider-reconciliation-dispatch:all:after:'.$this->afterAccountId;
    }

    public function handle(StartEmailProviderReconciliation $start): void
    {
        $accounts = EmailAccount::query()
            ->where('is_active', true)
            ->when($this->accountId !== null, fn ($query) => $query->whereKey($this->accountId))
            ->when(
                $this->accountId === null && $this->afterAccountId > 0,
                fn ($query) => $query->where('id', '>', $this->afterAccountId),
            )
            ->select('id')
            ->orderBy('id')
            ->limit($this->accountId === null ? self::ACCOUNT_PAGE_SIZE : 1)
            ->get();

        foreach ($accounts as $accountReference) {
            try {
                $account = EmailAccount::query()->find((int) $accountReference->id);
                if (! $account || ($this->accountId === null && $this->notDue($account))) {
                    continue;
                }

                $start->handle(
                    $account,
                    $this->accountId === null
                        ? EmailProviderReconciliationRun::TRIGGER_SCHEDULED
                        : EmailProviderReconciliationRun::TRIGGER_CATCHUP,
                );
            } catch (Throwable $exception) {
                Log::warning('Scheduled provider reconciliation account dispatch failed.', [
                    'account_id' => (int) $accountReference->id,
                    'code' => $exception instanceof EmailProviderReconciliationReadException
                        ? $exception->safeCode
                        : 'provider_reconciliation_dispatch_failed',
                ]);
            }
        }

        if ($this->accountId !== null || $accounts->isEmpty()) {
            return;
        }

        $lastAccountId = (int) $accounts->last()->id;
        $hasAnotherPage = EmailAccount::query()
            ->where('is_active', true)
            ->where('id', '>', $lastAccountId)
            ->exists();
        if ($hasAnotherPage) {
            // The cursor is part of the queue payload. A hard loss before this
            // dispatch retries the same bounded page; a redelivery after it is
            // harmless because StartEmailProviderReconciliation is idempotent.
            self::dispatch(null, $lastAccountId)->afterCommit();
        }
    }

    private function notDue(EmailAccount $account): bool
    {
        if (EmailProviderReconciliationRun::query()
            ->where('account_id', $account->id)
            ->where('active_slot', 1)
            ->exists()) {
            return false;
        }

        $latest = EmailProviderReconciliationRun::query()
            ->where('account_id', $account->id)
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->first();

        return $latest
            && $latest->finished_at->gt(now()->subSeconds((int) $latest->normal_interval_seconds));
    }
}
