<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Jobs\ProcessEmailCanonicalCorrelationRun;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailCanonicalCorrelationRun;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ResumeEmailCanonicalCorrelationRun
{
    public function __construct(private readonly ResolveMailboxAccessDecision $accessDecisions) {}

    public function handle(EmailCanonicalCorrelationRun $run, User $actor): EmailCanonicalCorrelationRun
    {
        $resumed = DB::transaction(function () use ($run, $actor): EmailCanonicalCorrelationRun {
            $actor = User::query()->find($actor->id);
            $locked = EmailCanonicalCorrelationRun::query()->lockForUpdate()->find($run->id);
            if (! $actor?->isActive()
                || $actor->isSystemActor()
                || ! $actor->can('email.mailbox_sync_manage')
                || ! $locked
                || (int) $locked->requested_by !== (int) $actor->id
                || $locked->status !== EmailCanonicalCorrelationRun::STATUS_FAILED) {
                throw new AuthorizationException('This correlation run cannot be resumed.');
            }

            foreach ($locked->account_scope_json as $accountId) {
                $account = EmailAccount::query()->find((int) $accountId);
                if (! $account?->is_active
                    || ! $this->accessDecisions->resolve($actor, $account, MailboxAccess::VIEW)->allowed) {
                    throw new AuthorizationException('This correlation run cannot be resumed.');
                }
            }

            $locked->forceFill([
                'status' => EmailCanonicalCorrelationRun::STATUS_QUEUED,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => null,
            ])->save();

            return $locked;
        }, 3);

        ProcessEmailCanonicalCorrelationRun::dispatch((int) $resumed->id)
            ->onQueue('email')
            ->afterCommit();

        return $resumed;
    }
}
