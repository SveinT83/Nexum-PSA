<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailCanonicalCorrelationRun;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CancelEmailCanonicalCorrelationRun
{
    public function __construct(private readonly ResolveMailboxAccessDecision $accessDecisions) {}

    public function handle(EmailCanonicalCorrelationRun $run, User $actor): EmailCanonicalCorrelationRun
    {
        return DB::transaction(function () use ($run, $actor): EmailCanonicalCorrelationRun {
            $actor = User::query()->find($actor->id);
            $locked = EmailCanonicalCorrelationRun::query()->lockForUpdate()->find($run->id);

            if (! $actor?->isActive()
                || $actor->isSystemActor()
                || ! $actor->can('email.mailbox_sync_manage')
                || ! $locked) {
                throw new AuthorizationException('This correlation run is not available.');
            }

            foreach ($locked->account_scope_json as $accountId) {
                $account = EmailAccount::query()->find((int) $accountId);
                if (! $account?->is_active
                    || ! $this->accessDecisions->resolve($actor, $account, MailboxAccess::VIEW)->allowed) {
                    throw new AuthorizationException('This correlation run is not available.');
                }
            }

            if (in_array($locked->status, [
                EmailCanonicalCorrelationRun::STATUS_COMPLETED,
                EmailCanonicalCorrelationRun::STATUS_CANCELLED,
            ], true)) {
                return $locked;
            }

            $locked->forceFill([
                'status' => EmailCanonicalCorrelationRun::STATUS_CANCELLED,
                'cancelled_by' => $actor->id,
                'finished_at' => now(),
                'error_code' => 'cancelled_by_operator',
                'error_message' => 'The bounded shadow run was cancelled by an authorized operator.',
            ])->save();

            return $locked;
        }, 3);
    }
}
