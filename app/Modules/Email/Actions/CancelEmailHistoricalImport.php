<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailHistoricalImportRun;
use App\Modules\Email\Services\EmailMailboxMaintenanceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelEmailHistoricalImport
{
    public function __construct(
        private readonly EmailMailboxMaintenanceAuthorization $authorization,
    ) {}

    public function handle(EmailAccount $account, EmailHistoricalImportRun $run, User $actor): EmailHistoricalImportRun
    {
        $this->authorization->authorize($actor, $account);

        return DB::transaction(function () use ($account, $run, $actor): EmailHistoricalImportRun {
            $locked = EmailHistoricalImportRun::query()->lockForUpdate()->findOrFail($run->id);
            $currentActor = User::query()->whereKey($actor->id)->first();
            $currentAccount = EmailAccount::query()->lockForUpdate()->find($account->id);

            if (! $currentActor || ! $currentAccount
                || (int) $locked->account_id !== (int) $currentAccount->id) {
                throw new AuthorizationException('Mailbox maintenance record not found.');
            }
            $this->authorization->authorize($currentActor, $currentAccount);

            if ($locked->status === EmailHistoricalImportRun::STATUS_CANCELLED) {
                return $locked;
            }

            if (! in_array($locked->status, [
                EmailHistoricalImportRun::STATUS_QUEUED,
                EmailHistoricalImportRun::STATUS_RUNNING,
                EmailHistoricalImportRun::STATUS_CANCELLING,
            ], true)) {
                throw ValidationException::withMessages([
                    'run' => 'This historical import is not active and cannot be cancelled.',
                ]);
            }

            $locked->forceFill([
                'status' => EmailHistoricalImportRun::STATUS_CANCELLING,
                'cancellation_requested_at' => $locked->cancellation_requested_at ?? now(),
                'cancelled_by' => $currentActor->id,
            ])->save();

            return $locked->refresh();
        });
    }
}
