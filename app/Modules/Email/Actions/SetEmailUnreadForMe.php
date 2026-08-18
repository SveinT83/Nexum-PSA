<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Services\EmailOrdinaryMailboxEntitlementResolver;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Email\Services\EmailUnreadSchemaState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SetEmailUnreadForMe
{
    public function __construct(
        private readonly EmailOrdinaryMailboxEntitlementResolver $entitlements,
        private readonly EmailUnreadAccessEpochService $epochs,
        private readonly EmailUnreadSchemaState $schemaState,
    ) {}

    public function handle(User $actor, EmailMessage $message, bool $isUnread): EmailMessageUserState
    {
        return DB::transaction(function () use ($actor, $isUnread, $message): EmailMessageUserState {
            $lockedAccount = EmailAccount::query()->lockForUpdate()->findOrFail($message->account_id);
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->id);

            if ($lockedActor->isSystemActor()
                || ! $this->entitlements->hasCurrentViewAccess($lockedAccount, $lockedActor)) {
                throw new AuthorizationException('Personal unread is unavailable for this mailbox access.');
            }

            $usesEpochs = $this->schemaState->usesAccessEpochs();

            if (! $usesEpochs && ! $this->schemaState->usesLegacyState()) {
                throw new AuthorizationException('Personal unread is temporarily unavailable.');
            }

            $baseline = $usesEpochs
                ? $this->epochs->ensureCurrentEntitlement($lockedAccount, $lockedActor, $lockedActor)
                : null;

            if ($usesEpochs && ! $baseline) {
                throw new AuthorizationException('Personal unread is unavailable for this mailbox access.');
            }

            $lockedMessage = EmailMessage::query()
                ->where('account_id', $lockedAccount->id)
                ->lockForUpdate()
                ->findOrFail($message->id);
            $state = EmailMessageUserState::query()
                ->where('email_message_id', $lockedMessage->id)
                ->where('user_id', $lockedActor->id)
                ->when(
                    $usesEpochs,
                    fn ($query) => $query->where('access_epoch', $baseline->access_epoch),
                )
                ->lockForUpdate()
                ->first();
            $now = now();

            $attributes = [
                'email_message_id' => $lockedMessage->id,
                'user_id' => $lockedActor->id,
                'opened_count' => 0,
            ];
            if ($usesEpochs) {
                $attributes['access_epoch'] = $baseline->access_epoch;
            }

            $state ??= new EmailMessageUserState($attributes);
            $state->is_unread = $isUnread;
            $state->marked_read_at = $isUnread ? $state->marked_read_at : $now;
            $state->marked_unread_at = $isUnread ? $now : $state->marked_unread_at;
            $state->save();

            return $state->fresh();
        });
    }
}
