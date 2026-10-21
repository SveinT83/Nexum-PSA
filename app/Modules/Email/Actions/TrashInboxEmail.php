<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Services\MailboxAccess;

/** Ordinary Delete is a reversible provider Trash action, never local deletion or IMAP expunge. */
final class TrashInboxEmail
{
    public function handle(User $actor, EmailMessage $message): EmailRemoteOperation
    {
        abort_unless($message->ticket_id === null
            && app(MailboxAccess::class)->canOrganizeMessage($actor, $message), 404);
        $placement = EmailMailboxPlacement::query()->where('email_message_id', $message->id)
            ->where('account_id', $message->account_id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('provider_missing_at')->orderByDesc('id')->firstOrFail();

        return app(PerformEmailRemoteOperation::class)->handle($placement, PerformEmailRemoteOperation::TRASH, $actor);
    }
}
