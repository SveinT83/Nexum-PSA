<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetryEmailRemoteOperation
{
    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly RunEmailRemoteOperation $runRemoteOperation,
    ) {}

    public function handle(EmailRemoteOperation $operation, User $actor): EmailRemoteOperation
    {
        $eligible = DB::transaction(function () use ($operation, $actor): EmailRemoteOperation {
            /** @var EmailRemoteOperation $locked */
            $locked = EmailRemoteOperation::query()
                ->with('account')
                ->lockForUpdate()
                ->findOrFail($operation->id);

            if (! $locked->account
                || ! $this->mailboxAccess->canAccessAccount($actor, $locked->account, MailboxAccess::ORGANIZE)) {
                throw new AuthorizationException('You cannot retry operations for this mailbox.');
            }

            if (! $locked->canBeRetried()) {
                throw ValidationException::withMessages([
                    'operation' => 'This mailbox operation is terminal, permanent, or has reached its retry limit.',
                ]);
            }

            $locked->forceFill([
                'next_attempt_at' => null,
                'status_reason_code' => 'REMOTE_OPERATION_MANUAL_RETRY',
                'status_reason_message' => 'An authorized technician requested a safe retry.',
            ])->save();

            return $locked;
        });

        return $this->runRemoteOperation->handle($eligible, 'manual', $actor);
    }
}
