<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Services\EmailOrdinaryMailboxEntitlementResolver;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Email\Services\EmailUnreadSchemaState;
use App\Modules\Email\Services\EmailLiveInvalidator;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RecordEmailMessageOpened
{
    public function __construct(
        private readonly EmailOrdinaryMailboxEntitlementResolver $entitlements,
        private readonly EmailUnreadAccessEpochService $epochs,
        private readonly EmailUnreadSchemaState $schemaState,
        private readonly EmailLiveInvalidator $invalidator,
    ) {}

    public function handle(
        User $actor,
        EmailMessage $message,
        ?EmailMailboxPlacement $placement = null,
    ): EmailMessageUserState {
        return DB::transaction(function () use ($actor, $message, $placement): EmailMessageUserState {
            $lockedAccount = EmailAccount::query()
                ->lockForUpdate()
                ->findOrFail($message->account_id);
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->id);

            if ($lockedActor->isSystemActor()
                || ! $this->entitlements->hasCurrentViewAccess($lockedAccount, $lockedActor)
                || ($placement && (
                    (int) $placement->email_message_id !== (int) $message->id
                    || (int) $placement->account_id !== (int) $message->account_id
                ))) {
                throw new AuthorizationException('The message cannot be opened through this mailbox access.');
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

            if (! $state) {
                $attributes = [
                    'email_message_id' => $lockedMessage->id,
                    'user_id' => $lockedActor->id,
                    'is_unread' => $usesEpochs
                        ? (int) $lockedMessage->id > (int) $baseline->baseline_message_id
                        : true,
                    'opened_count' => 0,
                ];
                if ($usesEpochs) {
                    $attributes['access_epoch'] = $baseline->access_epoch;
                }

                $state = new EmailMessageUserState($attributes);
            }

            $state->first_opened_at ??= $now;
            $state->last_opened_at = $now;
            $state->last_opened_placement_id = $placement?->id;
            $state->opened_count = ((int) $state->opened_count) + 1;
            $state->save();

            $this->invalidator->record([
                'user' => [
                    $lockedActor->id => [EmailLiveProjectionChange::TYPE_PERSONAL_STATE],
                ],
                'conversations' => [$lockedMessage->conversation_id],
            ]);

            return $state->fresh();
        });
    }
}
