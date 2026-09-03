<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailConversationActionRun;
use App\Modules\Email\Services\EmailConversationAcknowledgementBoundary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CancelEmailConversationAcknowledgement
{
    public function __construct(
        private readonly EmailConversationAcknowledgementBoundary $boundary,
    ) {}

    public function handle(EmailConversationActionRun $run, User $actor): EmailConversationActionRun
    {
        $this->boundary->assertAvailable();

        return DB::transaction(function () use ($run, $actor): EmailConversationActionRun {
            $run = EmailConversationActionRun::query()->lockForUpdate()->findOrFail($run->id);
            if ((int) $run->requested_by !== (int) $actor->id
                || $run->operation !== EmailConversationActionRun::OPERATION_ACKNOWLEDGE) {
                throw new AuthorizationException('This mailbox action is not available.');
            }

            if (in_array($run->status, [
                EmailConversationActionRun::STATUS_APPLIED,
                EmailConversationActionRun::STATUS_STALE,
                EmailConversationActionRun::STATUS_FAILED,
                EmailConversationActionRun::STATUS_CANCELLED,
            ], true)) {
                return $run->fresh('items.remoteOperation');
            }

            $run->forceFill([
                'status' => EmailConversationActionRun::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'completed_at' => now(),
            ])->save();

            return $run->fresh('items.remoteOperation');
        }, 3);
    }
}
