<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Ordinary personal awareness and optional personal-owner provider mirroring remain separate outcomes. */
final class SetEmailSelectedReadState
{
    public function handle(User $actor, EmailMailboxPlacement $placement, bool $read): array
    {
        $placement->loadMissing(['account', 'message']);
        if (! $placement->account || ! $placement->message?->hasActiveProviderPlacement($placement)
            || ! app(MailboxAccess::class)->canAccessAccount($actor, $placement->account, MailboxAccess::VIEW)) {
            throw new AuthorizationException('You cannot change this message read state.');
        }
        app(SetEmailUnreadForMe::class)->handle($actor, $placement->message, ! $read);
        $result = ['is_unread' => ! $read, 'local_status' => 'succeeded', 'provider_status' => 'not_requested',
            'provider_operation_id' => null];
        if ($placement->account->isPersonal() && (int) $placement->account->owner_id === (int) $actor->id) {
            try {
                $operation = app(PerformEmailRemoteOperation::class)->handle($placement,
                    $read ? PerformEmailRemoteOperation::MARK_SEEN : PerformEmailRemoteOperation::MARK_UNSEEN, $actor);
                $result['provider_operation_id'] = (int) $operation->id;
                $result['provider_status'] = $operation->status;
            } catch (AuthorizationException|ValidationException|RuntimeException) {
                $result['provider_status'] = 'failed';
            }
        }

        return $result;
    }
}
