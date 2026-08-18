<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelEmailRemoteOperation
{
    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
    ) {}

    public function handle(EmailRemoteOperation $operation, User $actor): EmailRemoteOperation
    {
        return DB::transaction(function () use ($operation, $actor): EmailRemoteOperation {
            /** @var EmailRemoteOperation $locked */
            $locked = EmailRemoteOperation::query()
                ->with('account')
                ->lockForUpdate()
                ->findOrFail($operation->id);

            if (! $locked->account
                || ! $this->mailboxAccess->canAccessAccount($actor, $locked->account, MailboxAccess::ORGANIZE)) {
                throw new AuthorizationException('You cannot cancel operations for this mailbox.');
            }

            if ($locked->status === EmailRemoteOperation::STATUS_CANCELLED) {
                return $locked;
            }

            if ($locked->status === EmailRemoteOperation::STATUS_RUNNING) {
                throw ValidationException::withMessages([
                    'operation' => 'This provider attempt is already running and cannot be cancelled mid-flight.',
                ]);
            }

            if (! in_array($locked->status, [
                EmailRemoteOperation::STATUS_PENDING,
                EmailRemoteOperation::STATUS_FAILED,
            ], true)) {
                throw ValidationException::withMessages([
                    'operation' => 'This mailbox operation is already terminal and cannot be cancelled.',
                ]);
            }

            $locked->forceFill([
                'status' => EmailRemoteOperation::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'next_attempt_at' => null,
                'status_reason_code' => 'REMOTE_OPERATION_CANCELLED',
                'status_reason_message' => 'An authorized technician cancelled the operation before another provider attempt.',
            ])->save();

            return $locked->refresh();
        });
    }
}
