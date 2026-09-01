<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\EmailTestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tests one saved mailbox configuration in the bounded CLI Email worker.
 *
 * Only opaque database identifiers and the requested activation state are
 * serialized. Credentials are resolved from encrypted account storage at run
 * time and a stale job can never activate a newer configuration.
 */
final class TestEmailAccountConnectionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 180;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $accountId,
        public readonly int $bindingVersion,
        public readonly bool $activateWhenVerified,
    ) {
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return $this->accountId.':'.$this->bindingVersion;
    }

    public function handle(EmailTestService $tester): void
    {
        $account = EmailAccount::query()->findOrFail($this->accountId);

        if ((int) $account->provider_binding_version !== $this->bindingVersion) {
            return;
        }

        $result = $tester->runConfiguration($account, $this->bindingVersion);

        DB::transaction(function () use ($result): void {
            $account = EmailAccount::query()->lockForUpdate()->find($this->accountId);

            if (! $account || (int) $account->provider_binding_version !== $this->bindingVersion) {
                return;
            }

            $account->is_active = $this->activateWhenVerified && $result->overall() === 'OK';
            $account->save();
        }, 3);
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $account = EmailAccount::query()->lockForUpdate()->find($this->accountId);

            if (! $account || (int) $account->provider_binding_version !== $this->bindingVersion) {
                return;
            }

            $account->forceFill([
                'is_active' => false,
                'last_test_at' => now(),
                'last_test_result' => 'Error',
                'last_error_code' => 'CONNECTION_CHECK_FAILED',
                'last_error_message' => 'The connection check could not finish safely. Save and test the account again.',
            ])->save();
        }, 3);
    }
}
