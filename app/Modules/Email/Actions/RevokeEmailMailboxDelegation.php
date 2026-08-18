<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMailboxDelegation;
use App\Modules\Email\Services\EmailMailboxAccessEventRecorder;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevokeEmailMailboxDelegation
{
    public function __construct(
        private readonly EmailMailboxAccessEventRecorder $events,
        private readonly EmailUnreadAccessEpochService $unreadEpochs,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        EmailMailboxDelegation $delegation,
        User $actor,
        string $reason,
    ): EmailMailboxDelegation {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages([
                'reason' => 'Enter a revocation reason of no more than 2000 characters.',
            ]);
        }

        return DB::transaction(function () use ($delegation, $actor, $reason): EmailMailboxDelegation {
            $account = EmailAccount::query()->lockForUpdate()->find($delegation->email_account_id);
            $locked = EmailMailboxDelegation::query()->lockForUpdate()->find($delegation->id);
            $currentActor = User::query()->find($actor->id);

            if (! $account?->isPersonal()
                || ! $currentActor?->isActive()
                || $currentActor->isSystemActor()
                || (int) $account->owner_id !== (int) $currentActor->id) {
                throw new AuthorizationException('Only the active personal mailbox owner may revoke this delegation.');
            }

            if (! $locked || (int) $locked->email_account_id !== (int) $account->id) {
                throw new AuthorizationException('This mailbox delegation is not available.');
            }

            if ($locked->revoked_at !== null) {
                return $locked;
            }

            $delegate = User::query()->find($locked->delegate_id);
            $wasViewEntitled = $delegate
                ? $this->unreadEpochs->captureEntitlement($account, $delegate)
                : false;

            $locked->forceFill([
                'revoked_by' => $currentActor->id,
                'revocation_reason' => $reason,
                'revoked_at' => now(),
            ])->save();

            $this->events->recordDelegationRevoked($locked);

            if ($delegate) {
                $this->unreadEpochs->reconcileAfterMutation(
                    account: $account,
                    user: $delegate,
                    wasEntitled: $wasViewEntitled,
                    source: EmailUnreadAccessEpochService::SOURCE_DELEGATION,
                    sourceReference: 'delegation:'.$locked->id,
                    actor: $currentActor,
                );
            }

            return $locked->refresh();
        }, 3);
    }
}
