<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Jobs\TransitionEmailProviderReconciliationCancellation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelEmailProviderReconciliation
{
    public function handle(
        EmailAccount $account,
        EmailProviderReconciliationRun $run,
        ?User $actor = null,
    ): EmailProviderReconciliationRun {
        if ((int) $run->account_id !== (int) $account->id) {
            throw new AuthorizationException('Mailbox reconciliation record not found.');
        }

        $cancelled = DB::transaction(function () use ($account, $run, $actor): EmailProviderReconciliationRun {
            EmailAccount::query()->lockForUpdate()->findOrFail($account->id);
            $locked = EmailProviderReconciliationRun::query()->lockForUpdate()->findOrFail($run->id);
            if ((int) $locked->account_id !== (int) $account->id) {
                throw new AuthorizationException('Mailbox reconciliation record not found.');
            }
            if ($locked->status === EmailProviderReconciliationRun::STATUS_CANCELLING
                || $locked->status === EmailProviderReconciliationRun::STATUS_CANCELLED
                || $locked->cancellation_requested_at !== null) {
                return $locked;
            }
            if (! $locked->cancellable()) {
                throw ValidationException::withMessages([
                    'run' => 'This provider reconciliation is not active and cannot be cancelled.',
                ]);
            }

            $locked->forceFill([
                'cancelled_by' => $actor?->id,
                'cancellation_requested_at' => $locked->cancellation_requested_at ?? now(),
                'last_progress_at' => now(),
            ])->save();

            return $locked->refresh();
        }, 3);

        // The provider-lease job linearizes CANCELLING only after any in-flight
        // provider/Store work has drained. Repeated intent is idempotent.
        if (! $cancelled->terminal() && $cancelled->cancellation_requested_at !== null) {
            TransitionEmailProviderReconciliationCancellation::dispatch(
                (int) $cancelled->id,
            )->afterCommit();
        }

        return $cancelled;
    }
}
