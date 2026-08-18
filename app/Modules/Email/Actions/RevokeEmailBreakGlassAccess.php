<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Services\EmailMailboxAccessEventRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevokeEmailBreakGlassAccess
{
    public function __construct(private readonly EmailMailboxAccessEventRecorder $events) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        EmailBreakGlassAccess $access,
        User $actor,
        string $reason,
    ): EmailBreakGlassAccess {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages([
                'reason' => 'Enter a revocation reason of no more than 2000 characters.',
            ]);
        }

        return DB::transaction(function () use ($access, $actor, $reason): EmailBreakGlassAccess {
            $account = EmailAccount::query()->lockForUpdate()->find($access->email_account_id);
            $locked = EmailBreakGlassAccess::query()->lockForUpdate()->find($access->id);
            $currentActor = User::query()->find($actor->id);

            if (! $account?->isPersonal()
                || ! $currentActor?->isActive()
                || $currentActor->isSystemActor()
                || ! $locked
                || (int) $locked->email_account_id !== (int) $account->id) {
                throw new AuthorizationException('This emergency mailbox access is not available.');
            }

            $revocationSource = match (true) {
                (int) $locked->actor_id === (int) $currentActor->id => 'actor',
                (int) $account->owner_id === (int) $currentActor->id => 'owner',
                $currentActor->can('email.break_glass_activate') => 'operator',
                default => null,
            };

            if ($revocationSource === null) {
                throw new AuthorizationException('You may not revoke this emergency mailbox access.');
            }

            if ($locked->revoked_at !== null) {
                return $locked;
            }

            $locked->forceFill([
                'revoked_by' => $currentActor->id,
                'revocation_reason' => $reason,
                'revoked_at' => now(),
            ])->save();

            $locked->setRelation('account', $account);
            $this->events->recordBreakGlassRevoked($locked, $revocationSource);

            return $locked->refresh();
        }, 3);
    }
}
