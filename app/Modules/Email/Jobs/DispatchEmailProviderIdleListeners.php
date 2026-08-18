<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\EmailProviderReconciliationBindingPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DispatchEmailProviderIdleListeners implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ACCOUNT_PAGE_SIZE = 50;

    public int $timeout = 120;

    public int $tries = 5;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30];

    public int $uniqueFor = 55;

    /** Last account ID durably serialized into this bounded page job. */
    public int $afterAccountId = 0;

    public function __construct(?int $afterAccountId = null)
    {
        $this->afterAccountId = max(0, $afterAccountId ?? 0);
        $this->onQueue('email-idle');
    }

    public function uniqueId(): string
    {
        return 'email-provider-idle-dispatch:after:'.$this->afterAccountId;
    }

    public function handle(EmailProviderReconciliationBindingPolicy $bindings): void
    {
        $accounts = EmailAccount::query()
            ->where('is_active', true)
            ->when(
                $this->afterAccountId > 0,
                fn ($query) => $query->where('id', '>', $this->afterAccountId),
            )
            ->select('id')
            ->orderBy('id')
            ->limit(self::ACCOUNT_PAGE_SIZE)
            ->get();

        foreach ($accounts as $reference) {
            try {
                $account = EmailAccount::query()->find((int) $reference->id);
                if ($account && ! $account->provider_runtime_paused_at
                    && $bindings->databaseEligible($account)) {
                    ListenForEmailProviderChanges::dispatch(
                        (int) $account->id,
                        $bindings->capture($account),
                    );
                }
            } catch (Throwable) {
                // One unavailable credential or queue push must not suppress
                // later account listeners; scheduled reconciliation remains
                // the correctness fallback.
            }
        }

        if ($accounts->count() !== self::ACCOUNT_PAGE_SIZE) {
            return;
        }

        $lastAccountId = (int) $accounts->last()->id;
        if ($lastAccountId <= $this->afterAccountId) {
            return;
        }

        // The cursor is part of the queue payload. A hard loss retries only
        // this page; redelivery may duplicate harmless IDLE hints, while each
        // account listener's unique lock prevents unsafe concurrent sockets.
        self::dispatch($lastAccountId)->afterCommit();
    }
}
